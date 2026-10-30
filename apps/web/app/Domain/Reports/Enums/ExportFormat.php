<?php

declare(strict_types=1);

namespace App\Domain\Reports\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum ExportFormat: string
{
    use EnumHelpers;

    case Pdf = 'pdf';
    case Csv = 'csv';
    case Json = 'json';
}
