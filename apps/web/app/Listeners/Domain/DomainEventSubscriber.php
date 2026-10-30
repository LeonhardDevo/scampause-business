<?php

declare(strict_types=1);

namespace App\Listeners\Domain;

use App\Domain\Evidence\Enums\EvidenceChannel;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Intake\Events\IntakeAssessed;
use App\Domain\Notifications\AssessmentNotifier;
use App\Domain\Notifications\Models\SpNotification;
use App\Domain\Workflow\MembershipChanges;
use App\Events\DeliveryReported;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Events\Dispatcher;

final readonly class DomainEventSubscriber
{
    public function __construct(
        private AssessmentNotifier $assessments,
        private EvidenceLedger $ledger,
        private MembershipChanges $memberships,
    ) {}

    public function intakeAssessed(IntakeAssessed $event): void
    {
        $this->assessments->afterIntake($event->request);
    }

    public function deliveryReported(DeliveryReported $event): void
    {
        $delivery = $event->delivery;

        if ($delivery->reference_type !== 'sp_notification' || ! is_numeric($delivery->reference_id)) {
            return;
        }

        $row = SpNotification::query()->withoutGlobalScopes()->find((int) $delivery->reference_id);

        if ($row === null) {
            return;
        }

        $status = $delivery->status === 'sent' ? 'sent' : 'failed';
        $row->forceFill([
            'status' => $status,
            'provider_message_id' => $delivery->provider_message_id,
            'error' => $delivery->error !== null ? mb_substr((string) $delivery->error, 0, 500, 'UTF-8') : null,
        ])->save();

        $this->ledger->append(
            workspaceId: (int) $row->getAttribute('workspace_id'),
            event: 'notification.delivered',
            subjectType: $row->getAttribute('payment_request_id') !== null ? 'payment_request' : 'sp_notification',
            subjectId: (int) ($row->getAttribute('payment_request_id') ?? $row->getKey()),
            payload: [
                'channel' => (string) $row->getAttribute('channel'),
                'template' => (string) $row->getAttribute('template'),
                'recipient_id' => is_int($r = $row->getAttribute('recipient_user_id')) ? $r : null,
                'status' => $status,
            ],
            channel: EvidenceChannel::Worker,
            paymentRequestId: is_int($p = $row->getAttribute('payment_request_id')) ? $p : null,
        );
    }

    public function auditRecorded(AuditLog $log): void
    {
        $action = (string) $log->action;

        if (! in_array($action, ['member.role_changed', 'member.removed', 'member.left'], true) || $log->workspace_id === null || ! is_numeric($log->subject_id)) {
            return;
        }

        $workspace = Workspace::query()->find((int) $log->workspace_id);

        if ($workspace === null) {
            return;
        }

        $changes = is_array($log->changes) ? $log->changes : [];
        $pair = is_array($changes['role'] ?? null) ? $changes['role'] : [null, null];
        $from = is_string($pair[0] ?? null) ? $pair[0] : null;
        $to = is_string($pair[1] ?? null) ? $pair[1] : null;
        $actor = $log->actor_id !== null ? User::query()->find((int) $log->actor_id) : null;

        if ($action === 'member.role_changed') {
            $this->memberships->roleChanged($workspace, (int) $log->subject_id, $from, $to, $actor);

            return;
        }

        $this->memberships->removed($workspace, (int) $log->subject_id, $from, $actor);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            IntakeAssessed::class => 'intakeAssessed',
            DeliveryReported::class => 'deliveryReported',
        ];
    }
}
