<?php

declare(strict_types=1);

namespace App\Domain\Policy\Data;

use App\Domain\Money\Money;

final readonly class PolicyEvaluation
{
    /**
     * @param  list<string>  $requiredRoles
     * @param  list<string>  $approverRoles
     * @param  list<array{id: int|null, name: string, version: int}>  $matchedRules
     * @param  list<string>  $notes
     */
    public function __construct(
        public int $approvalsRequired,
        public array $requiredRoles,
        public array $approverRoles,
        public bool $verificationRequired,
        public int $expiryHours,
        public array $matchedRules,
        public array $notes,
        public ?int $amountBaseMinor,
        public string $baseCurrency,
        public int $eligibleApprovers = 0,
    ) {}

    public function withEligible(int $count): self
    {
        $arguments = get_object_vars($this);
        $arguments['eligibleApprovers'] = $count;

        return new self(...$arguments);
    }

    /** @return list<int> */
    public function ruleIds(): array
    {
        return array_values(array_filter(array_map(static fn (array $rule): ?int => $rule['id'], $this->matchedRules), 'is_int'));
    }

    /**
     * @return array{approvals_required: int, required_roles: list<string>, approver_roles: list<string>, verification_required: bool, expiry_hours: int, matched_rules: list<array{id: int|null, name: string, version: int}>, notes: list<string>, amount_base: array{minor: int, currency: string, formatted: string}|null, eligible_approvers: int}
     */
    public function toArray(?string $locale = null): array
    {
        return [
            'approvals_required' => $this->approvalsRequired,
            'required_roles' => $this->requiredRoles,
            'approver_roles' => $this->approverRoles,
            'verification_required' => $this->verificationRequired,
            'expiry_hours' => $this->expiryHours,
            'matched_rules' => $this->matchedRules,
            'notes' => $this->notes,
            'amount_base' => $this->amountBaseMinor !== null ? Money::of($this->amountBaseMinor, $this->baseCurrency)->toDto($locale ?? app()->getLocale()) : null,
            'eligible_approvers' => $this->eligibleApprovers,
        ];
    }
}
