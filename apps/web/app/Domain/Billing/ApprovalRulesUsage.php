<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Billing\UsageResolver;
use App\Domain\Policy\Models\ApprovalRule;
use App\Models\Workspace;

final class ApprovalRulesUsage implements UsageResolver
{
    public const string KEY = 'approval_rules';

    public function key(): string
    {
        return self::KEY;
    }

    public function used(Workspace $workspace): int
    {
        return ApprovalRule::query()->forWorkspace($workspace)->where('is_active', true)->count();
    }
}
