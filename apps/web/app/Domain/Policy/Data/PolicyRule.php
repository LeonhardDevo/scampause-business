<?php

declare(strict_types=1);

namespace App\Domain\Policy\Data;

use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Policy\Models\ApprovalRule;
use App\Domain\Risk\Enums\RiskLevel;

final readonly class PolicyRule
{
    /**
     * @param  list<PaymentRequestKind>|null  $kinds
     * @param  list<string>  $requiredRoles
     * @param  list<string>  $approverRoles
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public int $version,
        public ?int $minAmountMinor = null,
        public ?int $maxAmountMinor = null,
        public ?array $kinds = null,
        public ?RiskLevel $minRiskLevel = null,
        public ?bool $appliesToNewVendor = null,
        public ?bool $appliesToBankChange = null,
        public int $approvalsRequired = 1,
        public array $requiredRoles = [],
        public array $approverRoles = ['owner', 'admin', 'approver'],
        public bool $verificationRequired = false,
        public ?int $expiryHours = null,
    ) {}

    public static function fromModel(ApprovalRule $rule): self
    {
        $kinds = $rule->getAttribute('kinds');
        $level = $rule->getAttribute('min_risk_level');

        return new self(
            id: (int) $rule->getKey(),
            name: (string) $rule->getAttribute('name'),
            version: (int) $rule->getAttribute('version'),
            minAmountMinor: is_int($min = $rule->getAttribute('min_amount_base_minor')) ? $min : null,
            maxAmountMinor: is_int($max = $rule->getAttribute('max_amount_base_minor')) ? $max : null,
            kinds: is_array($kinds) ? array_values(array_filter(array_map(static fn (mixed $kind): ?PaymentRequestKind => is_string($kind) ? PaymentRequestKind::tryFrom($kind) : null, $kinds))) : null,
            minRiskLevel: $level instanceof RiskLevel ? $level : null,
            appliesToNewVendor: is_bool($n = $rule->getAttribute('applies_to_new_vendor')) ? $n : null,
            appliesToBankChange: is_bool($b = $rule->getAttribute('applies_to_bank_change')) ? $b : null,
            approvalsRequired: max(1, (int) $rule->getAttribute('approvals_required')),
            requiredRoles: self::roles($rule->getAttribute('required_roles')),
            approverRoles: self::roles($rule->getAttribute('approver_roles')),
            verificationRequired: (bool) $rule->getAttribute('verification_required'),
            expiryHours: is_int($hours = $rule->getAttribute('expiry_hours')) ? $hours : null,
        );
    }

    public static function fallback(): self
    {
        return new self(id: null, name: (string) __('domain.policy.fallback_rule'), version: 1);
    }

    public function matches(PolicyInput $input): bool
    {
        if ($this->minAmountMinor !== null && $input->amountBaseMinor !== null && $input->amountBaseMinor < $this->minAmountMinor) {
            return false;
        }

        if ($this->maxAmountMinor !== null && ($input->amountBaseMinor === null || $input->amountBaseMinor >= $this->maxAmountMinor)) {
            return false;
        }

        if ($this->kinds !== null && $this->kinds !== [] && ! in_array($input->kind, $this->kinds, true)) {
            return false;
        }

        if ($this->minRiskLevel !== null && ! $input->riskLevel->atLeast($this->minRiskLevel)) {
            return false;
        }

        if ($this->appliesToNewVendor === true && ! $input->isNewVendor) {
            return false;
        }

        return ! ($this->appliesToBankChange === true && ! $input->hasBankChange);
    }

    /** @return list<string> */
    private static function roles(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, static fn (mixed $role): bool => is_string($role) && in_array($role, ['owner', 'admin', 'approver'], true))) : [];
    }
}
