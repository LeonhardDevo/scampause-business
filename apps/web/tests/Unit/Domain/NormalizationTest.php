<?php

declare(strict_types=1);

use App\Domain\Support\BankValidators;
use App\Domain\Support\BusinessDays;
use App\Domain\Support\EditDistance;
use App\Domain\Support\Masking;
use App\Domain\Support\NameSimilarity;
use App\Domain\Support\Normalize\BankNormalizer;
use App\Domain\Support\Normalize\DomainNormalizer;
use App\Domain\Support\Normalize\EmailNormalizer;
use App\Domain\Support\Normalize\InvoiceNumber;
use App\Domain\Support\Normalize\NameNormalizer;
use App\Domain\Support\Normalize\PhoneNormalizer;
use App\Domain\Support\Normalize\TaxId;
use App\Domain\Support\Translit;
use App\Domain\Vendors\Enums\BankScheme;
use Carbon\CarbonImmutable;

it('normalizes bank account numbers per scheme', function (BankScheme $scheme, string $raw, string $expected): void {
    expect(BankNormalizer::account($scheme, $raw))->toBe($expected);
})->with([
    [BankScheme::Iban, 'IBAN de89 3704-0044.0532/0130 00', 'DE89370400440532013000'],
    [BankScheme::RuAccount, '4070 2810 9380 0001 2345', '40702810938000012345'],
    [BankScheme::UsAch, '0001-2345-6789', '000123456789'],
    [BankScheme::UkSortCode, '5544 3322', '55443322'],
    [BankScheme::Other, 'ab 12-34', 'AB1234'],
]);

it('normalizes routing codes to digits', function (): void {
    expect(BankNormalizer::routing(BankScheme::UsAch, '021-000-021'))->toBe('021000021')
        ->and(BankNormalizer::routing(BankScheme::Iban, '021000021'))->toBe('')
        ->and(BankNormalizer::routing(BankScheme::UkSortCode, '20-00-35'))->toBe('200035');
});

it('resolves registrable domains with the committed public suffix list', function (?string $raw, ?string $expected): void {
    expect(DomainNormalizer::registrable($raw))->toBe($expected);
})->with([
    ['Mail.BlueRidge-Packaging.com.', 'blueridge-packaging.com'],
    ['billing.northwind.co.uk', 'northwind.co.uk'],
    ['пример.рф', 'xn--e1afmkfd.xn--p1ai'],
    ['localhost', null],
    ['not a domain', null],
    [null, null],
]);

it('splits domains into label and suffix', function (): void {
    expect(DomainNormalizer::split('a.northwind.co.uk'))->toBe(['registrable' => 'northwind.co.uk', 'label' => 'northwind', 'suffix' => 'co.uk'])
        ->and(DomainNormalizer::unicode('xn--e1afmkfd.xn--p1ai'))->toBe('пример.рф')
        ->and(DomainNormalizer::fromUrl('https://pay.example.org/x?y=1'))->toBe('example.org');
});

it('normalizes e-mail addresses', function (): void {
    expect(EmailNormalizer::normalize(' "Alex" <Alex@Mail.Northwind.COM> '))->toBe('alex@mail.northwind.com')
        ->and(EmailNormalizer::domain('Alex@Mail.Northwind.COM'))->toBe('northwind.com')
        ->and(EmailNormalizer::normalize('nobody'))->toBeNull();
});

it('normalizes phones to E.164 with the workspace region', function (): void {
    expect(PhoneNormalizer::e164('(828) 555-0142', 'US'))->toBe('+18285550142')
        ->and(PhoneNormalizer::e164('8 (495) 123-45-67', 'RU'))->toBe('+74951234567')
        ->and(PhoneNormalizer::e164('12', 'US'))->toBeNull();
});

it('builds name keys with transliteration and legal forms removed', function (string $raw, string $expected): void {
    expect(NameNormalizer::key($raw))->toBe($expected);
})->with([
    ['ООО «Кедр и Партнёры»', 'kedr i partnyory'],
    ['Blue Ridge Packaging, LLC', 'blue ridge packaging'],
    ['"Northwind" Inc.', 'northwind'],
    ['ИП Щукин', 'shhukin'],
]);

it('transliterates Cyrillic with the GOST 7.79-B table', function (): void {
    expect(Translit::toLatin('Цех Щит Жёлтый'))->toBe('czex shhit zhyoltyj');
});

it('scores trigram similarity like pg_trgm', function (): void {
    expect(NameSimilarity::trigramScore('word', 'two words'))->toBe(36)
        ->and(NameSimilarity::score('Blue Ridge Packaging Co.', 'Blue Ridge Packaging'))->toBe(100)
        ->and(NameSimilarity::score('ООО Кедр', 'Kedr LLC'))->toBe(100)
        ->and(NameSimilarity::score('Northwind', 'Contoso'))->toBeLessThan(20)
        ->and(NameSimilarity::best('Kedr', [null, '', 'Kedr Partners']))->toBe(36);
});

it('computes Damerau-Levenshtein distances', function (string $a, string $b, int $distance): void {
    expect(EditDistance::damerauLevenshtein($a, $b))->toBe($distance);
})->with([
    ['northwind', 'northwind', 0],
    ['northwind', 'nortwhind', 1],
    ['northwind', 'northwlnd', 1],
    ['ca', 'abc', 3],
    ['', 'abc', 3],
    ['кедр', 'кедрр', 1],
]);

it('normalizes invoice numbers and tax ids', function (): void {
    expect(InvoiceNumber::normalize(' inv-2026/0042 '))->toBe('INV20260042')
        ->and(InvoiceNumber::normalize('№ ---'))->toBeNull()
        ->and(TaxId::normalize('77-0708 3893'))->toBe('7707083893');
});

it('validates bank identifiers with the contract vectors', function (): void {
    foreach (['DE89370400440532013000', 'LT093250024720018839', 'ES7921000813610123456789', 'GB82BARC20035544332211'] as $iban) {
        expect(BankValidators::iban($iban))->toBeTrue($iban);
    }

    foreach (['021000021', '121000248', '026009593'] as $aba) {
        expect(BankValidators::aba($aba))->toBeTrue($aba);
    }

    expect(BankValidators::iban('GB82BARC20035544332212'))->toBeFalse()
        ->and(BankValidators::iban('DE8937040044053201300'))->toBeFalse()
        ->and(BankValidators::aba('021000022'))->toBeFalse()
        ->and(BankValidators::ruAccount('40702810938000012345', '044525225'))->toBeTrue()
        ->and(BankValidators::ruAccount('40702810938000012346', '044525225'))->toBeFalse()
        ->and(BankValidators::ruCorrespondent('30101810400000000225', '044525225'))->toBeTrue()
        ->and(BankValidators::bic('DEUTDEFF'))->toBeTrue()
        ->and(BankValidators::bic('DEUTDEFF500'))->toBeTrue()
        ->and(BankValidators::bic('DEUT1EFF'))->toBeFalse()
        ->and(BankValidators::inn('7707083893'))->toBeTrue()
        ->and(BankValidators::inn('500100732259'))->toBeTrue()
        ->and(BankValidators::inn('7707083894'))->toBeFalse()
        ->and(BankValidators::ein('12-3456789'))->toBeTrue()
        ->and(BankValidators::checksum(BankScheme::Other, 'x', null))->toBeNull()
        ->and(BankValidators::checksum(BankScheme::UsAch, '000123456789', '021000021'))->toBeTrue();
});

it('masks accounts, holders and phones', function (): void {
    expect(Masking::account(BankScheme::Iban, '8839', 'LT09'))->toBe('LT09 •••• •••• 8839')
        ->and(Masking::account(BankScheme::RuAccount, '4417'))->toBe('•••• 4417')
        ->and(Masking::holder('Blue Ridge Packaging LLC'))->toBe('Blue Ridge Pack•••')
        ->and(Masking::holder('Kedr'))->toBe('Ke•••')
        ->and(Masking::phone('0142'))->toBe('••• 0142');
});

it('counts business days between dates', function (): void {
    $friday = CarbonImmutable::parse('2026-10-23');

    expect(BusinessDays::between($friday, CarbonImmutable::parse('2026-10-27'), [1, 2, 3, 4, 5]))->toBe(2)
        ->and(BusinessDays::between($friday, $friday, [1, 2, 3, 4, 5]))->toBe(0)
        ->and(BusinessDays::between($friday, CarbonImmutable::parse('2026-10-22'), [1, 2, 3, 4, 5]))->toBe(-1);
});
