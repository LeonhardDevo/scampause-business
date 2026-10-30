<?php

declare(strict_types=1);

namespace App\Domain\Verification\Data;

use App\Domain\Intake\Enums\DecisionReason;
use App\Domain\Verification\Enums\VerificationMethod;
use App\Domain\Verification\Enums\VerificationOutcome;
use Carbon\CarbonImmutable;

final readonly class VerificationInput
{
    public const array CHECKLIST = ['account_last4', 'amount', 'account_holder', 'change_initiated_by_vendor', 'invoice_number'];

    /**
     * @param  array<string, bool>  $checklist
     */
    public function __construct(
        public VerificationMethod $method,
        public ?int $contactId,
        public string $spokenToName,
        public VerificationOutcome $outcome,
        public CarbonImmutable $occurredAt,
        public array $checklist = [],
        public ?DecisionReason $decisionReason = null,
        public ?int $replacesAccountId = null,
        public ?string $notes = null,
    ) {}

    /** @return array<string, bool> */
    public function normalizedChecklist(): array
    {
        $out = [];

        foreach (self::CHECKLIST as $key) {
            $out[$key] = (bool) ($this->checklist[$key] ?? false);
        }

        return $out;
    }
}
