<?php

declare(strict_types=1);

use App\Domain\Intake\Enums\BankComparisonOutcome;
use App\Domain\Intake\Enums\VendorMatchMethod;
use App\Domain\Vendors\Bank\AccountSnapshot;
use App\Domain\Vendors\Bank\BankDetailsComparator;
use App\Domain\Vendors\Bank\PayeeSnapshot;
use App\Domain\Vendors\Enums\BankAccountStatus;
use App\Domain\Vendors\Enums\BankScheme;
use App\Domain\Vendors\Matching\VendorCandidate;
use App\Domain\Vendors\Matching\VendorMatch;
use App\Domain\Vendors\Matching\VendorMatcher;
use Carbon\CarbonImmutable;

it('auto-matches the strongest vendor only with a clear margin', function (array $candidates, ?int $vendor, ?string $warning, ?VendorMatchMethod $method): void {
    $match = VendorMatcher::choose($candidates);

    expect($match->vendorId)->toBe($vendor)
        ->and($match->warning)->toBe($warning)
        ->and($match->method)->toBe($method);
})->with([
    'tax id wins' => [[new VendorCandidate(1, VendorMatchMethod::TaxId, 95), new VendorCandidate(2, VendorMatchMethod::Name, 70)], 1, null, VendorMatchMethod::TaxId],
    'runner-up too close' => [[new VendorCandidate(1, VendorMatchMethod::Name, 80), new VendorCandidate(2, VendorMatchMethod::Name, 70)], null, VendorMatch::WARNING_AMBIGUOUS, null],
    'below auto threshold' => [[new VendorCandidate(1, VendorMatchMethod::BankFingerprint, 60)], null, VendorMatch::WARNING_NOT_FOUND, null],
    'nothing found' => [[], null, VendorMatch::WARNING_NOT_FOUND, null],
    'same vendor several methods' => [[new VendorCandidate(3, VendorMatchMethod::Name, 72), new VendorCandidate(3, VendorMatchMethod::SenderDomain, 90)], 3, null, VendorMatchMethod::SenderDomain],
    'tie broken by method order' => [[new VendorCandidate(4, VendorMatchMethod::BankFingerprint, 80), new VendorCandidate(4, VendorMatchMethod::Name, 80)], 4, null, VendorMatchMethod::Name],
]);

function spAccount(int $id, string $fingerprint, BankAccountStatus $status, ?string $validFrom = null, string $country = 'DE'): AccountSnapshot
{
    return new AccountSnapshot($id, $fingerprint, $status, BankScheme::Iban, 'DE89370400440532013000', null, 'COBADEFF', $country, '3000', $validFrom !== null ? CarbonImmutable::parse($validFrom) : null);
}

function spPayee(string $fingerprint = 'fp-new', string $country = 'DE', ?string $holder = 'Blue Ridge Packaging'): PayeeSnapshot
{
    return new PayeeSnapshot($fingerprint, BankScheme::Iban, 'DE89370400440532013001', null, 'COBADEFF', $country, $holder, '3001');
}

it('classifies bank comparison outcomes in contract order', function (?PayeeSnapshot $payee, bool $blocked, ?int $vendor, array $accounts, array $others, BankComparisonOutcome $expected): void {
    $result = BankDetailsComparator::compare($payee, $blocked, $vendor, $accounts, $others, ['Blue Ridge Packaging'], CarbonImmutable::parse('2026-10-23'));

    expect($result->outcome)->toBe($expected);
})->with([
    'no account' => [null, false, 1, [], [], BankComparisonOutcome::NoAccountExtracted],
    'blocklisted wins over everything' => [spPayee('fp-a'), true, 1, [spAccount(1, 'fp-a', BankAccountStatus::Verified, '2026-01-01')], [], BankComparisonOutcome::Blocklisted],
    'no vendor' => [spPayee(), false, null, [], [], BankComparisonOutcome::UnmatchedVendor],
    'verified match' => [spPayee('fp-a'), false, 1, [spAccount(1, 'fp-a', BankAccountStatus::Verified, '2026-01-01')], [], BankComparisonOutcome::MatchVerified],
    'other vendor account' => [spPayee('fp-x'), false, 1, [spAccount(1, 'fp-a', BankAccountStatus::Verified, '2026-01-01')], ['fp-x' => 9], BankComparisonOutcome::OtherVendorAccount],
    'pending match' => [spPayee('fp-p'), false, 1, [spAccount(1, 'fp-p', BankAccountStatus::PendingVerification)], [], BankComparisonOutcome::MatchPending],
    'first use' => [spPayee('fp-n'), false, 1, [], [], BankComparisonOutcome::FirstUse],
    'changed' => [spPayee('fp-n'), false, 1, [spAccount(1, 'fp-a', BankAccountStatus::Verified, '2026-01-01')], [], BankComparisonOutcome::Changed],
    'retired only means first use' => [spPayee('fp-n'), false, 1, [spAccount(1, 'fp-a', BankAccountStatus::Retired, '2025-01-01')], [], BankComparisonOutcome::FirstUse],
]);

it('fills comparison details from the latest verified account', function (): void {
    $result = BankDetailsComparator::compare(
        spPayee('fp-new', 'LT', 'Northwind Trading'),
        false,
        1,
        [spAccount(1, 'fp-old', BankAccountStatus::Verified, '2026-01-01'), spAccount(2, 'fp-newer', BankAccountStatus::Verified, '2026-10-03')],
        [],
        ['Blue Ridge Packaging', null],
        CarbonImmutable::parse('2026-10-23'),
    );

    expect($result->outcome)->toBe(BankComparisonOutcome::Changed)
        ->and($result->previousAccountId)->toBe(2)
        ->and($result->details['verified_change_days_ago'])->toBe(20)
        ->and($result->details['same_country'])->toBeFalse()
        ->and($result->details['same_bank'])->toBeTrue()
        ->and($result->details['previous_last4'])->toBe('3000')
        ->and($result->details['new_last4'])->toBe('3001')
        ->and($result->details['account_edit_distance'])->toBe(1)
        ->and($result->details['holder_similarity'])->toBeLessThan(70);
});
