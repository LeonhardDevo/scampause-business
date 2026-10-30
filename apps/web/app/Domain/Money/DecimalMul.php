<?php

declare(strict_types=1);

namespace App\Domain\Money;

final class DecimalMul
{
    public const int SCALE = 8;

    public static function normalizeRate(string $rate): ?string
    {
        $rate = trim(str_replace(',', '.', $rate));

        if (preg_match('/^\d{1,10}(?:\.\d{1,8})?$/', $rate) !== 1) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $rate, 2), 2, '');
        $whole = ltrim($whole, '0');
        $normalized = ($whole === '' ? '0' : $whole).'.'.str_pad($fraction, self::SCALE, '0');

        return ltrim(str_replace('.', '', $normalized), '0') === '' ? null : $normalized;
    }

    public static function convert(int $amountMinor, string $rate, int $quoteExponent, int $baseExponent): int
    {
        $normalized = self::normalizeRate($rate);

        if ($normalized === null || $amountMinor < 0) {
            throw new \InvalidArgumentException('Invalid conversion input.');
        }

        $product = self::multiply((string) $amountMinor, str_replace('.', '', $normalized));
        $power = self::SCALE - ($baseExponent - $quoteExponent);

        if ($power <= 0) {
            return (int) self::trim($product.str_repeat('0', -$power));
        }

        $product = str_pad($product, $power + 1, '0', STR_PAD_LEFT);
        $quotient = self::trim(substr($product, 0, -$power));
        $remainder = substr($product, -$power);
        $half = '5'.str_repeat('0', $power - 1);
        $comparison = strcmp($remainder, $half);
        $lastDigit = (int) substr($quotient, -1);

        if ($comparison > 0 || ($comparison === 0 && $lastDigit % 2 === 1)) {
            $quotient = self::increment($quotient);
        }

        return (int) $quotient;
    }

    public static function divideHalfEven(int $numerator, int $denominator): int
    {
        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;
        $twice = 2 * abs($remainder);

        if ($twice > $denominator || ($twice === $denominator && $quotient % 2 !== 0)) {
            $quotient += $numerator < 0 ? -1 : 1;
        }

        return $quotient;
    }

    public static function multiply(string $a, string $b): string
    {
        $a = self::trim($a);
        $b = self::trim($b);

        if ($a === '0' || $b === '0') {
            return '0';
        }

        $left = array_map('intval', array_reverse(str_split($a)));
        $right = array_map('intval', array_reverse(str_split($b)));
        $result = array_fill(0, count($left) + count($right), 0);

        foreach ($left as $i => $x) {
            $carry = 0;

            foreach ($right as $j => $y) {
                $sum = $result[$i + $j] + $x * $y + $carry;
                $result[$i + $j] = $sum % 10;
                $carry = intdiv($sum, 10);
            }

            $k = $i + count($right);

            while ($carry > 0) {
                $sum = $result[$k] + $carry;
                $result[$k] = $sum % 10;
                $carry = intdiv($sum, 10);
                $k++;
            }
        }

        return self::trim(implode('', array_reverse($result)));
    }

    private static function increment(string $digits): string
    {
        $chars = str_split($digits);

        for ($i = count($chars) - 1; $i >= 0; $i--) {
            if ($chars[$i] !== '9') {
                $chars[$i] = (string) ((int) $chars[$i] + 1);

                return implode('', $chars);
            }

            $chars[$i] = '0';
        }

        return '1'.implode('', $chars);
    }

    private static function trim(string $digits): string
    {
        $trimmed = ltrim($digits, '0');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
