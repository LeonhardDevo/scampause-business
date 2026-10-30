<?php

declare(strict_types=1);

namespace Database\Seeders\Domain\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;

final class FrozenClock
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function at(CarbonInterface $moment, Closure $callback): mixed
    {
        $previous = Carbon::getTestNow();
        self::freeze($moment);

        try {
            return $callback();
        } finally {
            self::restore($previous);
        }
    }

    public static function freeze(CarbonInterface $moment): void
    {
        $utc = CarbonImmutable::instance($moment)->utc();
        Carbon::setTestNow(Carbon::instance($utc));
        CarbonImmutable::setTestNow($utc);
    }

    public static function restore(?CarbonInterface $previous): void
    {
        Carbon::setTestNow($previous === null ? null : Carbon::instance($previous));
        CarbonImmutable::setTestNow($previous === null ? null : CarbonImmutable::instance($previous));
    }
}
