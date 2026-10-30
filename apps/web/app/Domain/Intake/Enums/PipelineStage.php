<?php

declare(strict_types=1);

namespace App\Domain\Intake\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum PipelineStage: string
{
    use EnumHelpers;

    case Extract = 'extract';
    case Domain = 'domain';
    case Bank = 'bank';
    case Score = 'score';

    public static function fromWire(string $value): ?self
    {
        return match ($value) {
            'extract' => self::Extract,
            'domain', 'domain-check' => self::Domain,
            'bank', 'bank-compare' => self::Bank,
            'score' => self::Score,
            default => null,
        };
    }

    /** @return list<string> */
    public static function wireValues(): array
    {
        return ['extract', 'domain', 'domain-check', 'bank', 'bank-compare', 'score'];
    }
}
