<?php

declare(strict_types=1);

use App\Domain\Approvals\ApproverPool;
use App\Domain\Approvals\Data\ApproverCandidate;
use App\Domain\Approvals\StepTokens;
use App\Domain\Intake\Enums\DecisionReason;
use App\Domain\Intake\Enums\PreventedCategory;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Policy\PolicyService;
use App\Domain\Vendors\BankAccountService;
use App\Domain\Workflow\PreventionLedger;
use App\Domain\Workflow\WorkflowException;
use App\Enums\WorkspaceRole;
use App\Models\User;

function candidate(int $id, WorkspaceRole $role): ApproverCandidate
{
    $user = new User;
    $user->id = $id;
    $user->name = 'User '.$id;

    return new ApproverCandidate($user, $role);
}

it('fills required roles first and then the remaining slots in order', function (): void {
    $eligible = [candidate(3, WorkspaceRole::Approver), candidate(2, WorkspaceRole::Admin), candidate(1, WorkspaceRole::Owner)];
    $picked = ApproverPool::pickSlots($eligible, 2, ['owner'], null);

    expect(array_map(static fn (ApproverCandidate $c): int => $c->user->id, $picked))->toBe([1, 3]);
});

it('accepts a higher role for a required lower role', function (): void {
    $picked = ApproverPool::pickSlots([candidate(1, WorkspaceRole::Owner), candidate(3, WorkspaceRole::Approver)], 2, ['admin'], null);

    expect(array_map(static fn (ApproverCandidate $c): int => $c->user->id, $picked))->toBe([1, 3]);
});

it('returns fewer approvers than slots when not enough are eligible', function (): void {
    expect(ApproverPool::pickSlots([candidate(3, WorkspaceRole::Approver)], 2, [], null))->toHaveCount(1)
        ->and(ApproverPool::pickSlots([candidate(3, WorkspaceRole::Approver)], 1, ['owner'], null))->toHaveCount(1);
});

it('lets the verifier hold at most one slot', function (): void {
    $picked = ApproverPool::pickSlots([candidate(5, WorkspaceRole::Admin), candidate(6, WorkspaceRole::Approver)], 2, [], 5);

    expect(array_map(static fn (ApproverCandidate $c): int => $c->user->id, $picked))->toBe([5, 6]);
});

it('checks that the approvers cover every required role', function (): void {
    expect(ApproverPool::rolesCovered(['owner'], ['admin', 'approver']))->toBeFalse()
        ->and(ApproverPool::rolesCovered(['owner'], ['approver', 'owner']))->toBeTrue()
        ->and(ApproverPool::rolesCovered(['admin'], ['owner']))->toBeTrue()
        ->and(ApproverPool::rolesCovered([], []))->toBeTrue();
});

it('classifies prevented risk by decision reason', function (?DecisionReason $reason, bool $denied, ?PreventedCategory $expected): void {
    expect(PreventionLedger::category($reason, $denied))->toBe($expected);
})->with([
    [DecisionReason::FraudConfirmed, false, PreventedCategory::Fraud],
    [DecisionReason::DetailsMismatch, false, PreventedCategory::Fraud],
    [DecisionReason::NotOrdered, false, PreventedCategory::Fraud],
    [DecisionReason::Duplicate, false, PreventedCategory::Duplicate],
    [DecisionReason::PolicyViolation, false, null],
    [DecisionReason::Other, false, null],
    [DecisionReason::Other, true, PreventedCategory::Fraud],
]);

it('records the prevented amount only when the base amount is known', function (): void {
    $request = new PaymentRequest;
    $request->forceFill(['amount_base_minor' => 1840000]);
    PreventionLedger::apply($request, DecisionReason::FraudConfirmed);

    $unknown = new PaymentRequest;
    $unknown->forceFill(['amount_base_minor' => null]);
    PreventionLedger::apply($unknown, DecisionReason::FraudConfirmed);

    $policy = new PaymentRequest;
    $policy->forceFill(['amount_base_minor' => 500]);
    PreventionLedger::apply($policy, DecisionReason::PolicyViolation);

    expect($request->getAttribute('prevented_amount_base_minor'))->toBe(1840000)
        ->and($request->getAttribute('prevented_category'))->toBe(PreventedCategory::Fraud)
        ->and($unknown->getAttribute('prevented_amount_base_minor'))->toBeNull()
        ->and($unknown->getAttribute('prevented_category'))->toBe(PreventedCategory::Fraud)
        ->and($policy->getAttribute('prevented_category'))->toBeNull();
});

it('marks changed characters of a revealed account number', function (): void {
    expect(BankAccountService::charDiff('000483924417', '000483929876'))->toBe([
        ['text' => '00048392', 'changed' => false],
        ['text' => '9876', 'changed' => true],
    ])
        ->and(BankAccountService::charDiff('1234', '1294'))->toBe([
            ['text' => '12', 'changed' => false],
            ['text' => '9', 'changed' => true],
            ['text' => '4', 'changed' => false],
        ]);
});

it('issues single-use step tokens that only match their own hash', function (): void {
    $a = StepTokens::issue();
    $b = StepTokens::issue();

    expect(strlen($a['token']))->toBe(8)
        ->and(StepTokens::matches($a['token'], $a['hash']))->toBeTrue()
        ->and(StepTokens::matches($b['token'], $a['hash']))->toBeFalse()
        ->and(StepTokens::matches(null, $a['hash']))->toBeFalse()
        ->and(StepTokens::matches($a['token'], null))->toBeFalse();
});

it('normalizes exchange rates to 8 decimal places', function (): void {
    expect(PolicyService::normalizeRate('1.085'))->toBe('1.08500000')
        ->and(PolicyService::normalizeRate('0,011'))->toBe('0.01100000')
        ->and(PolicyService::normalizeRate('12'))->toBe('12.00000000');

    expect(fn () => PolicyService::normalizeRate('0'))->toThrow(WorkflowException::class)
        ->and(fn () => PolicyService::normalizeRate('-1'))->toThrow(WorkflowException::class);
});
