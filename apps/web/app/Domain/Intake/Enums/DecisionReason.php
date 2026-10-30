<?php

declare(strict_types=1);

namespace App\Domain\Intake\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum DecisionReason: string
{
    use EnumHelpers;

    case FraudConfirmed = 'fraud_confirmed';
    case DetailsMismatch = 'details_mismatch';
    case NotOrdered = 'not_ordered';
    case Duplicate = 'duplicate';
    case PolicyViolation = 'policy_violation';
    case Other = 'other';
}
