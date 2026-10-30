<?php

declare(strict_types=1);

namespace App\Domain\Support\Normalize;

use App\Domain\Support\Translit;

final class NameNormalizer
{
    private const array LEGAL_FORMS = [
        'llc', 'inc', 'ltd', 'co', 'corp', 'corporation', 'company', 'gmbh', 'sa', 'sl', 'srl', 'bv', 'plc',
        'ooo', 'oao', 'zao', 'pao', 'ao', 'ip',
    ];

    private const array QUOTES = ['«', '»', '"', "'", "\u{201C}", "\u{201D}", "\u{201E}", "\u{2019}", "\u{2018}"];

    public static function key(string $raw): string
    {
        $value = Translit::toLatin($raw);
        $value = str_replace(self::QUOTES, '', $value);
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
        $words = array_filter(
            explode(' ', $value),
            static fn (string $word): bool => $word !== '' && ! in_array($word, self::LEGAL_FORMS, true),
        );

        return implode(' ', $words);
    }
}
