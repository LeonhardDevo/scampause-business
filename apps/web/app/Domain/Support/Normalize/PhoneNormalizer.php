<?php

declare(strict_types=1);

namespace App\Domain\Support\Normalize;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

final class PhoneNormalizer
{
    public static function e164(?string $raw, string $country = 'US'): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            $number = $util->parse(trim($raw), strtoupper($country));
        } catch (NumberParseException) {
            return null;
        }

        if (! $util->isPossibleNumber($number)) {
            return null;
        }

        return $util->format($number, PhoneNumberFormat::E164);
    }

    public static function last4(string $e164): string
    {
        return substr($e164, -4);
    }
}
