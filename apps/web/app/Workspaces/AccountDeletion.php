<?php

declare(strict_types=1);

namespace App\Workspaces;

use App\Audit\Auditor;
use App\Enums\WorkspaceRole;
use App\Events\UserDeleting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final readonly class AccountDeletion
{
    public function __construct(
        private WorkspaceService $workspaces,
        private Auditor $auditor,
    ) {}

    /** @return Collection<int, Workspace> */
    public function blockingWorkspaces(User $user): Collection
    {
        return $this->soleOwnerWorkspaces($user)
            ->filter(fn (Workspace $workspace): bool => $workspace->members()->count() > 1)
            ->values();
    }

    /** @throws WorkspaceException */
    public function delete(User $user): void
    {
        $blocking = $this->blockingWorkspaces($user);

        if ($blocking->isNotEmpty()) {
            throw new WorkspaceException('transfer_ownership_first', ['workspace' => $blocking->first()->name]);
        }

        DB::transaction(function () use ($user): void {
            foreach ($this->soleOwnerWorkspaces($user) as $workspace) {
                $this->workspaces->delete($workspace, $user);
            }

            $this->auditor->record('auth.account_deleted', $user, actor: $user, workspace: null, subjectLabel: $user->email, platform: true);

            if (Event::until(new UserDeleting($user)) !== true) {
                $user->delete();
            }
        });
    }

    /** @return Collection<int, Workspace> */
    private function soleOwnerWorkspaces(User $user): Collection
    {
        return $user->workspaces()
            ->wherePivot('role', WorkspaceRole::Owner->value)
            ->get()
            ->filter(fn (Workspace $workspace): bool => $workspace->ownersCount() === 1)
            ->values();
    }
}
