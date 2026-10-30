<?php

declare(strict_types=1);

namespace App\Domain\Vendors\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum ContactStatus: string
{
    use EnumHelpers;

    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case Retired = 'retired';
}
