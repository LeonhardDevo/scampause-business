<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Notifications\DomainNotifier;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

final readonly class GovernanceRecorder
{
    public function __construct(private EvidenceLedger $ledger, private DomainNotifier $notifier) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(Workspace $workspace, string $event, string $subjectType, int $subjectId, array $payload, ?User $actor, string $href = '/policies'): void
    {
        $this->ledger->append(
            workspaceId: $workspace->id,
            event: $event,
            subjectType: $subjectType,
            subjectId: $subjectId,
            payload: $payload,
            actorUserId: $actor?->id,
        );

        $recipients = array_values(array_filter(
            DomainNotifier::membersWithRoles($workspace, [WorkspaceRole::Owner]),
            static fn (User $owner): bool => $owner->id !== $actor?->id,
        ));

        $this->notifier->notify('controls_changed', $recipients, $workspace->id, null, [DomainNotifier::DATABASE, DomainNotifier::TELEGRAM], [
            'event' => $event,
            'event_label' => static fn (string $locale): string => (string) __('domain.events.'.str_replace('.', '_', $event), [], $locale),
            'actor' => static fn (string $locale): string => $actor !== null ? $actor->name : (string) __('domain.notifications.system', [], $locale),
        ], 'warning', $href);
    }
}
