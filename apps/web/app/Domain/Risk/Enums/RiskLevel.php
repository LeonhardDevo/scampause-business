<?php

declare(strict_types=1);

namespace App\Domain\Risk\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum RiskLevel: string
{
    use EnumHelpers;

    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function rank(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Critical => 4,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 75 => self::Critical,
            $score >= 50 => self::High,
            $score >= 25 => self::Medium,
            default => self::Low,
        };
    }
}
