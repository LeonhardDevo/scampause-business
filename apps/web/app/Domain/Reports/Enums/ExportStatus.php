<?php

declare(strict_types=1);

namespace App\Domain\Reports\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum ExportStatus: string
{
    use EnumHelpers;

    case Queued = 'queued';
    case Generating = 'generating';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';
}
