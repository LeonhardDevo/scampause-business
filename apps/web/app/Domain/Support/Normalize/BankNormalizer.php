<?php

declare(strict_types=1);

namespace App\Domain\Support\Normalize;

use App\Domain\Vendors\Enums\BankScheme;

final class BankNormalizer
{
    public static function account(BankScheme $scheme, string $raw): string
    {
        $value = strtoupper(trim($raw));
        $value = (string) preg_replace('/[\s\x{00A0}\x{202F}\-\.\/]+/u', '', $value);

        return match ($scheme) {
            BankScheme::Iban => str_starts_with($value, 'IBAN') ? substr($value, 4) : $value,
            BankScheme::RuAccount, BankScheme::UsAch, BankScheme::UkSortCode => (string) preg_replace('/\D+/', '', $value),
            BankScheme::Other => $value,
        };
    }

    public static function routing(BankScheme $scheme, ?string $raw): string
    {
        if ($raw === null || $scheme === BankScheme::Iban) {
            return '';
        }

        return (string) preg_replace('/\D+/', '', $raw);
    }

    public static function last4(string $normalizedAccount): string
    {
        return substr($normalizedAccount, -4);
    }

    public static function bic(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = (string) preg_replace('/\s+/', '', strtoupper(trim($raw)));

        return $value === '' ? null : $value;
    }
}
