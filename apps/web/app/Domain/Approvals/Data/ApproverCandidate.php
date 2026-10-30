<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Data;

use App\Enums\WorkspaceRole;
use App\Models\User;

final readonly class ApproverCandidate
{
    public function __construct(public User $user, public WorkspaceRole $role) {}
}
