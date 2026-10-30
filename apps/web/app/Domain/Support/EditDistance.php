<?php

declare(strict_types=1);

namespace App\Domain\Support;

final class EditDistance
{
    public static function damerauLevenshtein(string $a, string $b): int
    {
        $s = mb_str_split($a, 1, 'UTF-8');
        $t = mb_str_split($b, 1, 'UTF-8');
        $n = count($s);
        $m = count($t);

        if ($n === 0) {
            return $m;
        }

        if ($m === 0) {
            return $n;
        }

        $d = [];

        for ($i = 0; $i <= $n; $i++) {
            $d[$i] = [0 => $i];
        }

        for ($j = 0; $j <= $m; $j++) {
            $d[0][$j] = $j;
        }

        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $cost = $s[$i - 1] === $t[$j - 1] ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);

                if ($i > 1 && $j > 1 && $s[$i - 1] === $t[$j - 2] && $s[$i - 2] === $t[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $d[$n][$m];
    }
}
