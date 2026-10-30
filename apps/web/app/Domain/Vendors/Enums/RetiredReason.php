<?php

declare(strict_types=1);

namespace App\Domain\Vendors\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum RetiredReason: string
{
    use EnumHelpers;

    case Replaced = 'replaced';
    case Closed = 'closed';
    case Fraud = 'fraud';
    case EnteredInError = 'entered_in_error';
}
