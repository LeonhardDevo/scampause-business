<?php

declare(strict_types=1);

namespace App\Domain\Money;

final class Currencies
{
    /** @return array<string, int> */
    public static function all(): array
    {
        /** @var array<string, int> $currencies */
        $currencies = config('scampause.currencies', []);

        return $currencies;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    public static function exponent(string $currency): int
    {
        return self::all()[strtoupper($currency)] ?? 2;
    }

    public static function supports(string $currency): bool
    {
        return array_key_exists(strtoupper($currency), self::all());
    }
}
