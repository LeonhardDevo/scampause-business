<?php

declare(strict_types=1);

use App\Domain\Intake\Enums\BankComparisonOutcome as O;
use App\Domain\Intake\Enums\EmployeeResponse;
use App\Domain\Intake\Enums\PaymentRequestKind as K;
use App\Domain\Risk\Enums\RiskLevel;
use App\Domain\Risk\Enums\SignalSeverity;
use App\Domain\Risk\RiskEngine;
use App\Domain\Risk\RiskFacts;
use App\Domain\Risk\SignalHit;

/**
 * @param  array<string, mixed>  $overrides
 */
function spFacts(array $overrides = []): RiskFacts
{
    return new RiskFacts(...array_merge([
        'vendorMatched' => true,
        'vendorAgeDays' => 400,
        'vendorHasPaid' => true,
        'comparisonOutcome' => O::MatchVerified,
        'amountMinor' => 50_000,
        'currency' => 'USD',
        'amountBaseMinor' => 50_000,
    ], $overrides));
}

it('raises every rule-based signal from its facts', function (array $overrides, string $code): void {
    expect(RiskEngine::evaluate(spFacts($overrides))->codes())->toContain($code);
})->with([
    'changed' => [['comparisonOutcome' => O::Changed, 'comparisonDetails' => ['previous_last4' => '1111', 'new_last4' => '2222']], 'BANK_DETAILS_CHANGED'],
    'blocklisted' => [['comparisonOutcome' => O::Blocklisted], 'BANK_ACCOUNT_BLOCKLISTED'],
    'other vendor' => [['comparisonOutcome' => O::OtherVendorAccount], 'BANK_ACCOUNT_SEEN_OTHER_VENDOR'],
    'unverified' => [['comparisonOutcome' => O::MatchPending], 'BANK_ACCOUNT_UNVERIFIED'],
    'first use' => [['comparisonOutcome' => O::FirstUse], 'BANK_ACCOUNT_FIRST_USE'],
    'country changed' => [['comparisonOutcome' => O::Changed, 'comparisonDetails' => ['same_country' => false]], 'BANK_COUNTRY_CHANGED'],
    'holder mismatch' => [['payeeHolderPresent' => true, 'comparisonDetails' => ['holder_similarity' => 40]], 'BANK_HOLDER_MISMATCH'],
    'recent change' => [['comparisonOutcome' => O::Changed, 'comparisonDetails' => ['verified_change_days_ago' => 12]], 'BANK_RECENT_CHANGE'],
    'checksum' => [['payeeChecksumValid' => false], 'INVALID_ACCOUNT_CHECKSUM'],
    'payroll' => [['kind' => K::PayrollChange], 'PAYROLL_ACCOUNT_CHANGE'],
    'employee denied' => [['employeeResponse' => EmployeeResponse::NotMe], 'EMPLOYEE_DENIED_CHANGE'],
    'sender hit passthrough' => [['senderHits' => [new SignalHit('SENDER_DOMAIN_LOOKALIKE', ['domain' => 'x.com'])]], 'SENDER_DOMAIN_LOOKALIKE'],
    'content hit passthrough' => [['contentHits' => [new SignalHit('URGENCY_LANGUAGE', ['phrases' => ['urgent']])]], 'URGENCY_LANGUAGE'],
    'phone not in master' => [['messagePhoneLast4' => ['0199']], 'PHONE_NOT_IN_MASTER'],
    'new vendor unknown' => [['vendorMatched' => false], 'NEW_VENDOR'],
    'new vendor recent' => [['vendorAgeDays' => 5, 'vendorHasPaid' => false], 'NEW_VENDOR'],
    'duplicate invoice' => [['duplicateInvoiceOf' => 'SP-00007'], 'DUPLICATE_INVOICE'],
    'duplicate suspect' => [['duplicateSuspectOf' => 'SP-00008'], 'DUPLICATE_SUSPECT'],
    'anomaly' => [['historyCount' => 3, 'historyBaseAmounts' => [10_000, 10_000, 10_000], 'amountBaseMinor' => 30_000], 'AMOUNT_ANOMALY'],
    'severe anomaly' => [['historyCount' => 4, 'historyBaseAmounts' => [10_000, 10_000, 12_000, 8_000], 'amountBaseMinor' => 60_000], 'AMOUNT_ANOMALY_SEVERE'],
    'round wire' => [['kind' => K::UrgentWire, 'amountMinor' => 1_500_000_00, 'amountMultipleOfThousand' => true], 'ROUND_AMOUNT_WIRE'],
    'currency changed' => [['vendorDefaultCurrency' => 'EUR'], 'CURRENCY_CHANGED'],
    'due pressure' => [['dueBusinessDays' => 1], 'DUE_DATE_PRESSURE'],
    'past due' => [['dueBusinessDays' => -1], 'DUE_DATE_PRESSURE'],
    'qr mismatch' => [['qrMismatch' => true, 'qrMismatchEvidence' => ['reason' => 'account_differs']], 'QR_PAYEE_MISMATCH'],
    'qr only' => [['qrOnly' => true], 'QR_ONLY_DETAILS'],
    'fx missing' => [['fxMissing' => true, 'amountBaseMinor' => null], 'FX_RATE_MISSING'],
    'low confidence' => [['lowConfidenceFields' => ['amount']], 'EXTRACTION_LOW_CONFIDENCE'],
    'callback changed' => [['callbackRecentlyChanged' => ['last4' => '0142', 'changed_at' => '2026-10-20T10:00:00Z']], 'CALLBACK_CONTACT_RECENTLY_CHANGED'],
    'callback shared' => [['callbackPhoneShared' => ['last4' => '0142', 'other_vendors' => 1]], 'CALLBACK_PHONE_SHARED'],
    'pipeline incomplete' => [['pipelineIncomplete' => true], 'PIPELINE_INCOMPLETE'],
    'ocr language missing' => [['ocrLanguageMissing' => true], 'OCR_LANGUAGE_MISSING'],
    'ocr forces low confidence' => [['ocrLanguageMissing' => true], 'EXTRACTION_LOW_CONFIDENCE'],
]);

it('stays silent on a clean, known payment', function (): void {
    $result = RiskEngine::evaluate(spFacts());

    expect($result->codes())->toBe([])
        ->and($result->score)->toBe(0)
        ->and($result->level)->toBe(RiskLevel::Low)
        ->and($result->verificationRequired)->toBeFalse();
});

it('does not raise anomaly or recent-change signals outside their thresholds', function (array $overrides, string $code): void {
    expect(RiskEngine::evaluate(spFacts($overrides))->codes())->not->toContain($code);
})->with([
    [['historyCount' => 2, 'historyBaseAmounts' => [10_000, 10_000], 'amountBaseMinor' => 90_000], 'AMOUNT_ANOMALY'],
    [['historyCount' => 3, 'historyBaseAmounts' => [10_000, 10_000, 10_000], 'amountBaseMinor' => 20_000], 'AMOUNT_ANOMALY'],
    [['comparisonOutcome' => O::Changed, 'comparisonDetails' => ['verified_change_days_ago' => 31]], 'BANK_RECENT_CHANGE'],
    [['payeeHolderPresent' => false, 'comparisonDetails' => ['holder_similarity' => 10]], 'BANK_HOLDER_MISMATCH'],
    [['messagePhoneLast4' => ['0199'], 'messagePhoneInMaster' => true], 'PHONE_NOT_IN_MASTER'],
    [['duplicateInvoiceOf' => 'SP-1', 'duplicateSuspectOf' => 'SP-2'], 'DUPLICATE_SUSPECT'],
    [['dueBusinessDays' => 3], 'DUE_DATE_PRESSURE'],
    [['kind' => K::Invoice, 'amountMultipleOfThousand' => true], 'ROUND_AMOUNT_WIRE'],
]);

it('caps content points at forty', function (): void {
    $content = [
        new SignalHit('URGENCY_LANGUAGE'), new SignalHit('SECRECY_LANGUAGE'), new SignalHit('CALLBACK_DISCOURAGED'),
        new SignalHit('AUTHORITY_PRESSURE'), new SignalHit('CHANGE_REQUEST_LANGUAGE'),
    ];
    $result = RiskEngine::evaluate(spFacts(['contentHits' => $content]));

    expect($result->rawPoints)->toBe(40)
        ->and($result->score)->toBe(40)
        ->and($result->level)->toBe(RiskLevel::Medium);
});

it('applies score floors', function (array $overrides, int $minimum, string $floor): void {
    $result = RiskEngine::evaluate(spFacts($overrides));

    expect($result->score)->toBeGreaterThanOrEqual($minimum)
        ->and($result->floorSignal)->toBe($floor);
})->with([
    'qr mismatch floor 50' => [['qrMismatch' => true], 50, 'QR_PAYEE_MISMATCH'],
    'lookalike with change language floor 75' => [[
        'senderHits' => [new SignalHit('SENDER_DOMAIN_LOOKALIKE')],
        'contentHits' => [new SignalHit('CHANGE_REQUEST_LANGUAGE')],
    ], 75, 'SENDER_DOMAIN_LOOKALIKE'],
    'blocklisted floor 90' => [['comparisonOutcome' => O::Blocklisted], 90, 'BANK_ACCOUNT_BLOCKLISTED'],
    'employee denial floor 90' => [['employeeResponse' => EmployeeResponse::NotMe], 90, 'EMPLOYEE_DENIED_CHANGE'],
]);

it('maps scores to levels at the boundaries', function (int $score, RiskLevel $level): void {
    expect(RiskLevel::fromScore($score))->toBe($level);
})->with([[0, RiskLevel::Low], [24, RiskLevel::Low], [25, RiskLevel::Medium], [49, RiskLevel::Medium], [50, RiskLevel::High], [74, RiskLevel::High], [75, RiskLevel::Critical], [100, RiskLevel::Critical]]);

it('maps points to severities', function (int $points, SignalSeverity $severity): void {
    expect(SignalSeverity::fromPoints($points))->toBe($severity);
})->with([[0, SignalSeverity::Info], [1, SignalSeverity::Low], [10, SignalSeverity::Low], [11, SignalSeverity::Medium], [20, SignalSeverity::Medium], [21, SignalSeverity::High], [35, SignalSeverity::High], [36, SignalSeverity::Critical]]);

it('caps the score at one hundred', function (): void {
    $result = RiskEngine::evaluate(spFacts([
        'comparisonOutcome' => O::Blocklisted,
        'senderHits' => [new SignalHit('SENDER_DOMAIN_LOOKALIKE'), new SignalHit('EMAIL_AUTH_FAILED')],
        'kind' => K::PayrollChange,
    ]));

    expect($result->rawPoints)->toBeGreaterThan(100)
        ->and($result->score)->toBe(100);
});

it('decides when verification is required and why', function (array $overrides, bool $required, array $reasons): void {
    $result = RiskEngine::evaluate(spFacts($overrides));

    expect($result->verificationRequired)->toBe($required)
        ->and($result->verificationReasons)->toBe($reasons);
})->with([
    'clean' => [[], false, []],
    'changed' => [['comparisonOutcome' => O::Changed], true, ['BANK_DETAILS_CHANGED', 'level:high']],
    'first use under threshold' => [['comparisonOutcome' => O::FirstUse, 'amountBaseMinor' => 50_000], false, []],
    'first use at threshold' => [['comparisonOutcome' => O::FirstUse, 'amountBaseMinor' => 100_000], true, ['BANK_ACCOUNT_FIRST_USE']],
    'first use unknown amount' => [['comparisonOutcome' => O::FirstUse, 'amountBaseMinor' => null], true, ['BANK_ACCOUNT_FIRST_USE']],
    'urgent wire kind' => [['kind' => K::UrgentWire], true, ['kind:urgent_wire']],
    'pipeline incomplete forces' => [['pipelineIncomplete' => true], true, ['PIPELINE_INCOMPLETE']],
    'policy' => [['policyRequiresVerification' => true, 'policyReasons' => ['rule:7']], true, ['rule:7']],
]);

it('produces the same digest and score for the same inputs', function (): void {
    $facts = spFacts(['comparisonOutcome' => O::Changed, 'requestDigest' => ['revision' => 2, 'amount_minor' => 1000]]);
    $first = RiskEngine::evaluate($facts);
    $second = RiskEngine::evaluate($facts);
    $other = RiskEngine::evaluate(spFacts(['comparisonOutcome' => O::Changed, 'requestDigest' => ['revision' => 3, 'amount_minor' => 1000]]));

    expect($first->inputsDigest)->toBe($second->inputsDigest)
        ->and($first->score)->toBe($second->score)
        ->and($first->inputsDigest)->not->toBe($other->inputsDigest)
        ->and($first->rulesVersion)->toBe('2026.10.2');
});

it('computes half-even medians', function (): void {
    expect(RiskEngine::median([3, 1, 2]))->toBe(2)
        ->and(RiskEngine::median([1, 2]))->toBe(2)
        ->and(RiskEngine::median([1, 4]))->toBe(2)
        ->and(RiskEngine::median([]))->toBeNull();
});

it('assigns a weight and category to every configured signal', function (): void {
    foreach (array_keys((array) config('scampause.risk.weights')) as $code) {
        expect(RiskEngine::CATEGORIES)->toHaveKey($code);
    }

    foreach (array_keys(RiskEngine::CATEGORIES) as $code) {
        expect(config('scampause.risk.weights'))->toHaveKey($code)
            ->and(trans('risk.signals.'.$code.'.title', [], 'en'))->not->toBe('risk.signals.'.$code.'.title')
            ->and(trans('risk.signals.'.$code.'.title', [], 'ru'))->not->toBe('risk.signals.'.$code.'.title');
    }
});
