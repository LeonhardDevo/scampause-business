<?php

declare(strict_types=1);

namespace App\Domain\Risk;

use App\Domain\Risk\Enums\RiskLevel;

final readonly class RiskResult
{
    /**
     * @param  list<ScoredSignal>  $signals
     * @param  list<string>  $verificationReasons
     */
    public function __construct(
        public array $signals,
        public int $score,
        public RiskLevel $level,
        public int $rawPoints,
        public ?string $floorSignal,
        public bool $verificationRequired,
        public array $verificationReasons,
        public string $rulesVersion,
        public string $inputsDigest,
    ) {}

    /** @return list<string> */
    public function codes(): array
    {
        return array_map(static fn (ScoredSignal $signal): string => $signal->code, $this->signals);
    }

    public function has(string $code): bool
    {
        return in_array($code, $this->codes(), true);
    }

    public function topSignal(): ?ScoredSignal
    {
        $top = null;

        foreach ($this->signals as $signal) {
            if ($top === null || $signal->points > $top->points) {
                $top = $signal;
            }
        }

        return $top;
    }
}
