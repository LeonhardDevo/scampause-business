<?php

declare(strict_types=1);

namespace App\Domain\Reports\Contracts;

use App\Models\User;
use App\Models\Workspace;

interface DashboardData
{
    /**
     * @return array<string, mixed>
     */
    public function props(Workspace $workspace, User $user): array;
}
