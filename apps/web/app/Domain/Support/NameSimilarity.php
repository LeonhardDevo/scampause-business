<?php

declare(strict_types=1);

namespace App\Domain\Support;

use App\Domain\Support\Normalize\NameNormalizer;

final class NameSimilarity
{
    public static function score(string $a, string $b): int
    {
        return self::trigramScore(NameNormalizer::key($a), NameNormalizer::key($b));
    }

    /**
     * @param  iterable<string|null>  $candidates
     */
    public static function best(string $name, iterable $candidates): int
    {
        $best = 0;

        foreach ($candidates as $candidate) {
            if ($candidate === null || trim($candidate) === '') {
                continue;
            }

            $best = max($best, self::score($name, $candidate));
        }

        return $best;
    }

    public static function trigramScore(string $a, string $b): int
    {
        $left = self::trigrams($a);
        $right = self::trigrams($b);

        if ($left === [] || $right === []) {
            return 0;
        }

        $intersection = count(array_intersect_key($left, $right));
        $union = count($left + $right);

        return (int) round(($intersection / $union) * 100);
    }

    /** @return array<string, true> */
    public static function trigrams(string $value): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($value, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
        $set = [];

        foreach ($words === false ? [] : $words as $word) {
            $chars = mb_str_split('  '.$word.' ', 1, 'UTF-8');
            $count = count($chars);

            for ($i = 0; $i + 2 < $count; $i++) {
                $set[$chars[$i].$chars[$i + 1].$chars[$i + 2]] = true;
            }
        }

        return $set;
    }
}
