<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum DecisionChannel: string
{
    use EnumHelpers;

    case Web = 'web';
    case Telegram = 'telegram';
}
