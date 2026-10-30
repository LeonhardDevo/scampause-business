<?php

declare(strict_types=1);

namespace App\Domain\Risk\Contracts;

use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Risk\RiskResult;

interface PolicyVerificationResolver
{
    /**
     * @return array{required: bool, reasons: list<string>}
     */
    public function resolve(PaymentRequest $request, RiskResult $preliminary): array;
}
