<?php

declare(strict_types=1);

namespace App\Domain\Intake\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum FileRole: string
{
    use EnumHelpers;

    case Original = 'original';
    case Attachment = 'attachment';
    case Verification = 'verification';
    case Preview = 'preview';
}
