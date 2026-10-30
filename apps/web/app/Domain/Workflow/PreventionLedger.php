<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

use App\Domain\Intake\Enums\DecisionReason;
use App\Domain\Intake\Enums\PreventedCategory;
use App\Domain\Intake\Models\PaymentRequest;

final class PreventionLedger
{
    public static function category(?DecisionReason $reason, bool $verificationDenied = false): ?PreventedCategory
    {
        if ($verificationDenied) {
            return PreventedCategory::Fraud;
        }

        return match ($reason) {
            DecisionReason::FraudConfirmed, DecisionReason::DetailsMismatch, DecisionReason::NotOrdered => PreventedCategory::Fraud,
            DecisionReason::Duplicate => PreventedCategory::Duplicate,
            default => null,
        };
    }

    public static function apply(PaymentRequest $request, ?DecisionReason $reason, bool $verificationDenied = false): ?PreventedCategory
    {
        $category = self::category($reason, $verificationDenied);
        $amount = $request->getAttribute('amount_base_minor');

        $request->forceFill([
            'prevented_category' => $category,
            'prevented_amount_base_minor' => $category !== null && is_int($amount) ? $amount : null,
        ]);

        return $category;
    }
}
