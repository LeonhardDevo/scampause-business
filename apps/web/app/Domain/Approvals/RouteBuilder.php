<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Audit\Auditor;
use App\Domain\Approvals\Data\ApproverCandidate;
use App\Domain\Approvals\Data\ScheduleSettings;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Enums\RouteStatus;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Approvals\Models\ApprovalRoute;
use App\Domain\Evidence\Enums\EvidenceChannel;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Exceptions\InvalidTransition;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\Pipeline;
use App\Domain\Intake\StateMachine;
use App\Domain\Notifications\DomainNotifier;
use App\Domain\Policy\PaymentSettings;
use App\Domain\Policy\RequestPolicy;
use App\Domain\Risk\Enums\RiskLevel;
use App\Domain\Verification\Models\Verification;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Psr\Clock\ClockInterface;

final readonly class RouteBuilder
{
    public function __construct(
        private RequestPolicy $policy,
        private ApproverPool $pool,
        private ApprovalPublisher $publisher,
        private DomainNotifier $notifier,
        private EvidenceLedger $ledger,
        private Auditor $auditor,
        private ClockInterface $clock,
    ) {}

    public function build(PaymentRequest $request, ?User $actor, EvidenceChannel $channel = EvidenceChannel::Web): ApprovalRoute
    {
        $workspace = Workspace::query()->findOrFail((int) $request->getAttribute('workspace_id'));
        $level = $request->getAttribute('risk_level');
        $evaluation = $this->policy->evaluate($workspace, $this->policy->inputFor($request, $this->policy->signalCodes($request), $level instanceof RiskLevel ? $level : RiskLevel::Low));
        $solo = $this->pool->soloMode($workspace);
        $slots = $solo ? 1 : $evaluation->approvalsRequired;
        $requiredRoles = $solo ? [] : $evaluation->requiredRoles;
        $eligible = $this->pool->eligible(
            $workspace,
            $evaluation->approverRoles,
            $evaluation->amountBaseMinor,
            $this->excluded($request),
            (int) $request->getKey(),
            $solo,
        );
        $picked = ApproverPool::pickSlots($eligible, $slots, $requiredRoles, $this->verifierId($request));

        foreach ($picked as $candidate) {
            $this->pool->lock($workspace->id, $candidate->user->id);
        }

        $now = CarbonImmutable::instance($this->clock->now());
        $settings = ScheduleSettings::from($workspace, PaymentSettings::for($workspace));
        $expires = ApprovalSchedule::deadline($now, $evaluation->expiryHours, $settings);
        $attempt = (int) ApprovalRoute::query()->forWorkspace($workspace)->where('payment_request_id', $request->getKey())->max('attempt') + 1;

        $route = new ApprovalRoute;
        $route->forceFill([
            'workspace_id' => $workspace->id,
            'payment_request_id' => $request->getKey(),
            'attempt' => $attempt,
            'status' => RouteStatus::Active,
            'approvals_required' => $slots,
            'required_roles' => $requiredRoles,
            'approver_roles' => $evaluation->approverRoles,
            'rule_snapshot' => $evaluation->matchedRules,
            'solo_mode' => $solo,
            'started_at' => $now,
            'expires_at' => $expires,
            'created_by' => $actor?->id,
        ])->save();

        $pipeline = Pipeline::of($request);
        $pipeline->put('route_fingerprint', is_string($fp = $request->getAttribute('payee_fingerprint')) ? $fp : null)->writeTo($request);
        $request->forceFill(['current_route_id' => $route->getKey(), 'expires_at' => $expires])->save();

        $this->ledger->append(
            workspaceId: $workspace->id,
            event: 'approval.route_created',
            subjectType: 'payment_request',
            subjectId: (int) $request->getKey(),
            payload: [
                'attempt' => $attempt,
                'approvals_required' => $slots,
                'required_roles' => $requiredRoles,
                'rules' => array_map(static fn (array $rule): array => ['id' => $rule['id'], 'version' => $rule['version']], $evaluation->matchedRules),
                'expires_at' => EvidenceLedger::timestamp($expires),
                'solo_mode' => $solo,
                'sod_waived' => $solo,
            ],
            actorUserId: $actor?->id,
            channel: $channel,
            paymentRequestId: (int) $request->getKey(),
        );

        $approvals = [];

        foreach ($picked as $index => $candidate) {
            $approvals[] = $this->assign($route, $request, $candidate, $index + 1, $actor, $channel);
        }

        $this->auditor->record('approval.route_created', $route, metadata: ['attempt' => $attempt, 'approvers' => count($picked), 'required' => $slots], actor: $actor, workspace: $workspace, subjectLabel: (string) $request->getAttribute('reference'));

        if (count($picked) < $slots) {
            $this->incomplete($request, $route, $workspace, $slots - count($picked), $actor, count($picked), $slots);
        }

        foreach ($approvals as $approval) {
            $this->publisher->requested($approval, $request, $workspace, $route);
        }

        $this->publisher->schedule($route, $request, $workspace);

        return $route;
    }

    public function fillMissing(ApprovalRoute $route, ?User $actor = null): int
    {
        if ($route->getAttribute('status') !== RouteStatus::Active) {
            return 0;
        }

        $workspace = Workspace::query()->findOrFail((int) $route->getAttribute('workspace_id'));
        $request = PaymentRequest::query()->forWorkspace($workspace)->findOrFail((int) $route->getAttribute('payment_request_id'));
        $live = Approval::query()->forWorkspace($workspace)
            ->where('approval_route_id', $route->getKey())
            ->whereNotIn('status', [ApprovalStatus::Reassigned->value, ApprovalStatus::Revoked->value, ApprovalStatus::Expired->value])
            ->get();
        $required = (int) $route->getAttribute('approvals_required');
        $missing = $required - $live->count();

        if ($missing <= 0) {
            return 0;
        }

        $taken = $live->pluck('approver_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $usedSlots = $live->pluck('slot')->map(static fn (mixed $slot): int => (int) $slot)->all();
        $eligible = array_values(array_filter(
            $this->pool->eligible($workspace, $this->roles($route->getAttribute('approver_roles')), self::amount($request), $this->excluded($request), (int) $request->getKey(), (bool) $route->getAttribute('solo_mode')),
            static fn (ApproverCandidate $candidate): bool => ! in_array($candidate->user->id, $taken, true),
        ));
        $picked = ApproverPool::pickSlots($eligible, $missing, [], $this->verifierId($request));
        $slot = 1;
        $added = 0;

        foreach ($picked as $candidate) {
            while (in_array($slot, $usedSlots, true)) {
                $slot++;
            }

            $this->pool->lock($workspace->id, $candidate->user->id);
            $approval = $this->assign($route, $request, $candidate, $slot, $actor, EvidenceChannel::System);
            $usedSlots[] = $slot;
            $this->publisher->requested($approval, $request, $workspace, $route);
            $this->publisher->scheduleReminders($approval, $request, $workspace);
            $added++;
        }

        return $added;
    }

    public function reassign(Approval $approval, User $newApprover, User $actor): Approval
    {
        $workspace = Workspace::query()->findOrFail((int) $approval->getAttribute('workspace_id'));
        $request = PaymentRequest::query()->forWorkspace($workspace)->whereKey((int) $approval->getAttribute('payment_request_id'))->lockForUpdate()->firstOrFail();
        $route = ApprovalRoute::query()->forWorkspace($workspace)->whereKey((int) $approval->getAttribute('approval_route_id'))->lockForUpdate()->firstOrFail();
        $approval = Approval::query()->forWorkspace($workspace)->whereKey($approval->getKey())->lockForUpdate()->firstOrFail();

        if ($request->getAttribute('status') !== PaymentRequestStatus::AwaitingApproval
            || $route->getAttribute('status') !== RouteStatus::Active
            || $approval->getAttribute('status') !== ApprovalStatus::Pending) {
            throw new InvalidTransition('invalid_transition');
        }

        $role = $newApprover->roleIn($workspace);
        $assigned = Approval::query()->forWorkspace($workspace)
            ->where('approval_route_id', $route->getKey())
            ->whereNotIn('status', [ApprovalStatus::Reassigned->value, ApprovalStatus::Revoked->value])
            ->pluck('approver_id')->map(static fn (mixed $id): int => (int) $id)->all();

        if ($role === null || in_array($newApprover->id, $assigned, true)) {
            throw new InvalidTransition('approver_not_eligible');
        }

        $this->pool->lock($workspace->id, $newApprover->id);
        $candidate = new ApproverCandidate($newApprover, $role);

        if (! $this->pool->isEligible($workspace, $candidate, $this->roles($route->getAttribute('approver_roles')), self::amount($request), $this->excluded($request), (int) $request->getKey(), (bool) $route->getAttribute('solo_mode'))) {
            throw new InvalidTransition('approver_not_eligible');
        }

        $approval->forceFill(['status' => ApprovalStatus::Reassigned, 'decided_at' => CarbonImmutable::instance($this->clock->now())])->save();
        $replacement = new Approval;
        $replacement->forceFill([
            'workspace_id' => $workspace->id,
            'approval_route_id' => $route->getKey(),
            'payment_request_id' => $request->getKey(),
            'approver_id' => $newApprover->id,
            'slot' => (int) $approval->getAttribute('slot'),
            'status' => ApprovalStatus::Pending,
            'replaced_approval_id' => $approval->getKey(),
        ])->save();

        $this->ledger->append(
            workspaceId: $workspace->id,
            event: 'approval.reassigned',
            subjectType: 'approval',
            subjectId: (int) $replacement->getKey(),
            payload: ['from' => (int) $approval->getAttribute('approver_id'), 'to' => $newApprover->id],
            actorUserId: $actor->id,
            paymentRequestId: (int) $request->getKey(),
        );
        $this->auditor->record('approval.reassigned', $replacement, ['approver_id' => [(int) $approval->getAttribute('approver_id'), $newApprover->id]], actor: $actor, workspace: $workspace, subjectLabel: (string) $request->getAttribute('reference'));
        $this->publisher->stripButtons($approval, 'reassigned');
        $this->publisher->requested($replacement, $request, $workspace, $route);
        $this->publisher->scheduleReminders($replacement, $request, $workspace);
        $this->fillMissing($route, $actor);

        return $replacement;
    }

    public function markIncomplete(PaymentRequest $request, ApprovalRoute $route, string $reason): void
    {
        $workspace = Workspace::query()->findOrFail((int) $request->getAttribute('workspace_id'));
        /** @var PaymentRequestStatus $status */
        $status = $request->getAttribute('status');
        StateMachine::assert($status, PaymentRequestStatus::Review);
        $now = CarbonImmutable::instance($this->clock->now());
        $route->forceFill(['status' => RouteStatus::Superseded, 'closed_at' => $now])->save();
        $this->revokePending($route, $workspace, 'route_closed');
        $request->forceFill(['status' => PaymentRequestStatus::Review, 'current_route_id' => null, 'expires_at' => null])->save();

        $this->ledger->append(
            workspaceId: $workspace->id,
            event: 'route.incomplete',
            subjectType: 'payment_request',
            subjectId: (int) $request->getKey(),
            payload: ['missing_slots' => (int) $route->getAttribute('approvals_required'), 'reason' => $reason],
            channel: EvidenceChannel::System,
            paymentRequestId: (int) $request->getKey(),
        );
        $this->notifier->notify('route_incomplete', DomainNotifier::membersWithRoles($workspace, [WorkspaceRole::Owner, WorkspaceRole::Admin]), $workspace->id, $request, [DomainNotifier::DATABASE, DomainNotifier::TELEGRAM], [
            'required' => (int) $route->getAttribute('approvals_required'),
            'available' => 0,
        ], 'warning');
    }

    public function revokePending(ApprovalRoute $route, Workspace $workspace, string $statusKey): void
    {
        $pending = Approval::query()->forWorkspace($workspace)
            ->where('approval_route_id', $route->getKey())
            ->where('status', ApprovalStatus::Pending->value)
            ->lockForUpdate()
            ->get();

        foreach ($pending as $approval) {
            $approval->forceFill(['status' => ApprovalStatus::Revoked, 'decided_at' => CarbonImmutable::instance($this->clock->now()), 'telegram_nonce_hash' => null])->save();
            $this->publisher->stripButtons($approval, $statusKey);
        }
    }

    /** @return list<int> */
    public function excluded(PaymentRequest $request): array
    {
        return array_values(array_unique(array_filter([
            (int) $request->getAttribute('requested_by'),
            is_int($s = $request->getAttribute('submitted_by')) ? $s : null,
        ], static fn (?int $id): bool => $id !== null && $id > 0)));
    }

    public static function amount(PaymentRequest $request): ?int
    {
        $base = $request->getAttribute('amount_base_minor');
        $amount = $request->getAttribute('amount_minor');

        if (! is_int($amount)) {
            return 0;
        }

        return is_int($base) ? $base : null;
    }

    private function assign(ApprovalRoute $route, PaymentRequest $request, ApproverCandidate $candidate, int $slot, ?User $actor, EvidenceChannel $channel): Approval
    {
        $approval = new Approval;
        $approval->forceFill([
            'workspace_id' => (int) $route->getAttribute('workspace_id'),
            'approval_route_id' => $route->getKey(),
            'payment_request_id' => $request->getKey(),
            'approver_id' => $candidate->user->id,
            'slot' => $slot,
            'status' => ApprovalStatus::Pending,
        ])->save();

        $this->ledger->append(
            workspaceId: (int) $route->getAttribute('workspace_id'),
            event: 'approval.assigned',
            subjectType: 'approval',
            subjectId: (int) $approval->getKey(),
            payload: ['slot' => $slot, 'approver_id' => $candidate->user->id],
            actorUserId: $actor?->id,
            channel: $channel,
            paymentRequestId: (int) $request->getKey(),
        );

        return $approval;
    }

    private function incomplete(PaymentRequest $request, ApprovalRoute $route, Workspace $workspace, int $missing, ?User $actor, int $available, int $required): void
    {
        $this->ledger->append(
            workspaceId: $workspace->id,
            event: 'route.incomplete',
            subjectType: 'payment_request',
            subjectId: (int) $request->getKey(),
            payload: ['missing_slots' => $missing],
            actorUserId: $actor?->id,
            channel: EvidenceChannel::System,
            paymentRequestId: (int) $request->getKey(),
        );
        $this->notifier->notify('route_incomplete', DomainNotifier::membersWithRoles($workspace, [WorkspaceRole::Owner, WorkspaceRole::Admin]), $workspace->id, $request, [DomainNotifier::DATABASE, DomainNotifier::TELEGRAM], [
            'required' => $required,
            'available' => $available,
        ], 'warning');
    }

    private function verifierId(PaymentRequest $request): ?int
    {
        $id = $request->getAttribute('satisfying_verification_id');

        if (! is_int($id)) {
            return null;
        }

        $performer = Verification::query()->forWorkspace((int) $request->getAttribute('workspace_id'))->whereKey($id)->value('performed_by');

        return is_numeric($performer) ? (int) $performer : null;
    }

    /** @return list<string> */
    private function roles(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
