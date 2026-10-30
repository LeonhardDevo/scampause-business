<?php

declare(strict_types=1);

namespace App\Domain\Risk;

use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Risk\Contracts\PolicyVerificationResolver;

final class NoPolicyVerification implements PolicyVerificationResolver
{
    public function resolve(PaymentRequest $request, RiskResult $preliminary): array
    {
        return ['required' => false, 'reasons' => []];
    }
}
