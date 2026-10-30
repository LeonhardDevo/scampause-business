<?php

declare(strict_types=1);

namespace App\Domain\Intake\Contracts;

use App\Domain\Intake\Models\PaymentRequest;

interface PolicyPreview
{
    /**
     * @return array<string, mixed>|null
     */
    public function preview(PaymentRequest $request): ?array;
}
