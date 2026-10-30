<?php

declare(strict_types=1);

namespace App\Domain\Money;

final class MoneyParser
{
    public static function toMinor(string $major, string $currency): ?int
    {
        $value = trim(str_replace(["\u{00A0}", "\u{202F}", ' '], '', $major));
        $exponent = Currencies::exponent($currency);
        $value = str_replace(',', '.', $value);
        $pattern = $exponent === 0 ? '/^\d{1,15}$/' : '/^\d{1,15}(?:\.\d{1,'.$exponent.'})?$/';

        if (preg_match($pattern, $value) !== 1) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) ($whole.str_pad($fraction, $exponent, '0'));
    }

    public static function fromMinor(int $minor, string $currency): string
    {
        $exponent = Currencies::exponent($currency);
        $digits = str_pad((string) abs($minor), $exponent + 1, '0', STR_PAD_LEFT);
        $sign = $minor < 0 ? '-' : '';

        if ($exponent === 0) {
            return $sign.$digits;
        }

        return $sign.substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent);
    }
}
