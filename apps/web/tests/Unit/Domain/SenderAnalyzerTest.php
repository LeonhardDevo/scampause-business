<?php

declare(strict_types=1);

use App\Domain\Intake\Enums\AuthResult;
use App\Domain\Risk\Sender\DomainFacts;
use App\Domain\Risk\Sender\SenderAnalyzer;
use App\Domain\Risk\Sender\SenderInput;
use App\Domain\Risk\SignalHit;
use Carbon\CarbonImmutable;

/**
 * @param  array<string, mixed>  $overrides
 */
function spSenderInput(array $overrides = []): SenderInput
{
    $defaults = [
        'fromName' => null,
        'fromEmail' => 'ar@blueridge-packaging.com',
        'replyTo' => null,
        'returnPath' => null,
        'textEmails' => [],
        'urls' => [],
        'textDomains' => [],
        'vendorMatched' => true,
        'vendorDomains' => ['blueridge-packaging.com'],
        'workspaceVendorDomains' => ['blueridge-packaging.com', 'kedr.ru'],
        'ownDomains' => ['northwindcoffee.com'],
        'privilegedMemberNames' => ['Alex Morgan'],
        'spf' => AuthResult::Pass,
        'dkim' => AuthResult::Pass,
        'dmarc' => AuthResult::Pass,
        'facts' => ['blueridge-packaging.com' => new DomainFacts('blueridge-packaging.com', CarbonImmutable::parse('2015-03-01'), 'reject', 'ok')],
        'freeMailDomains' => ['gmail.com', 'mail.ru'],
        'vendorIsEmployee' => false,
        'payrollChange' => false,
        'today' => CarbonImmutable::parse('2026-10-23'),
        'intelSkippedByPlan' => false,
    ];

    return new SenderInput(...array_merge($defaults, $overrides));
}

/**
 * @return list<string>
 */
function spCodes(array $hits): array
{
    return array_map(static fn (SignalHit $hit): string => $hit->code, $hits);
}

it('raises nothing for the known vendor domain with good facts', function (): void {
    expect(spCodes(SenderAnalyzer::analyze(spSenderInput())->hits))->toBe([]);
});

it('detects lookalike techniques', function (string $candidate, string $known, ?string $technique): void {
    expect(SenderAnalyzer::findLookalike($candidate, [$known])['technique'] ?? null)->toBe($technique);
})->with([
    'edit distance long' => ['blueridge-packagng.com', 'blueridge-packaging.com', 'edit_distance'],
    'edit distance short' => ['kedrr.ru', 'kedr.ru', 'edit_distance'],
    'short labels need distance one' => ['kdr.ru', 'kedr.ru', 'edit_distance'],
    'too different' => ['contoso.com', 'blueridge-packaging.com', null],
    'homoglyph digits' => ['n0rthwind.com', 'northwind.com', 'homoglyph'],
    'homoglyph rn' => ['rnicrosoft.com', 'microsoft.com', 'homoglyph'],
    'homoglyph cyrillic' => ['xn--kdr-5cd2a.ru', 'kedr.ru', null],
    'affix suffix' => ['northwind-billing.com', 'northwind.com', 'affix'],
    'affix prefix' => ['paynorthwind.com', 'northwind.com', 'affix'],
    'tld swap' => ['northwind.co', 'northwind.com', 'tld_swap'],
    'same domain' => ['northwind.com', 'northwind.com', null],
]);

it('detects Cyrillic homoglyph domains through punycode', function (): void {
    $ascii = (string) idn_to_ascii('nоrthwind.com', IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

    expect(SenderAnalyzer::findLookalike($ascii, ['northwind.com'])['technique'] ?? null)->toBe('homoglyph');
});

it('flags a lookalike sender with evidence', function (): void {
    $analysis = SenderAnalyzer::analyze(spSenderInput(['fromEmail' => 'ar@blueridge-packagng.com', 'facts' => []]));
    $lookalike = collect($analysis->hits)->firstWhere('code', 'SENDER_DOMAIN_LOOKALIKE');

    expect($lookalike?->evidence)->toMatchArray(['domain' => 'blueridge-packagng.com', 'resembles' => 'blueridge-packaging.com', 'technique' => 'edit_distance'])
        ->and(spCodes($analysis->hits))->not->toContain('SENDER_DOMAIN_UNKNOWN_FOR_VENDOR')
        ->and($analysis->domains[0]['lookalike_of'])->toBe('blueridge-packaging.com');
});

it('flags an unknown domain for the matched vendor', function (): void {
    $analysis = SenderAnalyzer::analyze(spSenderInput(['fromEmail' => 'billing@contoso-invoices.com', 'facts' => ['contoso-invoices.com' => new DomainFacts('contoso-invoices.com', CarbonImmutable::parse('2010-01-01'), 'reject', 'ok')]]));

    expect(spCodes($analysis->hits))->toBe(['SENDER_DOMAIN_UNKNOWN_FOR_VENDOR']);
});

it('flags reply-to mismatch, free mail and failed authentication', function (): void {
    $analysis = SenderAnalyzer::analyze(spSenderInput([
        'replyTo' => 'blueridge.payments@gmail.com',
        'spf' => AuthResult::Softfail,
        'dkim' => AuthResult::None,
    ]));

    expect(spCodes($analysis->hits))->toContain('REPLY_TO_MISMATCH', 'FREE_EMAIL_PROVIDER', 'EMAIL_AUTH_FAILED');
});

it('skips the free-mail signal for employees unless it is a payroll change', function (bool $payroll, bool $expected): void {
    $analysis = SenderAnalyzer::analyze(spSenderInput([
        'fromEmail' => 'maria@gmail.com', 'vendorIsEmployee' => true, 'payrollChange' => $payroll, 'vendorDomains' => [], 'facts' => [],
    ]));

    expect(in_array('FREE_EMAIL_PROVIDER', spCodes($analysis->hits), true))->toBe($expected);
})->with([[false, false], [true, true]]);

it('flags display-name impersonation only when own domains are known', function (): void {
    $flagged = SenderAnalyzer::analyze(spSenderInput(['fromName' => 'Alex Morgan', 'fromEmail' => 'alex.morgan@gmail.com', 'facts' => []]));
    $skipped = SenderAnalyzer::analyze(spSenderInput(['fromName' => 'Alex Morgan', 'fromEmail' => 'alex.morgan@gmail.com', 'ownDomains' => [], 'facts' => []]));
    $internal = SenderAnalyzer::analyze(spSenderInput(['fromName' => 'Alex Morgan', 'fromEmail' => 'alex@northwindcoffee.com', 'facts' => []]));

    expect(spCodes($flagged->hits))->toContain('DISPLAY_NAME_IMPERSONATION')
        ->and(spCodes($skipped->hits))->not->toContain('DISPLAY_NAME_IMPERSONATION')
        ->and($skipped->notes)->toContain(SenderAnalyzer::NOTE_IMPERSONATION_SKIPPED)
        ->and(spCodes($internal->hits))->not->toContain('DISPLAY_NAME_IMPERSONATION');
});

it('derives domain age and DMARC signals from facts', function (string $registered, ?string $dmarc, array $expected): void {
    $analysis = SenderAnalyzer::analyze(spSenderInput([
        'facts' => ['blueridge-packaging.com' => new DomainFacts('blueridge-packaging.com', CarbonImmutable::parse($registered), $dmarc, 'ok')],
    ]));

    expect(spCodes($analysis->hits))->toBe($expected);
})->with([
    'ten days' => ['2026-10-13', 'reject', ['DOMAIN_RECENTLY_REGISTERED']],
    'exactly thirty days' => ['2026-09-23', 'reject', ['DOMAIN_RECENTLY_REGISTERED']],
    'ninety days' => ['2026-07-25', 'reject', ['DOMAIN_YOUNG']],
    'old without dmarc' => ['2012-01-01', 'absent', ['DOMAIN_NO_DMARC']],
]);

it('reports unavailable domain facts, with a plan note in cache-only mode', function (): void {
    $analysis = SenderAnalyzer::analyze(spSenderInput(['facts' => [], 'intelSkippedByPlan' => true]));

    expect(spCodes($analysis->hits))->toBe(['DOMAIN_INTEL_UNAVAILABLE'])
        ->and($analysis->notes)->toContain(SenderAnalyzer::NOTE_INTEL_PLAN);
});

it('flags punycode domains anywhere in the message', function (): void {
    $analysis = SenderAnalyzer::analyze(spSenderInput(['urls' => ['https://xn--e1afmkfd.xn--p1ai/pay']]));

    expect(spCodes($analysis->hits))->toContain('PUNYCODE_DOMAIN')
        ->and(collect($analysis->domains)->firstWhere('domain', 'xn--e1afmkfd.xn--p1ai')['unicode'])->toBe('пример.рф');
});

it('builds homoglyph skeletons', function (): void {
    expect(SenderAnalyzer::skeleton('rnicr0soft'))->toBe(SenderAnalyzer::skeleton('microsoft'))
        ->and(SenderAnalyzer::skeleton('раураl'))->toBe(SenderAnalyzer::skeleton('paypal'));
});
