<?php

declare(strict_types=1);

namespace App\Domain\Risk;

use App\Domain\Evidence\CanonicalJson;
use App\Domain\Intake\Enums\BankComparisonOutcome as Outcome;
use App\Domain\Intake\Enums\EmployeeResponse;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Money\DecimalMul;
use App\Domain\Risk\Enums\RiskLevel;
use App\Domain\Risk\Enums\SignalCategory;
use App\Domain\Risk\Enums\SignalSeverity;

final class RiskEngine
{
    public const array CATEGORIES = [
        'BANK_DETAILS_CHANGED' => 'bank',
        'BANK_ACCOUNT_BLOCKLISTED' => 'bank',
        'BANK_ACCOUNT_SEEN_OTHER_VENDOR' => 'bank',
        'BANK_ACCOUNT_UNVERIFIED' => 'bank',
        'BANK_ACCOUNT_FIRST_USE' => 'bank',
        'BANK_COUNTRY_CHANGED' => 'bank',
        'BANK_HOLDER_MISMATCH' => 'bank',
        'BANK_RECENT_CHANGE' => 'bank',
        'INVALID_ACCOUNT_CHECKSUM' => 'bank',
        'PAYROLL_ACCOUNT_CHANGE' => 'bank',
        'EMPLOYEE_DENIED_CHANGE' => 'bank',
        'SENDER_DOMAIN_LOOKALIKE' => 'sender',
        'SENDER_DOMAIN_UNKNOWN_FOR_VENDOR' => 'sender',
        'REPLY_TO_MISMATCH' => 'sender',
        'FREE_EMAIL_PROVIDER' => 'sender',
        'DISPLAY_NAME_IMPERSONATION' => 'sender',
        'EMAIL_AUTH_FAILED' => 'sender',
        'PUNYCODE_DOMAIN' => 'sender',
        'DOMAIN_RECENTLY_REGISTERED' => 'sender',
        'DOMAIN_YOUNG' => 'sender',
        'DOMAIN_NO_DMARC' => 'sender',
        'DOMAIN_INTEL_UNAVAILABLE' => 'sender',
        'URGENCY_LANGUAGE' => 'content',
        'SECRECY_LANGUAGE' => 'content',
        'CALLBACK_DISCOURAGED' => 'content',
        'AUTHORITY_PRESSURE' => 'content',
        'CHANGE_REQUEST_LANGUAGE' => 'content',
        'UNUSUAL_PAYMENT_METHOD' => 'content',
        'PHONE_NOT_IN_MASTER' => 'content',
        'NEW_VENDOR' => 'workflow',
        'DUPLICATE_INVOICE' => 'amount',
        'DUPLICATE_SUSPECT' => 'amount',
        'AMOUNT_ANOMALY' => 'amount',
        'AMOUNT_ANOMALY_SEVERE' => 'amount',
        'ROUND_AMOUNT_WIRE' => 'amount',
        'CURRENCY_CHANGED' => 'amount',
        'DUE_DATE_PRESSURE' => 'workflow',
        'QR_PAYEE_MISMATCH' => 'qr',
        'QR_ONLY_DETAILS' => 'qr',
        'FX_RATE_MISSING' => 'workflow',
        'EXTRACTION_LOW_CONFIDENCE' => 'workflow',
        'CALLBACK_CONTACT_RECENTLY_CHANGED' => 'workflow',
        'CALLBACK_PHONE_SHARED' => 'workflow',
        'PIPELINE_INCOMPLETE' => 'workflow',
        'OCR_LANGUAGE_MISSING' => 'workflow',
    ];

    public static function evaluate(RiskFacts $facts): RiskResult
    {
        $hits = [...self::bankHits($facts), ...$facts->senderHits, ...$facts->contentHits, ...self::otherHits($facts)];
        $signals = self::score($hits);
        $content = 0;
        $other = 0;

        foreach ($signals as $signal) {
            if ($signal->category === SignalCategory::Content) {
                $content += $signal->points;
            } else {
                $other += $signal->points;
            }
        }

        $raw = $other + min(self::contentCap(), $content);
        $score = min(100, $raw);
        $codes = array_map(static fn (ScoredSignal $signal): string => $signal->code, $signals);
        $floorSignal = null;

        foreach (self::floors() as $floor) {
            $matched = self::floorMatches($floor, $codes);

            if ($matched !== null && $score <= $floor['score']) {
                $score = max($score, $floor['score']);
            }

            if ($matched !== null) {
                $floorSignal = $matched;
            }
        }

        $level = RiskLevel::fromScore($score);
        [$required, $reasons] = self::verification($facts, $codes, $level);
        $rulesVersion = (string) config('scampause.rules_version');

        $digest = hash('sha256', CanonicalJson::object([
            'request' => $facts->requestDigest === [] ? new \stdClass : $facts->requestDigest,
            'comparison' => [
                'outcome' => $facts->comparisonOutcome?->value,
                'details' => self::scalarDetails($facts->comparisonDetails),
            ],
            'sender' => array_map(static fn (SignalHit $hit): string => $hit->code, $facts->senderHits),
            'signals' => array_map(static fn (ScoredSignal $signal): array => ['code' => $signal->code, 'points' => $signal->points], $signals),
            'rules_version' => $rulesVersion,
            'lexicon_version' => (string) config('scampause_lexicon.version'),
        ]));

        return new RiskResult($signals, $score, $level, $raw, $floorSignal, $required, $reasons, $rulesVersion, $digest);
    }

    /** @return list<SignalHit> */
    public static function bankHits(RiskFacts $facts): array
    {
        $hits = [];
        $details = $facts->comparisonDetails;
        $outcome = $facts->comparisonOutcome;
        $last4 = ['previous_last4' => self::str($details['previous_last4'] ?? null), 'new_last4' => self::str($details['new_last4'] ?? null)];

        if ($outcome === Outcome::Changed) {
            $hits[] = new SignalHit('BANK_DETAILS_CHANGED', $last4);

            if (($details['same_country'] ?? null) === false) {
                $hits[] = new SignalHit('BANK_COUNTRY_CHANGED', [
                    'previous_country' => self::str($details['previous_country'] ?? null),
                    'new_country' => self::str($details['new_country'] ?? null),
                ]);
            }

            $daysAgo = $details['verified_change_days_ago'] ?? null;

            if (is_int($daysAgo) && $daysAgo <= (int) config('scampause.risk.recent_change_days', 30)) {
                $hits[] = new SignalHit('BANK_RECENT_CHANGE', ['days_ago' => $daysAgo]);
            }
        }

        match ($outcome) {
            Outcome::Blocklisted => $hits[] = new SignalHit('BANK_ACCOUNT_BLOCKLISTED', ['last4' => $last4['new_last4']]),
            Outcome::OtherVendorAccount => $hits[] = new SignalHit('BANK_ACCOUNT_SEEN_OTHER_VENDOR', ['last4' => $last4['new_last4']]),
            Outcome::MatchPending => $hits[] = new SignalHit('BANK_ACCOUNT_UNVERIFIED', ['last4' => $last4['new_last4']]),
            Outcome::FirstUse => $hits[] = new SignalHit('BANK_ACCOUNT_FIRST_USE', ['last4' => $last4['new_last4']]),
            default => null,
        };

        $similarity = $details['holder_similarity'] ?? null;

        if ($facts->payeeHolderPresent && is_int($similarity) && $similarity < (int) config('scampause.risk.holder_similarity_threshold', 70)) {
            $hits[] = new SignalHit('BANK_HOLDER_MISMATCH', ['similarity' => $similarity]);
        }

        if ($facts->payeeChecksumValid === false) {
            $hits[] = new SignalHit('INVALID_ACCOUNT_CHECKSUM', ['last4' => $last4['new_last4']]);
        }

        if ($facts->kind === PaymentRequestKind::PayrollChange) {
            $hits[] = new SignalHit('PAYROLL_ACCOUNT_CHANGE', ['last4' => $last4['new_last4']]);
        }

        if ($facts->employeeResponse === EmployeeResponse::NotMe) {
            $hits[] = new SignalHit('EMPLOYEE_DENIED_CHANGE', []);
        }

        return $hits;
    }

    /** @return list<SignalHit> */
    public static function otherHits(RiskFacts $facts): array
    {
        $hits = [];

        if ($facts->messagePhoneLast4 !== [] && ! $facts->messagePhoneInMaster) {
            $hits[] = new SignalHit('PHONE_NOT_IN_MASTER', ['phones_last4' => array_slice($facts->messagePhoneLast4, 0, 5), 'advice' => 'do_not_use_these_numbers']);
        }

        if (! $facts->vendorMatched
            || ($facts->vendorAgeDays !== null && $facts->vendorAgeDays <= (int) config('scampause.risk.new_vendor_days', 30) && ! $facts->vendorHasPaid)) {
            $hits[] = new SignalHit('NEW_VENDOR', ['matched' => $facts->vendorMatched]);
        }

        if ($facts->duplicateInvoiceOf !== null) {
            $hits[] = new SignalHit('DUPLICATE_INVOICE', ['reference' => $facts->duplicateInvoiceOf]);
        } elseif ($facts->duplicateSuspectOf !== null) {
            $hits[] = new SignalHit('DUPLICATE_SUSPECT', ['reference' => $facts->duplicateSuspectOf]);
        }

        $anomaly = self::anomaly($facts);

        if ($anomaly !== null) {
            $hits[] = $anomaly;
        }

        if ($facts->kind === PaymentRequestKind::UrgentWire && $facts->amountMinor !== null && $facts->amountMinor > 0 && $facts->amountMultipleOfThousand) {
            $hits[] = new SignalHit('ROUND_AMOUNT_WIRE', []);
        }

        if ($facts->vendorDefaultCurrency !== null && $facts->currency !== null && $facts->vendorDefaultCurrency !== $facts->currency) {
            $hits[] = new SignalHit('CURRENCY_CHANGED', ['expected' => $facts->vendorDefaultCurrency, 'actual' => $facts->currency]);
        }

        if ($facts->dueBusinessDays !== null && $facts->dueBusinessDays <= (int) config('scampause.risk.due_pressure_business_days', 2)) {
            $hits[] = new SignalHit('DUE_DATE_PRESSURE', ['business_days' => $facts->dueBusinessDays]);
        }

        if ($facts->qrMismatch) {
            $hits[] = new SignalHit('QR_PAYEE_MISMATCH', $facts->qrMismatchEvidence);
        }

        if ($facts->qrOnly) {
            $hits[] = new SignalHit('QR_ONLY_DETAILS', []);
        }

        if ($facts->fxMissing) {
            $hits[] = new SignalHit('FX_RATE_MISSING', ['currency' => $facts->currency]);
        }

        if ($facts->lowConfidenceFields !== [] || $facts->ocrLanguageMissing) {
            $hits[] = new SignalHit('EXTRACTION_LOW_CONFIDENCE', ['fields' => $facts->lowConfidenceFields]);
        }

        if ($facts->callbackRecentlyChanged !== null) {
            $hits[] = new SignalHit('CALLBACK_CONTACT_RECENTLY_CHANGED', $facts->callbackRecentlyChanged);
        }

        if ($facts->callbackPhoneShared !== null) {
            $hits[] = new SignalHit('CALLBACK_PHONE_SHARED', $facts->callbackPhoneShared);
        }

        if ($facts->pipelineIncomplete) {
            $hits[] = new SignalHit('PIPELINE_INCOMPLETE', []);
        }

        if ($facts->ocrLanguageMissing) {
            $hits[] = new SignalHit('OCR_LANGUAGE_MISSING', ['missing' => ['rus']]);
        }

        return $hits;
    }

    /**
     * @param  list<int>  $values
     */
    public static function median(array $values): ?int
    {
        sort($values);
        $count = count($values);

        if ($count === 0) {
            return null;
        }

        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] : DecimalMul::divideHalfEven($values[$middle - 1] + $values[$middle], 2);
    }

    public static function categoryOf(string $code): SignalCategory
    {
        return SignalCategory::from(self::CATEGORIES[$code] ?? 'workflow');
    }

    public static function weight(string $code): int
    {
        /** @var array<string, int> $weights */
        $weights = config('scampause.risk.weights', []);

        return (int) ($weights[$code] ?? 0);
    }

    /**
     * @param  list<SignalHit>  $hits
     * @return list<ScoredSignal>
     */
    private static function score(array $hits): array
    {
        $signals = [];
        $seen = [];

        foreach ($hits as $hit) {
            if (isset($seen[$hit->code])) {
                continue;
            }

            $seen[$hit->code] = true;
            $points = self::weight($hit->code);
            $signals[] = new ScoredSignal($hit->code, self::categoryOf($hit->code), SignalSeverity::fromPoints($points), $points, $hit->evidence);
        }

        usort($signals, static fn (ScoredSignal $a, ScoredSignal $b): int => [$b->points, $a->code] <=> [$a->points, $b->code]);

        return $signals;
    }

    private static function anomaly(RiskFacts $facts): ?SignalHit
    {
        if ($facts->amountBaseMinor === null || $facts->historyCount < (int) config('scampause.risk.anomaly_min_history', 3)) {
            return null;
        }

        $sample = array_slice($facts->historyBaseAmounts, 0, (int) config('scampause.risk.anomaly_sample', 6));
        $median = self::median($sample);

        if ($median === null || $median <= 0) {
            return null;
        }

        if ($facts->amountBaseMinor > 5 * $median) {
            return new SignalHit('AMOUNT_ANOMALY_SEVERE', ['median_minor' => $median, 'ratio_x10' => intdiv($facts->amountBaseMinor * 10, $median)]);
        }

        if ($facts->amountBaseMinor > 2 * $median) {
            return new SignalHit('AMOUNT_ANOMALY', ['median_minor' => $median, 'ratio_x10' => intdiv($facts->amountBaseMinor * 10, $median)]);
        }

        return null;
    }

    /**
     * @param  list<string>  $codes
     * @return array{0: bool, 1: list<string>}
     */
    private static function verification(RiskFacts $facts, array $codes, RiskLevel $level): array
    {
        $reasons = [];
        /** @var list<string> $verificationSignals */
        $verificationSignals = config('scampause.risk.verification_signals', []);
        /** @var list<string> $forcing */
        $forcing = config('scampause.risk.verification_forcing_signals', []);
        /** @var list<string> $kinds */
        $kinds = config('scampause.risk.verification_kinds', []);

        foreach ($codes as $code) {
            if (in_array($code, $verificationSignals, true) || in_array($code, $forcing, true)) {
                $reasons[] = $code;
            }
        }

        if (in_array('BANK_ACCOUNT_FIRST_USE', $codes, true)
            && ($facts->amountBaseMinor === null || $facts->amountBaseMinor >= $facts->verificationThresholdMinor)) {
            $reasons[] = 'BANK_ACCOUNT_FIRST_USE';
        }

        if ($level->atLeast(RiskLevel::High)) {
            $reasons[] = 'level:'.$level->value;
        }

        if (in_array($facts->kind->value, $kinds, true)) {
            $reasons[] = 'kind:'.$facts->kind->value;
        }

        if ($facts->policyRequiresVerification) {
            foreach ($facts->policyReasons === [] ? ['rule:policy'] : $facts->policyReasons as $reason) {
                $reasons[] = $reason;
            }
        }

        $reasons = array_values(array_unique($reasons));

        return [$reasons !== [], $reasons];
    }

    /**
     * @return list<array{score: int, any?: list<string>, all_of_groups?: list<list<string>>}>
     */
    private static function floors(): array
    {
        /** @var list<array{score: int, any?: list<string>, all_of_groups?: list<list<string>>}> $floors */
        $floors = config('scampause.risk.floors', []);
        usort($floors, static fn (array $a, array $b): int => $a['score'] <=> $b['score']);

        return $floors;
    }

    /**
     * @param  array{score: int, any?: list<string>, all_of_groups?: list<list<string>>}  $floor
     * @param  list<string>  $codes
     */
    private static function floorMatches(array $floor, array $codes): ?string
    {
        if (isset($floor['any'])) {
            foreach ($floor['any'] as $code) {
                if (in_array($code, $codes, true)) {
                    return $code;
                }
            }

            return null;
        }

        $first = null;

        foreach ($floor['all_of_groups'] ?? [] as $group) {
            $found = array_values(array_intersect($group, $codes));

            if ($found === []) {
                return null;
            }

            $first ??= $found[0];
        }

        return $first;
    }

    private static function contentCap(): int
    {
        return (int) config('scampause.risk.content_cap', 40);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, string|int|bool|null>|\stdClass
     */
    private static function scalarDetails(array $details): array|\stdClass
    {
        $out = [];

        foreach ($details as $key => $value) {
            if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {
                $out[$key] = $value;
            }
        }

        return $out === [] ? new \stdClass : $out;
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
