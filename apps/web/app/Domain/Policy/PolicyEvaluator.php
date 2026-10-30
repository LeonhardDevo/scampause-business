<?php

declare(strict_types=1);

namespace App\Domain\Policy;

use App\Domain\Policy\Data\PolicyEvaluation;
use App\Domain\Policy\Data\PolicyInput;
use App\Domain\Policy\Data\PolicyRule;

final class PolicyEvaluator
{
    public const array ROLE_ORDER = ['owner', 'admin', 'approver'];

    /**
     * @param  list<PolicyRule>  $rules
     */
    public static function evaluate(array $rules, PolicyInput $input, int $defaultExpiryHours): PolicyEvaluation
    {
        $matched = array_values(array_filter($rules, static fn (PolicyRule $rule): bool => $rule->matches($input)));
        $notes = [];

        if ($input->amountBaseMinor === null) {
            $notes[] = (string) __('domain.policy.notes.fx_missing');
        }

        if ($matched === []) {
            $matched = [PolicyRule::fallback()];
            $notes[] = (string) __('domain.policy.notes.fallback');
        }

        $approvals = 1;
        $required = [];
        $approverRoles = [];
        $verification = false;
        $expiry = null;

        foreach ($matched as $rule) {
            $approvals = max($approvals, $rule->approvalsRequired);
            $required = [...$required, ...$rule->requiredRoles];
            $approverRoles = [...$approverRoles, ...$rule->approverRoles];
            $verification = $verification || $rule->verificationRequired;

            if ($rule->expiryHours !== null) {
                $expiry = $expiry === null ? $rule->expiryHours : min($expiry, $rule->expiryHours);
            }
        }

        $required = self::reduceRequired($required);
        $approverRoles = self::ordered(array_values(array_unique([...$approverRoles, ...$required])));
        $approvals = max($approvals, count($required));

        return new PolicyEvaluation(
            approvalsRequired: $approvals,
            requiredRoles: $required,
            approverRoles: $approverRoles === [] ? self::ROLE_ORDER : $approverRoles,
            verificationRequired: $verification,
            expiryHours: $expiry ?? $defaultExpiryHours,
            matchedRules: array_map(static fn (PolicyRule $rule): array => ['id' => $rule->id, 'name' => $rule->name, 'version' => $rule->version], $matched),
            notes: $notes,
            amountBaseMinor: $input->amountBaseMinor,
            baseCurrency: $input->baseCurrency,
        );
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    public static function reduceRequired(array $roles): array
    {
        $unique = self::ordered(array_values(array_unique($roles)));

        if ($unique === []) {
            return [];
        }

        $highest = $unique[0];

        return [$highest];
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    public static function ordered(array $roles): array
    {
        return array_values(array_filter(self::ROLE_ORDER, static fn (string $role): bool => in_array($role, $roles, true)));
    }

    public static function roleRank(string $role): int
    {
        return match ($role) {
            'owner' => 3,
            'admin' => 2,
            'approver' => 1,
            default => 0,
        };
    }
}
