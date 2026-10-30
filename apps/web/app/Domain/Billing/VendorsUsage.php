<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Billing\UsageResolver;
use App\Domain\Vendors\Models\Vendor;
use App\Models\Workspace;

final class VendorsUsage implements UsageResolver
{
    public const string KEY = 'vendors';

    public function key(): string
    {
        return self::KEY;
    }

    public function used(Workspace $workspace): int
    {
        return Vendor::query()->forWorkspace($workspace)->count();
    }
}
