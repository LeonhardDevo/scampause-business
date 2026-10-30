<?php

declare(strict_types=1);

namespace App\Domain\Support;

use Carbon\CarbonImmutable;

final class BusinessDays
{
    /**
     * @param  list<int>  $businessDays
     */
    public static function between(CarbonImmutable $from, CarbonImmutable $to, array $businessDays): int
    {
        $start = $from->startOfDay();
        $end = $to->startOfDay();

        if ($end->lessThanOrEqualTo($start)) {
            return $end->equalTo($start) ? 0 : -1;
        }

        $count = 0;
        $cursor = $start;

        for ($i = 0; $i < 400 && $cursor->lessThan($end); $i++) {
            $cursor = $cursor->addDay();

            if (in_array($cursor->dayOfWeekIso, $businessDays, true)) {
                $count++;
            }
        }

        return $count;
    }
}
