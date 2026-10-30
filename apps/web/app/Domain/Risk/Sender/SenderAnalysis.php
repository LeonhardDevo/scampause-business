<?php

declare(strict_types=1);

namespace App\Domain\Risk\Sender;

use App\Domain\Risk\SignalHit;

final readonly class SenderAnalysis
{
    /**
     * @param  list<array{domain: string, unicode: string|null, role: string, known_for_vendor: bool, lookalike_of: string|null, technique: string|null, registered_at: string|null, age_days: int|null, dmarc_policy: string|null, free_mail: bool}>  $domains
     * @param  list<SignalHit>  $hits
     * @param  list<string>  $notes
     */
    public function __construct(
        public array $domains,
        public array $hits,
        public array $notes,
        public ?string $fromDomain,
        public ?string $replyToDomain,
    ) {}

    /** @return list<string> */
    public function domainNames(): array
    {
        return array_values(array_unique(array_map(static fn (array $row): string => $row['domain'], $this->domains)));
    }
}
