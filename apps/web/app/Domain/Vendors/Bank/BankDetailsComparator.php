<?php

declare(strict_types=1);

namespace App\Domain\Vendors\Bank;

use App\Domain\Intake\Enums\BankComparisonOutcome as Outcome;
use App\Domain\Support\EditDistance;
use App\Domain\Support\NameSimilarity;
use App\Domain\Vendors\Enums\BankAccountStatus;
use Carbon\CarbonImmutable;

final class BankDetailsComparator
{
    /**
     * @param  list<AccountSnapshot>  $vendorAccounts
     * @param  array<string, int>  $otherVendorVerified
     * @param  list<string|null>  $vendorNames
     */
    public static function compare(
        ?PayeeSnapshot $payee,
        bool $blocklisted,
        ?int $vendorId,
        array $vendorAccounts,
        array $otherVendorVerified,
        array $vendorNames,
        CarbonImmutable $now,
    ): ComparisonResult {
        $verified = array_values(array_filter($vendorAccounts, static fn (AccountSnapshot $a): bool => $a->status === BankAccountStatus::Verified));
        $previous = self::latestVerified($verified);
        $details = self::details($payee, $previous, $vendorNames, $now);

        if ($payee === null) {
            return new ComparisonResult(Outcome::NoAccountExtracted, $previous?->id, null, $details);
        }

        if ($blocklisted) {
            return new ComparisonResult(Outcome::Blocklisted, $previous?->id, null, $details);
        }

        if ($vendorId === null) {
            if (array_key_exists($payee->fingerprint, $otherVendorVerified)) {
                $details['other_vendor_id'] = $otherVendorVerified[$payee->fingerprint];
            }

            return new ComparisonResult(Outcome::UnmatchedVendor, null, null, $details);
        }

        foreach ($verified as $account) {
            if ($account->fingerprint === $payee->fingerprint) {
                return new ComparisonResult(Outcome::MatchVerified, $previous?->id, $account->id, $details);
            }
        }

        if (array_key_exists($payee->fingerprint, $otherVendorVerified)) {
            $details['other_vendor_id'] = $otherVendorVerified[$payee->fingerprint];

            return new ComparisonResult(Outcome::OtherVendorAccount, $previous?->id, null, $details);
        }

        foreach ($vendorAccounts as $account) {
            if ($account->status === BankAccountStatus::PendingVerification && $account->fingerprint === $payee->fingerprint) {
                return new ComparisonResult(Outcome::MatchPending, $previous?->id, $account->id, $details);
            }
        }

        if ($previous === null) {
            return new ComparisonResult(Outcome::FirstUse, null, null, $details);
        }

        return new ComparisonResult(Outcome::Changed, $previous->id, null, $details);
    }

    /**
     * @param  list<AccountSnapshot>  $verified
     */
    public static function latestVerified(array $verified): ?AccountSnapshot
    {
        $latest = null;

        foreach ($verified as $account) {
            if ($latest === null
                || ($account->validFrom?->getTimestamp() ?? 0) > ($latest->validFrom?->getTimestamp() ?? 0)
                || (($account->validFrom?->getTimestamp() ?? 0) === ($latest->validFrom?->getTimestamp() ?? 0) && $account->id > $latest->id)) {
                $latest = $account;
            }
        }

        return $latest;
    }

    /**
     * @param  list<string|null>  $vendorNames
     * @return array{holder_similarity: int|null, account_edit_distance: int|null, same_bank: bool|null, same_country: bool|null, previous_last4: string|null, new_last4: string|null, previous_country: string|null, new_country: string|null, other_vendor_id: int|null, verified_change_days_ago: int|null}
     */
    private static function details(?PayeeSnapshot $payee, ?AccountSnapshot $previous, array $vendorNames, CarbonImmutable $now): array
    {
        $holderSimilarity = null;

        if ($payee?->holder !== null && array_filter($vendorNames) !== []) {
            $holderSimilarity = NameSimilarity::best($payee->holder, $vendorNames);
        }

        $sameBank = null;

        if ($payee !== null && $previous !== null) {
            if ($payee->bic !== null && $previous->bic !== null) {
                $sameBank = substr($payee->bic, 0, 8) === substr($previous->bic, 0, 8);
            } elseif ($payee->routingCode !== null && $payee->routingCode !== '' && $previous->routingCode !== null && $previous->routingCode !== '') {
                $sameBank = $payee->routingCode === $previous->routingCode;
            }
        }

        $sameCountry = $payee?->bankCountry !== null && $previous?->bankCountry !== null
            ? $payee->bankCountry === $previous->bankCountry
            : null;

        return [
            'holder_similarity' => $holderSimilarity,
            'account_edit_distance' => $payee !== null && $previous !== null
                ? EditDistance::damerauLevenshtein($previous->accountNumber, $payee->accountNumber)
                : null,
            'same_bank' => $sameBank,
            'same_country' => $sameCountry,
            'previous_last4' => $previous?->last4,
            'new_last4' => $payee?->last4,
            'previous_country' => $previous?->bankCountry,
            'new_country' => $payee?->bankCountry,
            'other_vendor_id' => null,
            'verified_change_days_ago' => $previous?->validFrom !== null
                ? (int) floor($previous->validFrom->diffInDays($now, true))
                : null,
        ];
    }
}
