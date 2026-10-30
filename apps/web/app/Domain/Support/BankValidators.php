<?php

declare(strict_types=1);

namespace App\Domain\Support;

use App\Domain\Support\Normalize\BankNormalizer;
use App\Domain\Vendors\Enums\BankScheme;

final class BankValidators
{
    private const array IBAN_LENGTHS = [
        'AD' => 24, 'AE' => 23, 'AL' => 28, 'AT' => 20, 'AZ' => 28, 'BA' => 20, 'BE' => 16, 'BG' => 22, 'BH' => 22,
        'BI' => 27, 'BR' => 29, 'BY' => 28, 'CH' => 21, 'CR' => 22, 'CY' => 28, 'CZ' => 24, 'DE' => 22, 'DJ' => 27,
        'DK' => 18, 'DO' => 28, 'EE' => 20, 'EG' => 29, 'ES' => 24, 'FI' => 18, 'FK' => 18, 'FO' => 18, 'FR' => 27,
        'GB' => 22, 'GE' => 22, 'GI' => 23, 'GL' => 18, 'GR' => 27, 'GT' => 28, 'HR' => 21, 'HU' => 28, 'IE' => 22,
        'IL' => 23, 'IQ' => 23, 'IS' => 26, 'IT' => 27, 'JO' => 30, 'KW' => 30, 'KZ' => 20, 'LB' => 28, 'LC' => 32,
        'LI' => 21, 'LT' => 20, 'LU' => 20, 'LV' => 21, 'LY' => 25, 'MC' => 27, 'MD' => 24, 'ME' => 22, 'MK' => 19,
        'MN' => 20, 'MR' => 27, 'MT' => 31, 'MU' => 30, 'NI' => 28, 'NL' => 18, 'NO' => 15, 'OM' => 23, 'PK' => 24,
        'PL' => 28, 'PS' => 29, 'PT' => 25, 'QA' => 29, 'RO' => 24, 'RS' => 22, 'RU' => 33, 'SA' => 24, 'SC' => 31,
        'SD' => 18, 'SE' => 24, 'SI' => 19, 'SK' => 24, 'SM' => 27, 'SO' => 23, 'ST' => 25, 'SV' => 28, 'TL' => 23,
        'TN' => 24, 'TR' => 26, 'UA' => 29, 'VA' => 22, 'VG' => 24, 'XK' => 20, 'YE' => 30,
    ];

    public static function iban(string $raw): bool
    {
        $iban = BankNormalizer::account(BankScheme::Iban, $raw);

        if (preg_match('/^([A-Z]{2})(\d{2})([A-Z0-9]+)$/', $iban, $match) !== 1) {
            return false;
        }

        $length = self::IBAN_LENGTHS[$match[1]] ?? null;

        if ($length === null || strlen($iban) !== $length) {
            return false;
        }

        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';

        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $remainder = 0;

        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) (((string) $remainder.$chunk)) % 97;
        }

        return $remainder === 1;
    }

    public static function ibanCountry(string $raw): ?string
    {
        $iban = BankNormalizer::account(BankScheme::Iban, $raw);

        return preg_match('/^[A-Z]{2}/', $iban) === 1 ? substr($iban, 0, 2) : null;
    }

    public static function aba(string $raw): bool
    {
        $digits = (string) preg_replace('/\D+/', '', $raw);

        if (strlen($digits) !== 9) {
            return false;
        }

        $d = array_map('intval', str_split($digits));
        $sum = 3 * ($d[0] + $d[3] + $d[6]) + 7 * ($d[1] + $d[4] + $d[7]) + ($d[2] + $d[5] + $d[8]);

        return $sum % 10 === 0;
    }

    public static function usAccount(string $raw): bool
    {
        return preg_match('/^\d{4,17}$/', BankNormalizer::account(BankScheme::UsAch, $raw)) === 1;
    }

    public static function ruBik(string $raw): bool
    {
        return preg_match('/^04\d{7}$/', (string) preg_replace('/\D+/', '', $raw)) === 1;
    }

    public static function ruAccount(string $account, string $bik): bool
    {
        $account = (string) preg_replace('/\D+/', '', $account);
        $bik = (string) preg_replace('/\D+/', '', $bik);

        if (strlen($account) !== 20 || strlen($bik) !== 9) {
            return false;
        }

        return self::ruKey(substr($bik, -3).$account);
    }

    public static function ruCorrespondent(string $account, string $bik): bool
    {
        $account = (string) preg_replace('/\D+/', '', $account);
        $bik = (string) preg_replace('/\D+/', '', $bik);

        if (strlen($account) !== 20 || strlen($bik) !== 9) {
            return false;
        }

        return self::ruKey('0'.substr($bik, 4, 2).$account);
    }

    public static function ukSortCode(string $raw): bool
    {
        return preg_match('/^\d{6}$/', (string) preg_replace('/\D+/', '', $raw)) === 1;
    }

    public static function ukAccount(string $raw): bool
    {
        return preg_match('/^\d{8}$/', (string) preg_replace('/\D+/', '', $raw)) === 1;
    }

    public static function bic(string $raw): bool
    {
        return preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/', strtoupper(trim($raw))) === 1;
    }

    public static function bicCountry(string $raw): ?string
    {
        return self::bic($raw) ? substr(strtoupper(trim($raw)), 4, 2) : null;
    }

    public static function inn(string $raw): bool
    {
        $digits = (string) preg_replace('/\D+/', '', $raw);
        $d = array_map('intval', str_split($digits));

        if (strlen($digits) === 10) {
            return self::innDigit($d, [2, 4, 10, 3, 5, 9, 4, 6, 8]) === $d[9];
        }

        if (strlen($digits) === 12) {
            return self::innDigit($d, [7, 2, 4, 10, 3, 5, 9, 4, 6, 8]) === $d[10]
                && self::innDigit($d, [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8]) === $d[11];
        }

        return false;
    }

    public static function ein(string $raw): bool
    {
        return preg_match('/^\d{2}-?\d{7}$/', trim($raw)) === 1;
    }

    public static function checksum(BankScheme $scheme, string $account, ?string $routing): ?bool
    {
        return match ($scheme) {
            BankScheme::Iban => self::iban($account),
            BankScheme::UsAch => $routing !== null && $routing !== '' ? self::aba($routing) && self::usAccount($account) : null,
            BankScheme::RuAccount => $routing !== null && $routing !== '' ? self::ruAccount($account, $routing) : null,
            BankScheme::UkSortCode => $routing !== null && $routing !== '' ? self::ukSortCode($routing) && self::ukAccount($account) : null,
            BankScheme::Other => null,
        };
    }

    private static function ruKey(string $digits): bool
    {
        $weights = [7, 1, 3];
        $sum = 0;

        foreach (str_split($digits) as $index => $digit) {
            $sum += ((int) $digit * $weights[$index % 3]) % 10;
        }

        return $sum % 10 === 0;
    }

    /**
     * @param  list<int>  $digits
     * @param  list<int>  $weights
     */
    private static function innDigit(array $digits, array $weights): int
    {
        $sum = 0;

        foreach ($weights as $index => $weight) {
            $sum += $digits[$index] * $weight;
        }

        return ($sum % 11) % 10;
    }
}
