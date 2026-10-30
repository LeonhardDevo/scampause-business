<?php

declare(strict_types=1);

use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Policy\Data\PolicyInput;
use App\Domain\Policy\Data\PolicyRule;
use App\Domain\Policy\PolicyEvaluator;
use App\Domain\Risk\Enums\RiskLevel;

function northwindRules(): array
{
    return [
        new PolicyRule(id: 1, name: 'Every payment', version: 1),
        new PolicyRule(id: 2, name: 'Dual control', version: 1, minAmountMinor: 300000, approvalsRequired: 2),
        new PolicyRule(id: 3, name: 'Owner sign-off', version: 1, minAmountMinor: 1000000, approvalsRequired: 2, requiredRoles: ['owner']),
        new PolicyRule(id: 4, name: 'Bank changes', version: 1, appliesToBankChange: true, approvalsRequired: 2, requiredRoles: ['admin'], verificationRequired: true),
        new PolicyRule(id: 5, name: 'Payroll', version: 1, kinds: [PaymentRequestKind::PayrollChange], approvalsRequired: 2, requiredRoles: ['owner'], verificationRequired: true),
        new PolicyRule(id: 6, name: 'High risk', version: 1, minRiskLevel: RiskLevel::High, approvalsRequired: 2, verificationRequired: true, expiryHours: 24),
    ];
}

it('aggregates the matching rules by maximum, union and minimum', function (array $input, int $approvals, array $required, bool $verification, array $rules, int $expiry): void {
    $result = PolicyEvaluator::evaluate(northwindRules(), new PolicyInput(...$input), 48);

    expect($result->approvalsRequired)->toBe($approvals)
        ->and($result->requiredRoles)->toBe($required)
        ->and($result->verificationRequired)->toBe($verification)
        ->and($result->ruleIds())->toBe($rules)
        ->and($result->expiryHours)->toBe($expiry);
})->with([
    'small invoice' => [['amountBaseMinor' => 50000], 1, [], false, [1], 48],
    'exactly 3000 (inclusive minimum)' => [['amountBaseMinor' => 300000], 2, [], false, [1, 2], 48],
    'just under 3000' => [['amountBaseMinor' => 299999], 1, [], false, [1], 48],
    '4000 needs a second approver' => [['amountBaseMinor' => 400000], 2, [], false, [1, 2], 48],
    '12000 needs the owner' => [['amountBaseMinor' => 1200000], 2, ['owner'], false, [1, 2, 3], 48],
    'bank change with owner rule keeps owner only' => [['amountBaseMinor' => 1200000, 'hasBankChange' => true], 2, ['owner'], true, [1, 2, 3, 4], 48],
    'bank change small' => [['amountBaseMinor' => 100000, 'hasBankChange' => true], 2, ['admin'], true, [1, 4], 48],
    'new vendor is not a bank change' => [['amountBaseMinor' => 400000, 'isNewVendor' => true], 2, [], false, [1, 2], 48],
    'payroll' => [['amountBaseMinor' => 215000, 'kind' => PaymentRequestKind::PayrollChange], 2, ['owner'], true, [1, 5], 48],
    'high risk shortens expiry' => [['amountBaseMinor' => 50000, 'riskLevel' => RiskLevel::High], 2, [], true, [1, 6], 24],
    'critical counts as at least high' => [['amountBaseMinor' => 50000, 'riskLevel' => RiskLevel::Critical], 2, [], true, [1, 6], 24],
    'missing FX is treated as infinity' => [['amountBaseMinor' => null], 2, ['owner'], false, [1, 2, 3], 48],
]);

it('uses the built-in fallback when nothing matches', function (): void {
    $result = PolicyEvaluator::evaluate([], new PolicyInput(amountBaseMinor: 100), 48);

    expect($result->approvalsRequired)->toBe(1)
        ->and($result->requiredRoles)->toBe([])
        ->and($result->approverRoles)->toBe(['owner', 'admin', 'approver'])
        ->and($result->matchedRules)->toHaveCount(1)
        ->and($result->matchedRules[0]['id'])->toBeNull()
        ->and($result->notes)->toHaveCount(1);
});

it('treats the maximum as exclusive and never matches an infinite amount', function (): void {
    $rule = new PolicyRule(id: 9, name: 'Band', version: 1, minAmountMinor: 100, maxAmountMinor: 500, approvalsRequired: 3);

    expect($rule->matches(new PolicyInput(amountBaseMinor: 100)))->toBeTrue()
        ->and($rule->matches(new PolicyInput(amountBaseMinor: 499)))->toBeTrue()
        ->and($rule->matches(new PolicyInput(amountBaseMinor: 500)))->toBeFalse()
        ->and($rule->matches(new PolicyInput(amountBaseMinor: null)))->toBeFalse();
});

it('drops a required role when a higher role is also required', function (): void {
    expect(PolicyEvaluator::reduceRequired(['admin', 'owner']))->toBe(['owner'])
        ->and(PolicyEvaluator::reduceRequired(['approver', 'admin', 'approver']))->toBe(['admin'])
        ->and(PolicyEvaluator::reduceRequired([]))->toBe([]);
});

it('keeps the two named signal sets distinct', function (): void {
    expect(PolicyInput::bankChangeFrom(['BANK_ACCOUNT_FIRST_USE', 'NEW_VENDOR']))->toBeFalse()
        ->and(PolicyInput::bankChangeFrom(['BANK_DETAILS_CHANGED']))->toBeTrue()
        ->and(PolicyInput::bankChangeFrom(['EMPLOYEE_DENIED_CHANGE']))->toBeTrue()
        ->and(PolicyInput::newVendorFrom(5, ['BANK_ACCOUNT_FIRST_USE']))->toBeTrue()
        ->and(PolicyInput::newVendorFrom(null, []))->toBeTrue()
        ->and(PolicyInput::newVendorFrom(5, []))->toBeFalse()
        ->and(PolicyInput::webOnlySignals())->toContain('BANK_ACCOUNT_FIRST_USE', 'BANK_HOLDER_MISMATCH', 'BANK_DETAILS_CHANGED');
});
