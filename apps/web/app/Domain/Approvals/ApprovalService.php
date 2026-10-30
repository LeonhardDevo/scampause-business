<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Audit\Auditor;
use App\Domain\Approvals\Data\ApproverCandidate;
use App\Domain\Approvals\Data\ScheduleSettings;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Enums\DecisionChannel;
use App\Domain\Approvals\Enums\RouteStatus;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Approvals\Models\ApprovalRoute;
use App\Domain\Evidence\Enums\EvidenceChannel;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Intake\Enums\DecisionReason;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Exceptions\InvalidTransition;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\Pipeline;
use App\Domain\Intake\PipelineService;
use App\Domain\Notifications\DomainNotifier;
use App\Domain\Policy\PaymentSettings;
use App\Domain\Reports\ExportService;
use App\Domain\Risk\Enums\RiskLevel;
use App\Domain\Vendors\BankAccountService;
use App\Domain\Workflow\DecisionService;
use App\Domain\Workflow\WorkflowException;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

final readonly class ApprovalService
{
    public function __construct(
        private PipelineService $pipeline,
        private ApproverPool $pool,
        private ApprovalGate $gate,
        private ApprovalPublisher $publisher,
        private DecisionService $decisions,
        private BankAccountService $accounts,
        private ExportService $exports,
        private DomainNotifier $notifier,
        private EvidenceLedger $ledger,
        private Auditor $auditor,
        private ClockInterface $clock,
    ) {}

    public function approve(Approval $subject, User $actor, DecisionChannel $channel = DecisionChannel::Web, ?string $comment = null): Approval
    {
        return DB::transaction(function () use ($subject, $actor, $channel, $comment): Approval {
            [$workspace, $request, $route, $approval] = $this->lockAll($subject);
            $this->guardDecision($workspace, $request, $route, $approval, $actor);
            $this->pool->lock($workspace->id, $actor->id);
            $role = $actor->roleIn($workspace);
            $solo = (bool) $route->getAttribute('solo_mode');

            if ($role === null || ! $this->pool->isEligible(
                $workspace,
                new ApproverCandidate($actor, $role),
                $this->roles($route->getAttribute('approver_roles')),
                RouteBuilder::amount($request),
                $solo ? [] : $this->excluded($request),
                (int) $request->getKey(),
                $solo,
                0,
            )) {
                throw new WorkflowException('approver_no_longer_eligible');
            }

            if ((bool) $request->getAttribute('verification_required')) {
                $verifiedFingerprint = Pipeline::of($request)->value('verified_fingerprint');

                if (! is_int($request->getAttribute('satisfying_verification_id')) || $verifiedFingerprint !== $request->getAttribute('payee_fingerprint')) {
                    throw new WorkflowException('verification_not_satisfied');
                }
            }

            if (Pipeline::of($request)->value('route_fingerprint') !== $request->getAttribute('payee_fingerprint')) {
                throw new WorkflowException('payee_changed');
            }

            if ($channel === DecisionChannel::Telegram) {
                $reason = $this->gate->telegramBlockReason($request, $workspace);

                if ($reason !== null) {
                    throw new WorkflowException('telegram_approve_blocked', 'workflow', ['reason' => $reason]);
                }
            }

            $now = CarbonImmutable::instance($this->clock->now());
            $approval->forceFill([
                'status' => ApprovalStatus::Approved,
                'channel' => $channel,
                'decided_at' => $now,
                'comment' => $comment !== null && trim($comment) !== '' ? mb_substr(trim($comment), 0, 2000, 'UTF-8') : null,
                'telegram_nonce_hash' => null,
            ])->save();

            $level = $request->getAttribute('risk_level');
            $evidenceChannel = $channel === DecisionChannel::Telegram ? EvidenceChannel::Telegram : EvidenceChannel::Web;
            $this->ledger->append(
                workspaceId: $workspace->id,
                event: 'approval.approved',
                subjectType: 'approval',
                subjectId: (int) $approval->getKey(),
                payload: ['slot' => (int) $approval->getAttribute('slot'), 'risk_level' => $level instanceof RiskLevel ? $level->value : null],
                actorUserId: $actor->id,
                channel: $evidenceChannel,
                paymentRequestId: (int) $request->getKey(),
            );
            $this->auditor->record('approval.approved', $approval, ['status' => ['pending', 'approved']], ['channel' => $channel->value], actor: $actor, workspace: $workspace, subjectLabel: (string) $request->getAttribute('reference'));

            if ($channel === DecisionChannel::Web) {
                $this->publisher->stripButtons($approval, 'approved');
            }

            $this->completeIfReady($workspace, $request, $route, $actor, $evidenceChannel);

            return $approval;
        });
    }

    public function reject(Approval $subject, User $actor, DecisionReason $reason, ?string $comment, DecisionChannel $channel = DecisionChannel::Web): Approval
    {
        DecisionService::assertComment($reason, $comment);

        return DB::transaction(function () use ($subject, $actor, $reason, $comment, $channel): Approval {
            [$workspace, $request, $route, $approval] = $this->lockAll($subject);
            $this->guardDecision($workspace, $request, $route, $approval, $actor);
            $role = $actor->roleIn($workspace);

            if ($role === null || ! $role->allows('payment_requests.approve')) {
                throw new WorkflowException('approver_no_longer_eligible');
            }

            $now = CarbonImmutable::instance($this->clock->now());
            $approval->forceFill([
                'status' => ApprovalStatus::Rejected,
                'channel' => $channel,
                'decided_at' => $now,
                'reject_reason' => $reason,
                'comment' => $comment !== null && trim($comment) !== '' ? mb_substr(trim($comment), 0, 2000, 'UTF-8') : null,
                'telegram_nonce_hash' => null,
            ])->save();

            $evidenceChannel = $channel === DecisionChannel::Telegram ? EvidenceChannel::Telegram : EvidenceChannel::Web;
            $this->ledger->append(
                workspaceId: $workspace->id,
                event: 'approval.rejected',
                subjectType: 'approval',
                subjectId: (int) $approval->getKey(),
                payload: ['slot' => (int) $approval->getAttribute('slot'), 'reason' => $reason->value],
                actorUserId: $actor->id,
                channel: $evidenceChannel,
                paymentRequestId: (int) $request->getKey(),
            );
            $this->auditor->record('approval.rejected', $approval, ['status' => ['pending', 'rejected']], ['reason' => $reason->value, 'channel' => $channel->value], actor: $actor, workspace: $workspace, subjectLabel: (string) $request->getAttribute('reference'));

            if ($channel === DecisionChannel::Web) {
                $this->publisher->stripButtons($approval, 'rejected');
            }

            $this->decisions->reject($request, $reason, $comment, $actor, $evidenceChannel);

            return $approval;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function reminder(Approval $subject, int $sequence, int $total): array
    {
        return DB::transaction(function () use ($subject, $sequence, $total): array {
            [$workspace, $request, $route, $approval] = $this->lockAll($subject);

            if ($approval->getAttribute('status') !== ApprovalStatus::Pending || $request->getAttribute('status') !== PaymentRequestStatus::AwaitingApproval) {
                return ['send' => false, 'reason' => 'decided'];
            }

            if ($route->getAttribute('status') !== RouteStatus::Active) {
                return ['send' => false, 'reason' => 'route_closed'];
            }

            $now = CarbonImmutable::instance($this->clock->now());
            $settings = ScheduleSettings::from($workspace, PaymentSettings::for($workspace));

            if (ApprovalSchedule::inQuiet($now, $settings)) {
                $this->publisher->rescheduleReminder($approval, $sequence, $total, ApprovalSchedule::shiftOutOfQuiet($now, $settings));

                return ['send' => false, 'reason' => 'quiet_hours_rescheduled'];
            }

            $user = User::query()->findOrFail((int) $approval->getAttribute('approver_id'));
            $locale = DomainNotifier::locale($user);
            $token = StepTokens::issue();
            $last = $total > 0 && $sequence >= $total;
            $approval->forceFill([
                'reminders_sent' => (int) $approval->getAttribute('reminders_sent') + 1,
                'last_reminded_at' => $now,
                'telegram_nonce_hash' => $token['hash'],
            ])->save();
            $params = [
                ...$this->publisher->params($approval, $request, $workspace, $route, $locale),
                'n' => $sequence,
                'total' => max($sequence, $total),
            ];
            $response = ['send' => true, 'sp_notification_ids' => []];
            $channels = [];
            $account = $user->telegramAccount;

            if ($account !== null) {
                $row = $this->notifier->row('approval_reminder', $user, $workspace->id, $request, DomainNotifier::TELEGRAM, 'queued', (int) $approval->getKey());
                $message = $approval->getAttribute('telegram_message');
                $response['telegram'] = array_filter([
                    'chat_id' => (int) $account->chat_id,
                    'template' => 'sp.approval_reminder',
                    'params' => $params,
                    'actions' => $this->publisher->actions($approval, $request, $workspace, $token['token'], $locale),
                    'reply_to_message_id' => is_array($message) && isset($message['message_id']) ? (int) $message['message_id'] : null,
                ], static fn (mixed $value): bool => $value !== null);
                $response['sp_notification_ids']['telegram'] = (int) $row->getKey();
                $channels[] = 'telegram';
            }

            if ($last || $account === null) {
                $row = $this->notifier->row('approval_reminder', $user, $workspace->id, $request, DomainNotifier::EMAIL, 'queued', (int) $approval->getKey());
                $response['email'] = ['to' => $user->email, ...DomainNotifier::renderEmail('approval_reminder', $params, $locale, url('/payment-requests/'.$request->getKey().'?tab=approvals'))];
                $response['sp_notification_ids']['email'] = (int) $row->getKey();
                $channels[] = 'email';
            }

            $this->ledger->append(
                workspaceId: $workspace->id,
                event: 'approval.reminded',
                subjectType: 'approval',
                subjectId: (int) $approval->getKey(),
                payload: ['sequence' => $sequence, 'channels' => $channels],
                channel: EvidenceChannel::System,
                paymentRequestId: (int) $request->getKey(),
            );

            return $response;
        });
    }

    public function escalate(ApprovalRoute $subject): bool
    {
        return DB::transaction(function () use ($subject): bool {
            $workspace = Workspace::query()->findOrFail((int) $subject->getAttribute('workspace_id'));
            $request = $this->pipeline->lock($workspace->id, (int) $subject->getAttribute('payment_request_id'));
            $route = ApprovalRoute::query()->forWorkspace($workspace)->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();

            if ($route->getAttribute('status') !== RouteStatus::Active || $route->getAttribute('escalated_at') !== null || $request->getAttribute('status') !== PaymentRequestStatus::AwaitingApproval) {
                return false;
            }

            $now = CarbonImmutable::instance($this->clock->now());
            $route->forceFill(['escalated_at' => $now])->save();
            $this->ledger->append(
                workspaceId: $workspace->id,
                event: 'approval.escalated',
                subjectType: 'payment_request',
                subjectId: (int) $request->getKey(),
                payload: [],
                channel: EvidenceChannel::System,
                paymentRequestId: (int) $request->getKey(),
            );
            $pending = Approval::query()->forWorkspace($workspace)->where('approval_route_id', $route->getKey())->where('status', ApprovalStatus::Pending->value)->with('approver:id,name')->get();
            $names = implode(', ', $pending->map(static fn (Approval $approval): string => (string) $approval->approver?->name)->all());
            $started = CarbonImmutable::instance($route->getAttribute('started_at'));
            $this->notifier->notify('approval_escalation', DomainNotifier::membersWithRoles($workspace, [WorkspaceRole::Owner, WorkspaceRole::Admin]), $workspace->id, $request, [DomainNotifier::DATABASE, DomainNotifier::TELEGRAM, DomainNotifier::EMAIL], [
                'hours' => (int) floor(($now->getTimestamp() - $started->getTimestamp()) / 3600),
                'approvers' => $names,
                'expires_local' => CarbonImmutable::instance($route->getAttribute('expires_at'))->setTimezone($workspace->timezone ?: 'UTC')->format('Y-m-d H:i'),
            ], 'warning');

            return true;
        });
    }

    /**
     * @return array{0: Workspace, 1: PaymentRequest, 2: ApprovalRoute, 3: Approval}
     */
    private function lockAll(Approval $subject): array
    {
        $workspace = Workspace::query()->findOrFail((int) $subject->getAttribute('workspace_id'));
        $request = $this->pipeline->lock($workspace->id, (int) $subject->getAttribute('payment_request_id'));
        $route = ApprovalRoute::query()->forWorkspace($workspace)->whereKey((int) $subject->getAttribute('approval_route_id'))->lockForUpdate()->firstOrFail();
        $approval = Approval::query()->forWorkspace($workspace)->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();

        return [$workspace, $request, $route, $approval];
    }

    private function guardDecision(Workspace $workspace, PaymentRequest $request, ApprovalRoute $route, Approval $approval, User $actor): void
    {
        if ($approval->getAttribute('status') !== ApprovalStatus::Pending
            || $route->getAttribute('status') !== RouteStatus::Active
            || $request->getAttribute('status') !== PaymentRequestStatus::AwaitingApproval
            || (int) $request->getAttribute('current_route_id') !== (int) $route->getKey()) {
            throw new InvalidTransition('invalid_transition');
        }

        if ((int) $approval->getAttribute('approver_id') !== $actor->id) {
            throw new WorkflowException('not_assignee');
        }

        if (! $actor->belongsToWorkspace($workspace)) {
            throw new WorkflowException('approver_no_longer_eligible');
        }

        if (! (bool) $route->getAttribute('solo_mode') && in_array($actor->id, $this->excluded($request), true)) {
            throw new WorkflowException('sod_approver');
        }
    }

    private function completeIfReady(Workspace $workspace, PaymentRequest $request, ApprovalRoute $route, User $actor, EvidenceChannel $channel): void
    {
        $approved = Approval::query()->forWorkspace($workspace)
            ->where('approval_route_id', $route->getKey())
            ->where('status', ApprovalStatus::Approved->value)
            ->get();
        $roles = [];

        foreach ($approved as $approval) {
            $user = User::query()->find((int) $approval->getAttribute('approver_id'));
            $role = $user?->roleIn($workspace);
            $roles[] = $role instanceof WorkspaceRole ? $role->value : 'member';
        }

        $required = (int) $route->getAttribute('approvals_required');

        if ($approved->count() < $required || ! ApproverPool::rolesCovered($this->roles($route->getAttribute('required_roles')), $roles)) {
            return;
        }

        $now = CarbonImmutable::instance($this->clock->now());
        $route->forceFill(['status' => RouteStatus::Completed, 'closed_at' => $now])->save();

        foreach (Approval::query()->forWorkspace($workspace)->where('approval_route_id', $route->getKey())->where('status', ApprovalStatus::Pending->value)->get() as $extra) {
            $extra->forceFill(['status' => ApprovalStatus::Revoked, 'decided_at' => $now, 'telegram_nonce_hash' => null])->save();
            $this->publisher->stripButtons($extra, 'approved_by_team');
        }

        $request->forceFill([
            'status' => PaymentRequestStatus::Approved,
            'decided_at' => $now,
            'decided_by' => $actor->id,
            'expires_at' => null,
        ])->save();

        $this->ledger->append(
            workspaceId: $workspace->id,
            event: 'request.approved',
            subjectType: 'payment_request',
            subjectId: (int) $request->getKey(),
            payload: [],
            actorUserId: $actor->id,
            channel: $channel,
            paymentRequestId: (int) $request->getKey(),
        );
        $this->auditor->record('payment_request.approved', $request, ['status' => ['awaiting_approval', 'approved']], actor: $actor, workspace: $workspace, subjectLabel: (string) $request->getAttribute('reference'));
        $this->accounts->upsertFromApprovedRequest($request, $actor, $channel);

        $approverIds = $approved->pluck('approver_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $names = implode(', ', array_map(static fn (User $user): string => $user->name, DomainNotifier::users($approverIds)));
        $recipients = DomainNotifier::users([(int) $request->getAttribute('requested_by'), is_int($s = $request->getAttribute('submitted_by')) ? $s : null, ...$approverIds]);
        $recipients = array_values(array_filter($recipients, static fn (User $user): bool => $user->id !== $actor->id));
        $this->notifier->notify('request_approved', $recipients, $workspace->id, $request, [DomainNotifier::DATABASE, DomainNotifier::TELEGRAM], ['approvers' => $names], 'success');
        $this->exports->requestPacket($request, null);
    }

    /** @return list<int> */
    private function excluded(PaymentRequest $request): array
    {
        return array_values(array_filter([
            (int) $request->getAttribute('requested_by'),
            is_int($s = $request->getAttribute('submitted_by')) ? $s : null,
        ], static fn (?int $id): bool => $id !== null && $id > 0));
    }

    /** @return list<string> */
    private function roles(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
