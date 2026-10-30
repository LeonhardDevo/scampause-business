<?php

declare(strict_types=1);

namespace App\Domain\Support;

use Carbon\CarbonImmutable;
use Psr\Clock\ClockInterface;

final class Clock implements ClockInterface
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }
}
