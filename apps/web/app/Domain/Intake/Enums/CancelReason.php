<?php

declare(strict_types=1);

namespace App\Domain\Intake\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum CancelReason: string
{
    use EnumHelpers;

    case Withdrawn = 'withdrawn';
    case DuplicateUpload = 'duplicate_upload';
    case EnteredInError = 'entered_in_error';
    case Other = 'other';
}
