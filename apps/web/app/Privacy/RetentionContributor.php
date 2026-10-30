<?php

declare(strict_types=1);

namespace App\Privacy;

use Carbon\CarbonImmutable;

interface RetentionContributor
{
    /** @return array<string, int> */
    public function purge(CarbonImmutable $now): array;
}
