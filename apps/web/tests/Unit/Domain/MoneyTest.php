<?php

declare(strict_types=1);

use App\Domain\Money\DecimalMul;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Money\MoneyParser;

it('formats every shared money vector', function (): void {
    $vectors = json_decode((string) file_get_contents(base_path('tests/Fixtures/Domain/money-vectors.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($vectors)->toHaveCount(12);

    foreach ($vectors as $vector) {
        expect(MoneyFormatter::format($vector['minor'], $vector['currency'], $vector['locale']))
            ->toBe($vector['formatted'], $vector['locale'].' '.$vector['currency'].' '.$vector['minor']);
    }
});

it('parses major-unit strings into minor units without floats', function (string $input, string $currency, ?int $expected): void {
    expect(MoneyParser::toMinor($input, $currency))->toBe($expected);
})->with([
    ['18400.00', 'USD', 1840000],
    ['18 400,5', 'EUR', 1840050],
    ['0.01', 'USD', 1],
    ['4000', 'JPY', 4000],
    ['40.5', 'JPY', null],
    ['1.234', 'USD', null],
    ['abc', 'USD', null],
    ['-5', 'USD', null],
]);

it('renders minor units back to a decimal string', function (): void {
    expect(MoneyParser::fromMinor(1840050, 'EUR'))->toBe('18400.50')
        ->and(MoneyParser::fromMinor(7, 'USD'))->toBe('0.07')
        ->and(MoneyParser::fromMinor(4000, 'JPY'))->toBe('4000');
});

it('converts to base currency with half-even rounding', function (int $amount, string $rate, int $quoteExp, int $baseExp, int $expected): void {
    expect(DecimalMul::convert($amount, $rate, $quoteExp, $baseExp))->toBe($expected);
})->with([
    'identity' => [12345, '1', 2, 2, 12345],
    'half to even down' => [1, '0.5', 2, 2, 0],
    'half to even up' => [3, '0.5', 2, 2, 2],
    'above half' => [1, '0.50000001', 2, 2, 1],
    'jpy to usd' => [4000, '0.0067', 0, 2, 2680],
    'usd to jpy' => [12345, '150.25', 2, 0, 18548],
    'large amount without overflow' => [100_000_000_000, '92.5', 2, 2, 9_250_000_000_000],
    'eight decimals' => [100, '0.01234567', 2, 2, 1],
]);

it('multiplies arbitrary digit strings', function (): void {
    expect(DecimalMul::multiply('99999999999', '9999999999'))->toBe('999999999890000000001')
        ->and(DecimalMul::multiply('0', '123'))->toBe('0');
});

it('rejects malformed or non-positive rates', function (string $rate): void {
    expect(DecimalMul::normalizeRate($rate))->toBeNull();
})->with(['0', '0.000000000', '-1', 'x', '1.123456789']);

it('rounds medians half-even in division', function (): void {
    expect(DecimalMul::divideHalfEven(5, 2))->toBe(2)
        ->and(DecimalMul::divideHalfEven(7, 2))->toBe(4)
        ->and(DecimalMul::divideHalfEven(9, 4))->toBe(2);
});

it('builds money value objects', function (): void {
    $money = Money::parse('1000.00', 'usd');

    expect($money->minor)->toBe(100000)
        ->and($money->currency)->toBe('USD')
        ->and($money->isMultipleOfMajor(1000))->toBeTrue()
        ->and(Money::of(100001, 'USD')->isMultipleOfMajor(1000))->toBeFalse()
        ->and($money->plus(Money::of(5, 'USD'))->minor)->toBe(100005)
        ->and($money->toDto('en'))->toBe(['minor' => 100000, 'currency' => 'USD', 'formatted' => '$1,000.00']);
});
