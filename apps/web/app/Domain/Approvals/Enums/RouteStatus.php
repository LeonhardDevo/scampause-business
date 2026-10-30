<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum RouteStatus: string
{
    use EnumHelpers;

    case Active = 'active';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Superseded = 'superseded';
}
