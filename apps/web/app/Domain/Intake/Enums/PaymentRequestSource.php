<?php

declare(strict_types=1);

namespace App\Domain\Intake\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum PaymentRequestSource: string
{
    use EnumHelpers;

    case Upload = 'upload';
    case Eml = 'eml';
    case Manual = 'manual';
}
