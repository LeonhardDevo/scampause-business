<?php

declare(strict_types=1);

namespace App\Domain\Verification\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum VerificationOutcome: string
{
    use EnumHelpers;

    case Confirmed = 'confirmed';
    case Denied = 'denied';
    case Unreachable = 'unreachable';
    case Inconclusive = 'inconclusive';
}
