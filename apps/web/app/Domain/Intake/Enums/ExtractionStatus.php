<?php

declare(strict_types=1);

namespace App\Domain\Intake\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum ExtractionStatus: string
{
    use EnumHelpers;

    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Failed = 'failed';
}
