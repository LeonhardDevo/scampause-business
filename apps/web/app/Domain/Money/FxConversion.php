<?php

declare(strict_types=1);

namespace App\Domain\Money;

use Carbon\CarbonImmutable;

final readonly class FxConversion
{
    public function __construct(
        public ?int $amountBaseMinor,
        public ?string $rate,
        public ?CarbonImmutable $rateDate,
        public bool $missing,
    ) {}

    public static function missing(): self
    {
        return new self(null, null, null, true);
    }
}
