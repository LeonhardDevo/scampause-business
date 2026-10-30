<?php

declare(strict_types=1);

namespace App\Domain\Vendors\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum BankAccountSource: string
{
    use EnumHelpers;

    case Manual = 'manual';
    case Intake = 'intake';
    case Import = 'import';
}
