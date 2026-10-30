<?php

declare(strict_types=1);

namespace App\Domain\Intake\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum EmployeeResponse: string
{
    use EnumHelpers;

    case ItWasMe = 'it_was_me';
    case NotMe = 'not_me';
}
