<?php

declare(strict_types=1);

namespace App\Domain\Risk\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum SignalCategory: string
{
    use EnumHelpers;

    case Bank = 'bank';
    case Sender = 'sender';
    case Content = 'content';
    case Amount = 'amount';
    case Workflow = 'workflow';
    case Qr = 'qr';
}
