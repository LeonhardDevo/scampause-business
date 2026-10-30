<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Billing\UsageResolver;
use App\Domain\Intake\Models\PaymentRequest;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

final class PaymentRequestsMonthlyUsage implements UsageResolver
{
    public const string KEY = 'payment_requests_monthly';

    public function key(): string
    {
        return self::KEY;
    }

    public function used(Workspace $workspace): int
    {
        $start = CarbonImmutable::now($workspace->timezone ?: 'UTC')->startOfMonth()->utc();

        return PaymentRequest::query()->forWorkspace($workspace)->where('created_at', '>=', $start)->count();
    }
}
