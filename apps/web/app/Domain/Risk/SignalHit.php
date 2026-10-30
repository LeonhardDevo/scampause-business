<?php

declare(strict_types=1);

namespace App\Domain\Risk;

final readonly class SignalHit
{
    /**
     * @param  array<string, string|int|bool|null|list<string>|list<int>>  $evidence
     */
    public function __construct(
        public string $code,
        public array $evidence = [],
    ) {}
}
