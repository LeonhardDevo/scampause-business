<?php

declare(strict_types=1);

namespace App\Domain\Vendors\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum BankAccountStatus: string
{
    use EnumHelpers;

    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Retired = 'retired';
}
