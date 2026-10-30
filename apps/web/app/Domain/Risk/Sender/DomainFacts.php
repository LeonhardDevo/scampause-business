<?php

declare(strict_types=1);

namespace App\Domain\Risk\Sender;

use Carbon\CarbonImmutable;

final readonly class DomainFacts
{
    public function __construct(
        public string $domain,
        public ?CarbonImmutable $registeredAt,
        public ?string $dmarcPolicy,
        public string $rdapStatus,
    ) {}

    public function usable(): bool
    {
        return in_array($this->rdapStatus, ['ok', 'not_found'], true);
    }
}
