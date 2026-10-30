<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

use App\Audit\Auditor;
use App\Domain\Approvals\ApprovalPublisher;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Enums\RouteStatus;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Approvals\Models\ApprovalRoute;
use App\Domain\Approvals\RouteBuilder;
use App\Domain\Evidence\Enums\EvidenceChannel;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Intake\Enums\CancelReason;
use App\Domain\Intake\Enums\DecisionReason;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Exceptions\InvalidTransition;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\PipelineService;
use App\Domain\Intake\StateMachine;
use App\Domain\Money\Money;
use App\Domain\Notifications\DomainNotifier;
use App\Domain\Reports\ExportService;
use App\Domain\Vendors\BlocklistService;
use App\Domain\Vendors\Models\Vendor;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\WorkspaceScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

final readonly class DecisionService
{
    public function __construct(
        private PipelineService $pipeline,
        private RouteBuilder $routes,
        private ApprovalPublisher $publisher,
        private BlocklistService $blocklist,
        private ExportService $exports,
        private DomainNotifier $notifier,
        private EvidenceLedger $ledger,
        private Auditor $auditor,
        private ClockInterface $clock,
    ) {}

    public function rejectFromReview(PaymentRequest $subject, User $actor, DecisionReason $reason, ?string $comment): PaymentRequest
    {
        self::assertComment($reason, $comment);

        return DB::transaction(function () use ($subject, $actor, $reason, $comment): PaymentRequest {
            $request = $this->pipeline->lock((int) $subject->getAttribute('workspace_id'), (int) $subject->getKey());
            $this->expectStatus($request, [PaymentRequestStatus::Review]);
            $this->reject($request, $reason, $comment, $actor, EvidenceChannel::Web);

            return $request;
        });
    }

    public function reject(PaymentRequest $request, DecisionReason $reason, ?string $comment, ?User $actor, EvidenceChannel $channel, bool $verificationDenied = false): void
    {
        /** @var PaymentRequestStatus $status */
        $status = $request->getAttribute('status');
        StateMachine::assert($status, PaymentRequestStatus::Rejected);
        $workspace = Workspace::query()->findOrFail((int) $request->getAttribute('workspace_id'));
        $now = CarbonImmutable::instance($this->clock->now());
        $category = PreventionLedger::apply($request, $reason, $verificationDenied);
        $request->forceFill([
            'status' => PaymentRequestStatus::Rejected,
            'decided_at' => $now,
            'decided_by' => $actor?->id,
            'decision_reason' => $reason->value,
            'decision_note' => $comment !== null && trim($comment) !== '' ? mb_substr(trim($comment), 0, 2000, 'UTF-8') : null,
            'expires_at' => null,
        ])->save();

        $this->closeRoute($request, $workspace, RouteStatus::Rejected, 'rejected');
        $this->blocklist->blockFromDecision($request, $reason, $actor, $channel);

        $this->ledger->append(
            workspaceId: $workspace->id,
            event: 'request.rejected',
            subjectType: 'payment_request',
            subjectId: (int) $request->getKey(),
            payload: ['reason' => $reason->value, 'prevented_category' => $category?->value],
            actorUserId: $actor?->id,
            channel: $channel,
            paymentRequestId: (int) $request->getKey(),
        );
        $this->auditor->record('payment_request.rejected', $request, ['status' => [$status->value, 'rejected']], ['reason' => $reason->value], actor: $actor, workspace: $workspace, subjectLabel: (string) $request->getAttribute('reference'));
        $this->notifyRejected($request, $workspace, $reason, $actor);
        $this->exports->requestPacket($request, null);
    }

    public function cancel(PaymentRequest $subject, User $actor, CancelReason $reason, ?string $comment, bool $mayCancelAny): PaymentRequest
    {
        if ($reason === CancelReason::Other && ($comment === null || trim($comment) === '')) {
            throw new WorkflowException('comment_required', 'comment');
        }

        return DB::transaction(function () use ($subject, $actor, $reason, $comment, $mayCancelAny): PaymentRequest {
            $request = $this->pipeline->lock((int) $subject->getAttribute('workspace_id'), (int) $subject->getKey());
            $this->expectStatus($request, [PaymentRequestStatus::Review, PaymentRequestStatus::AwaitingVerification, PaymentRequestStatus::AwaitingApproval, PaymentRequestStatus::Expired]);
            $workspace = Workspace::query()->findOrFail((int) $request->getAttribute('workspace_id'));

            if (! $mayCancelAny) {
                $decided = Approval::query()->forWorkspace($workspace)->where('payment_request_id', $request->getKey())
                    ->whereIn('status', [ApprovalStatus::Approved->value, ApprovalStatus::Rejected->value])->exists();

                if ((int) $request->getAttribute('requested_by') !== $actor->id || $decided) {
                    throw new WorkflowException('cannot_cancel', 'reason');
                }
            }

            /** @var PaymentRequestStatus $status */
            $status = $request->getAttribute('status');
            $request->forceFill([
                'status' => PaymentRequestStatus::Cancelled,
                'decided_at' => CarbonImmutable::instance($this->clock->now()),
                'decided_by' => $actor->id,
                'decision_reason' => $reason->value,
                'decision_note' => $comment !== null && trim($comment) !== '' ? mb_substr(trim($comment), 0, 2000, 'UTF-8') : null,
                'expires_at' => null,
            ])->save();
            $this->closeRoute($request, $workspace, RouteStatus::Cancelled, 'cancelled');

            $this->ledger->append(
                workspaceId: $workspace->id,
                event: 'request.cancelled',
                subjectType: 'payment_request',
                subjectId: (int) $request->getKey(),
                payload: ['reason' => $reason->value],
                actorUserId: $actor->id,
                paymentRequestId: (int) $request->getKey(),
            );
            $this->auditor->record('payment_request.cancelled', $request, ['status' => [$status->value, 'cancelled']], ['reason' => $reason->value], actor: $actor, workspace: $workspace, subjectLabel: (string) $request->getAttribute('reference'));

            return $request;
        });
    }

    public function revoke(PaymentRequest $subject, User $actor, DecisionReason $reason, string $comment): PaymentRequest
    {
        if (trim($comment) === '') {
            throw new WorkflowException('comment_required', 'comment');
        }

        return DB::transaction(function () use ($subject, $actor, $reason, $comment): PaymentRequest {
            $request = $this->pipeline->lock((int) $subject->getAttribute('workspace_id'), (int) $subject->getKey());
            $this->expectStatus($request, [PaymentRequestStatus::Approved]);
            $workspace = Workspace::query()->findOrFail((int) $request->getAttribute('workspace_id'));
            $category = PreventionLedger::apply($request, $reason);
            $request->forceFill([
                'status' => PaymentRequestStatus::Rejected,
                'decided_at' => CarbonImmutable::instance($this->clock->now()),
                'decided_by' => $actor->id,
                'decision_reason' => $reason->value,
                'decision_note' => mb_substr(trim($comment), 0, 2000, 'UTF-8'),
            ])->save();

            $this->ledger->append(
                workspaceId: $workspace->id,
                event: 'request.revoked',
                subjectType: 'payment_request',
                subjectId: (int) $request->getKey(),
                payload: ['reason' => $reason->value, 'prevented_category' => $category?->value],
                actorUserId: $actor->id,
                paymentRequestId: (int) $request->getKey(),
            );
            $this->auditor->record('payment_request.revoked', $request, ['status' => ['approved', 'rejected']], ['reason' => $reason->value], actor: $actor, workspace: $workspace, subjectLabel: (string) $request->getAttribute('reference'));
            $this->notifyRejected($request, $workspace, $reason, $actor);
            $this->exports->requestPacket($request, null);

            return $request;
        });
    }

    public function markPaid(PaymentRequest $subject, User $actor, CarbonImmutable $paidAt, string $paymentReference): PaymentRequest
    {
        return DB::transaction(function () use ($subject, $actor, $paidAt, $paymentReference): PaymentRequest {
            $request = $this->pipeline->lock((int) $subject->getAttribute('workspace_id'), (int) $subject->getKey());
            $this->expectStatus($request, [PaymentRequestStatus::Approved]);
            $now = CarbonImmutable::instance($this->clock->now());
            $decided = $request->getAttribute('decided_at');
            $earliest = $decided instanceof \DateTimeInterface ? CarbonImmutable::instance($decided)->subDay() : null;

            if ($paidAt->greaterThan($now) || ($earliest !== null && $paidAt->lessThan($earliest))) {
                throw new WorkflowException('paid_at_out_of_range', 'paid_at');
            }

            $reference = mb_substr(trim($paymentReference), 0, 120, 'UTF-8');
            $workspace = Workspace::query()->findOrFail((int) $request->getAttribute('workspace_id'));
            $request->forceFill([
                'status' => PaymentRequestStatus::Paid,
                'paid_at' => $paidAt->utc(),
                'paid_by' => $actor->id,
                'payment_reference' => $reference,
            ])->save();

            $vendorId = $request->getAttribute('vendor_id');

            if (is_int($vendorId)) {
                $vendor = Vendor::query()->forWorkspace($workspace)->withTrashed()->whereKey($vendorId)->lockForUpdate()->first();

                if ($vendor !== null) {
                    $first = $vendor->getAttribute('first_paid_at');
                    $last = $vendor->getAttribute('last_paid_at');
                    $vendor->forceFill([
                        'first_paid_at' => $first instanceof \DateTimeInterface && CarbonImmutable::instance($first)->lessThan($paidAt) ? $first : $paidAt->utc(),
                        'last_paid_at' => $last instanceof \DateTimeInterface && CarbonImmutable::instance($last)->greaterThan($paidAt) ? $last : $paidAt->utc(),
                    ])->save();
                }
            }

            $this->ledger->append(
                workspaceId: $workspace->id,
                event: 'request.paid',
                subjectType: 'payment_request',
                subjectId: (int) $request->getKey(),
                payload: ['payment_reference_last4' => mb_substr($reference, -4, null, 'UTF-8')],
                actorUserId: $actor->id,
                paymentRequestId: (int) $request->getKey(),
            );
            $this->auditor->record('payment_request.paid', $request, ['status' => ['approved', 'paid']], actor: $actor, workspace: $workspace, subjectLabel: (string) $request->getAttribute('reference'));
            $this->exports->requestPacket($request, null);

            return $request;
        });
    }

    public function expireRoute(ApprovalRoute $subject, bool $force = false): bool
    {
        return DB::transaction(function () use ($subject, $force): bool {
            $workspace = Workspace::query()->findOrFail((int) $subject->getAttribute('workspace_id'));
            $request = $this->pipeline->lock($workspace->id, (int) $subject->getAttribute('payment_request_id'));
            $route = ApprovalRoute::query()->forWorkspace($workspace)->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::instance($this->clock->now());
            $expires = CarbonImmutable::instance($route->getAttribute('expires_at'));

            if ($route->getAttribute('status') !== RouteStatus::Active
                || $request->getAttribute('status') !== PaymentRequestStatus::AwaitingApproval
                || (int) $request->getAttribute('current_route_id') !== (int) $route->getKey()
                || (! $force && $now->lessThan($expires))) {
                return false;
            }

            $route->forceFill(['status' => RouteStatus::Expired, 'closed_at' => $now])->save();
            $approverIds = [];

            foreach (Approval::query()->forWorkspace($workspace)->where('approval_route_id', $route->getKey())->where('status', ApprovalStatus::Pending->value)->lockForUpdate()->get() as $approval) {
                $approverIds[] = (int) $approval->getAttribute('approver_id');
                $approval->forceFill(['status' => ApprovalStatus::Expired, 'decided_at' => $now, 'telegram_nonce_hash' => null])->save();
                $this->publisher->stripButtons($approval, 'expired');
            }

            $request->forceFill(['status' => PaymentRequestStatus::Expired, 'decided_at' => $now, 'decided_by' => null])->save();
            $this->ledger->append(
                workspaceId: $workspace->id,
                event: 'request.expired',
                subjectType: 'payment_request',
                subjectId: (int) $request->getKey(),
                payload: [],
                channel: EvidenceChannel::System,
                paymentRequestId: (int) $request->getKey(),
            );
            $this->auditor->record('payment_request.expired', $request, ['status' => ['awaiting_approval', 'expired']], actor: null, workspace: $workspace, subjectLabel: (string) $request->getAttribute('reference'));
            $recipients = [
                ...DomainNotifier::users([(int) $request->getAttribute('requested_by'), is_int($s = $request->getAttribute('submitted_by')) ? $s : null, ...$approverIds]),
                ...DomainNotifier::membersWithRoles($workspace, [WorkspaceRole::Owner]),
            ];
            $this->notifier->notify('request_expired', $recipients, $workspace->id, $request, [DomainNotifier::DATABASE, DomainNotifier::TELEGRAM], [], 'warning');

            return true;
        });
    }

    /**
     * @return list<int>
     */
    public function sweepExpired(): array
    {
        $cutoff = CarbonImmutable::instance($this->clock->now())->subSeconds(60);
        $expired = [];
        $routes = ApprovalRoute::query()->withoutGlobalScope(WorkspaceScope::class)
            ->where('status', RouteStatus::Active->value)
            ->where('expires_at', '<', $cutoff)
            ->orderBy('id')
            ->limit(500)
            ->get();

        foreach ($routes as $route) {
            if ($this->expireRoute($route)) {
                $expired[] = (int) $route->getKey();
            }
        }

        return $expired;
    }

    /**
     * @param  list<PaymentRequestStatus>  $allowed
     */
    public function expectStatus(PaymentRequest $request, array $allowed): void
    {
        /** @var PaymentRequestStatus $status */
        $status = $request->getAttribute('status');

        if (! in_array($status, $allowed, true)) {
            throw InvalidTransition::between($status, $allowed[0]);
        }
    }

    public static function assertComment(DecisionReason $reason, ?string $comment): void
    {
        if ($reason === DecisionReason::Other && ($comment === null || trim($comment) === '')) {
            throw new WorkflowException('comment_required', 'comment');
        }
    }

    private function closeRoute(PaymentRequest $request, Workspace $workspace, RouteStatus $status, string $statusKey): void
    {
        $routeId = $request->getAttribute('current_route_id');

        if (! is_int($routeId)) {
            return;
        }

        $route = ApprovalRoute::query()->forWorkspace($workspace)->whereKey($routeId)->lockForUpdate()->first();

        if ($route === null || $route->getAttribute('status') !== RouteStatus::Active) {
            return;
        }

        $route->forceFill(['status' => $status, 'closed_at' => CarbonImmutable::instance($this->clock->now())])->save();
        $this->routes->revokePending($route, $workspace, $statusKey);
    }

    private function notifyRejected(PaymentRequest $request, Workspace $workspace, DecisionReason $reason, ?User $actor): void
    {
        $ids = [(int) $request->getAttribute('requested_by'), is_int($s = $request->getAttribute('submitted_by')) ? $s : null];
        $recipients = [...DomainNotifier::users($ids), ...DomainNotifier::membersWithRoles($workspace, [WorkspaceRole::Owner, WorkspaceRole::Admin])];

        if ($request->getAttribute('kind') === PaymentRequestKind::PayrollChange && is_int($vendorId = $request->getAttribute('vendor_id'))) {
            $linked = Vendor::query()->forWorkspace($workspace)->withTrashed()->whereKey($vendorId)->value('linked_user_id');

            if (is_numeric($linked)) {
                $recipients = [...$recipients, ...DomainNotifier::users([(int) $linked])];
            }
        }

        $amount = $request->getAttribute('prevented_amount_base_minor');
        $this->notifier->notify('request_rejected', $recipients, $workspace->id, $request, [DomainNotifier::DATABASE, DomainNotifier::TELEGRAM, DomainNotifier::EMAIL], [
            'reason_label' => static fn (string $locale): string => $reason->label($locale),
            'actor' => static fn (string $locale): string => $actor !== null ? $actor->name : (string) __('domain.notifications.system', [], $locale),
            'prevented_line' => static fn (string $locale): string => is_int($amount)
                ? (string) __('domain.notifications.prevented_line', ['amount' => Money::of($amount, (string) $request->getAttribute('base_currency'))->format($locale)], $locale)
                : '',
        ], 'danger');
    }
}
