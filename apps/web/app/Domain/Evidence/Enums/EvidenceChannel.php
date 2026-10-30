<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum EvidenceChannel: string
{
    use EnumHelpers;

    case Web = 'web';
    case Telegram = 'telegram';
    case System = 'system';
    case Worker = 'worker';
}
