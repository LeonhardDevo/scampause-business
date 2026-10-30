<?php

declare(strict_types=1);

namespace App\Domain\Intake\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum BankComparisonOutcome: string
{
    use EnumHelpers;

    case MatchVerified = 'match_verified';
    case MatchPending = 'match_pending';
    case Changed = 'changed';
    case FirstUse = 'first_use';
    case UnmatchedVendor = 'unmatched_vendor';
    case NoAccountExtracted = 'no_account_extracted';
    case Blocklisted = 'blocklisted';
    case OtherVendorAccount = 'other_vendor_account';
}
