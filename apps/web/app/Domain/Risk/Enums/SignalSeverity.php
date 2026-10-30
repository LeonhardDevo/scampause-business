<?php

declare(strict_types=1);

namespace App\Domain\Risk\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum SignalSeverity: string
{
    use EnumHelpers;

    case Info = 'info';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public static function fromPoints(int $points): self
    {
        return match (true) {
            $points <= 0 => self::Info,
            $points <= 10 => self::Low,
            $points <= 20 => self::Medium,
            $points <= 35 => self::High,
            default => self::Critical,
        };
    }
}
