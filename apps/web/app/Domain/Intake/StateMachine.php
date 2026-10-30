<?php

declare(strict_types=1);

namespace App\Domain\Intake;

use App\Domain\Intake\Enums\PaymentRequestStatus as S;
use App\Domain\Intake\Exceptions\InvalidTransition;

final class StateMachine
{
    /** @return array<string, list<S>> */
    public static function allowedTransitions(): array
    {
        return [
            S::Received->value => [S::Processing, S::Review, S::Cancelled],
            S::Processing->value => [S::Review, S::Cancelled],
            S::Review->value => [S::Review, S::Processing, S::AwaitingVerification, S::AwaitingApproval, S::Rejected, S::Cancelled],
            S::AwaitingVerification->value => [S::AwaitingVerification, S::AwaitingApproval, S::Rejected, S::Cancelled],
            S::AwaitingApproval->value => [S::Approved, S::Rejected, S::Expired, S::AwaitingVerification, S::Review, S::Cancelled],
            S::Expired->value => [S::AwaitingApproval, S::AwaitingVerification, S::Cancelled],
            S::Approved->value => [S::Paid, S::Rejected],
            S::Paid->value => [],
            S::Rejected->value => [],
            S::Cancelled->value => [],
        ];
    }

    public static function can(S $from, S $to): bool
    {
        return in_array($to, self::allowedTransitions()[$from->value] ?? [], true);
    }

    public static function assert(S $from, S $to): void
    {
        if (! self::can($from, $to)) {
            throw InvalidTransition::between($from, $to);
        }
    }
}
