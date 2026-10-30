<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\Workspace;

interface UsageResolver
{
    public const string TAG = 'plan.usage_resolvers';

    public function key(): string;

    public function used(Workspace $workspace): int;
}
