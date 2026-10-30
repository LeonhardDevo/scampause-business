<?php

declare(strict_types=1);

namespace App\Domain\Intake\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum PreventedCategory: string
{
    use EnumHelpers;

    case Fraud = 'fraud';
    case Duplicate = 'duplicate';
}
