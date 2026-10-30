<?php

declare(strict_types=1);

namespace App\Domain\Intake\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum PaymentRequestStatus: string
{
    use EnumHelpers;

    case Received = 'received';
    case Processing = 'processing';
    case Review = 'review';
    case AwaitingVerification = 'awaiting_verification';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Rejected, self::Cancelled], true);
    }

    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    public function isDecided(): bool
    {
        return in_array($this, self::decided(), true);
    }

    public function isPipelineRunning(): bool
    {
        return $this === self::Received || $this === self::Processing;
    }

    /** @return list<self> */
    public static function open(): array
    {
        return [self::Received, self::Processing, self::Review, self::AwaitingVerification, self::AwaitingApproval];
    }

    /** @return list<self> */
    public static function decided(): array
    {
        return [self::Approved, self::Paid, self::Rejected, self::Expired, self::Cancelled];
    }
}
