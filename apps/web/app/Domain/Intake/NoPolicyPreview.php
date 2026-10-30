<?php

declare(strict_types=1);

namespace App\Domain\Intake;

use App\Domain\Intake\Contracts\PolicyPreview;
use App\Domain\Intake\Models\PaymentRequest;

final class NoPolicyPreview implements PolicyPreview
{
    public function preview(PaymentRequest $request): ?array
    {
        return null;
    }
}
