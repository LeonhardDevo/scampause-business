<?php

declare(strict_types=1);

namespace App\Domain\Risk;

use App\Domain\Risk\Enums\SignalCategory;
use App\Domain\Risk\Enums\SignalSeverity;

final readonly class ScoredSignal
{
    /**
     * @param  array<string, string|int|bool|null|list<string>|list<int>>  $evidence
     */
    public function __construct(
        public string $code,
        public SignalCategory $category,
        public SignalSeverity $severity,
        public int $points,
        public array $evidence,
    ) {}
}
