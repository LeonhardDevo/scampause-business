<?php

declare(strict_types=1);

namespace App\Domain\Support;

use App\Domain\Vendors\Enums\BankScheme;

final class Masking
{
    public const string DOT = '•';

    public static function account(BankScheme $scheme, string $last4, ?string $countryPrefix = null): string
    {
        $dots = str_repeat(self::DOT, 4);

        if ($scheme === BankScheme::Iban && $countryPrefix !== null && $countryPrefix !== '') {
            return $countryPrefix.' '.$dots.' '.$dots.' '.$last4;
        }

        return $dots.' '.$last4;
    }

    public static function holder(?string $holder): ?string
    {
        if ($holder === null || trim($holder) === '') {
            return null;
        }

        $holder = trim($holder);
        $length = mb_strlen($holder, 'UTF-8');
        $visible = $length > 16 ? 15 : max(1, intdiv($length, 2));

        return mb_substr($holder, 0, $visible, 'UTF-8').str_repeat(self::DOT, 3);
    }

    public static function phone(?string $last4): ?string
    {
        return $last4 === null ? null : str_repeat(self::DOT, 3).' '.$last4;
    }

    public static function routing(?string $routing): ?string
    {
        if ($routing === null || $routing === '') {
            return null;
        }

        return str_repeat(self::DOT, 3).substr($routing, -3);
    }

    /**
     * @param  int<1, max>  $size
     */
    public static function group(string $value, int $size = 4): string
    {
        return trim(implode(' ', str_split($value, $size)));
    }
}
