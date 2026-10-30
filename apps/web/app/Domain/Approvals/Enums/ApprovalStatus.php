<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum ApprovalStatus: string
{
    use EnumHelpers;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Reassigned = 'reassigned';
}
