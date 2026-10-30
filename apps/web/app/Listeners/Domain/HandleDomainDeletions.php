<?php

declare(strict_types=1);

namespace App\Listeners\Domain;

use App\Domain\Privacy\UserPseudonymizer;
use App\Domain\Privacy\WorkspacePurger;
use App\Events\UserDeleting;
use App\Events\WorkspaceDeleting;
use Illuminate\Events\Dispatcher;

final readonly class HandleDomainDeletions
{
    public function __construct(
        private WorkspacePurger $purger,
        private UserPseudonymizer $pseudonymizer,
    ) {}

    public function workspaceDeleting(WorkspaceDeleting $event): void
    {
        $this->purger->purge($event->workspace);
    }

    public function userDeleting(UserDeleting $event): ?bool
    {
        if (! $this->pseudonymizer->isReferenced($event->user)) {
            return null;
        }

        $this->pseudonymizer->pseudonymize($event->user);

        return true;
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            WorkspaceDeleting::class => 'workspaceDeleting',
            UserDeleting::class => 'userDeleting',
        ];
    }
}
