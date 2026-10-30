<?php

declare(strict_types=1);

namespace App\Domain\Money;

final class MoneyFormatter
{
    private const string NBSP = "\u{00A0}";

    public static function format(int $minor, string $currency, string $locale): string
    {
        $currency = strtoupper($currency);
        $spec = self::spec($locale, $currency);
        $exponent = Currencies::exponent($currency);
        $digits = str_pad((string) abs($minor), $exponent + 1, '0', STR_PAD_LEFT);
        $whole = $exponent === 0 ? $digits : substr($digits, 0, -$exponent);
        $fraction = $exponent === 0 ? '' : substr($digits, -$exponent);
        $grouped = self::group($whole, $spec['group']);
        $number = $fraction === '' ? $grouped : $grouped.$spec['decimal'].$fraction;
        $symbol = $spec['symbol'] ?? $currency;
        $space = $spec['symbol'] === null || $spec['space_before_symbol'] ? self::separator($spec) : '';

        $formatted = $spec['position'] === 'prefix'
            ? $symbol.($spec['symbol'] === null ? ' ' : $space).$number
            : $number.$space.$symbol;

        return ($minor < 0 ? '-' : '').$formatted;
    }

    /**
     * @return array{symbol: string|null, position: string, group: string, decimal: string, space_before_symbol: bool}
     */
    private static function spec(string $locale, string $currency): array
    {
        $locale = str_starts_with(strtolower($locale), 'ru') ? 'ru' : 'en';
        /** @var array<string, array{symbol: string|null, position: string, group: string, decimal: string, space_before_symbol: bool}> $table */
        $table = config('scampause.money_format.'.$locale, []);

        return $table[$currency] ?? $table['default'];
    }

    /**
     * @param  array{symbol: string|null, position: string, group: string, decimal: string, space_before_symbol: bool}  $spec
     */
    private static function separator(array $spec): string
    {
        return $spec['position'] === 'suffix' ? self::NBSP : ' ';
    }

    private static function group(string $whole, string $separator): string
    {
        $whole = ltrim($whole, '0');

        if ($whole === '') {
            return '0';
        }

        $groups = str_split(strrev($whole), 3);

        return strrev(implode(strrev($separator), $groups));
    }
}
