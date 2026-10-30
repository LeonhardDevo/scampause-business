<?php

declare(strict_types=1);

namespace App\Domain\Intake\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum AuthResult: string
{
    use EnumHelpers;

    case Pass = 'pass';
    case Fail = 'fail';
    case Softfail = 'softfail';
    case Neutral = 'neutral';
    case None = 'none';
    case Temperror = 'temperror';
    case Permerror = 'permerror';
}
