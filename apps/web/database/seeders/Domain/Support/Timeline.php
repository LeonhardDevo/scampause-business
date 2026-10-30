<?php

declare(strict_types=1);

namespace Database\Seeders\Domain\Support;

use Carbon\CarbonImmutable;
use Closure;

final class Timeline
{
    /** @var list<array{at: CarbonImmutable, order: int, label: string, step: Closure(): mixed}> */
    private array $steps = [];

    private int $order = 0;

    /**
     * @param  Closure(): mixed  $step
     */
    public function at(CarbonImmutable $at, string $label, Closure $step): void
    {
        $this->steps[] = ['at' => $at->utc(), 'order' => $this->order++, 'label' => $label, 'step' => $step];
    }

    public function count(): int
    {
        return count($this->steps);
    }

    public function run(): void
    {
        $steps = $this->steps;
        usort($steps, static fn (array $a, array $b): int => [$a['at']->getTimestamp(), $a['at']->micro, $a['order']] <=> [$b['at']->getTimestamp(), $b['at']->micro, $b['order']]);
        $this->steps = [];

        foreach ($steps as $step) {
            try {
                FrozenClock::at($step['at'], $step['step']);
            } catch (\Throwable $exception) {
                throw new \RuntimeException('Demo seed step "'.$step['label'].'" at '.$step['at']->toIso8601ZuluString().' failed: '.$exception->getMessage(), 0, $exception);
            }
        }
    }
}
