<?php

declare(strict_types=1);

namespace App\Domain\Vendors\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum BlockReason: string
{
    use EnumHelpers;

    case FraudConfirmed = 'fraud_confirmed';
    case DetailsMismatch = 'details_mismatch';
    case Manual = 'manual';
}
