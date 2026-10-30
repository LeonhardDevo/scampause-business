<?php

declare(strict_types=1);

namespace App\Domain\Vendors\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum VendorStatus: string
{
    use EnumHelpers;

    case Active = 'active';
    case OnHold = 'on_hold';
    case Archived = 'archived';
}
