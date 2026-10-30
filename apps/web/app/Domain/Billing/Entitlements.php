<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Models\Workspace;

final class Entitlements
{
    public function allows(Workspace $workspace, string $key): bool
    {
        return (bool) config('billing.plans.'.$workspace->planKey().'.entitlements.'.$key, false);
    }
}
