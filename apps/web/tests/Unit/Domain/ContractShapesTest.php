<?php

declare(strict_types=1);

use App\Domain\Intake\Data\ExtractionResult;
use App\Domain\Intake\Enums\ExtractionStatus;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Enums\PaymentRequestStatus as S;
use App\Domain\Intake\Enums\PipelineStage;
use App\Domain\Intake\Exceptions\InvalidTransition;
use App\Domain\Intake\StateMachine;
use App\Domain\Vendors\Enums\BankScheme;
use App\Enums\WorkspaceRole;

it('allows exactly the documented payment request transitions', function (S $from, S $to, bool $allowed): void {
    expect(StateMachine::can($from, $to))->toBe($allowed);
})->with([
    [S::Received, S::Processing, true],
    [S::Received, S::Review, true],
    [S::Processing, S::Review, true],
    [S::Review, S::AwaitingVerification, true],
    [S::Review, S::AwaitingApproval, true],
    [S::Review, S::Rejected, true],
    [S::AwaitingVerification, S::AwaitingApproval, true],
    [S::AwaitingApproval, S::Approved, true],
    [S::AwaitingApproval, S::Expired, true],
    [S::AwaitingApproval, S::AwaitingVerification, true],
    [S::Expired, S::AwaitingApproval, true],
    [S::Approved, S::Paid, true],
    [S::Approved, S::Rejected, true],
    [S::Paid, S::Rejected, false],
    [S::Rejected, S::Review, false],
    [S::Cancelled, S::Review, false],
    [S::Processing, S::Approved, false],
    [S::Review, S::Approved, false],
    [S::Expired, S::Paid, false],
]);

it('throws a typed invalid transition', function (): void {
    StateMachine::assert(S::Paid, S::Review);
})->throws(InvalidTransition::class);

it('accepts worker stage aliases', function (): void {
    expect(PipelineStage::fromWire('domain-check'))->toBe(PipelineStage::Domain)
        ->and(PipelineStage::fromWire('bank-compare'))->toBe(PipelineStage::Bank)
        ->and(PipelineStage::fromWire('nope'))->toBeNull();
});

it('parses the ExtractionResult DTO defensively', function (): void {
    $result = ExtractionResult::fromArray([
        'workspace_id' => 1,
        'payment_request_id' => 2,
        'revision' => 1,
        'status' => 'partial',
        'extractor_version' => 'worker-extract@1.0.0',
        'ocr_used' => true,
        'pages' => 2,
        'text' => 'Invoice INV-42',
        'fields' => [
            'vendor_name' => ['value' => 'Blue Ridge', 'confidence' => 80, 'source' => 'text'],
            'amount_minor' => ['value' => 1840000, 'confidence' => 75, 'source' => 'llm_assist'],
            'currency' => ['value' => 'usd', 'confidence' => 90, 'source' => 'text'],
            'due_date' => ['value' => '2026-02-30', 'confidence' => 90, 'source' => 'text'],
            'invoice_date' => ['value' => '2026-10-12', 'confidence' => 90, 'source' => 'text'],
            'payee' => [
                'scheme' => ['value' => 'iban', 'confidence' => 90, 'source' => 'text'],
                'account_number' => ['value' => 'DE89370400440532013000', 'confidence' => 90, 'source' => 'ocr'],
                'valid_checksum' => true,
            ],
            'sender' => ['from_email' => 'AR@blueridge.com', 'auth' => ['spf' => 'pass', 'dkim' => 'bogus', 'dmarc' => null], 'to' => ['a@b.c', 5], 'received_hops' => 3],
            'emails' => ['x@y.z', 'x@y.z', ''],
            'qr' => [['page' => 1, 'format' => 'st00012', 'fields' => ['Sum' => 100]]],
            'kind_suggestion' => 'bank_details_change',
        ],
        'warnings' => ['LOW_CONFIDENCE_AMOUNT', 'bad warning!'],
        'error_code' => null,
        'duration_ms' => 1200,
        'llm_assisted' => true,
        'files' => [['file_id' => 5, 'pages' => 2, 'ocr_used' => true], ['file_id' => 'x']],
    ]);

    expect($result->status)->toBe(ExtractionStatus::Partial)
        ->and($result->fields->amountMinor?->confidence)->toBe(60)
        ->and($result->fields->dueDate)->toBeNull()
        ->and($result->fields->invoiceDate?->value)->toBe('2026-10-12')
        ->and($result->fields->payee?->scheme)->toBe(BankScheme::Iban)
        ->and($result->fields->sender?->dkim)->toBeNull()
        ->and($result->fields->sender?->to)->toBe(['a@b.c'])
        ->and($result->fields->emails)->toBe(['x@y.z'])
        ->and($result->fields->qr[0]['fields'])->toBe(['Sum' => '100'])
        ->and($result->fields->kindSuggestion)->toBe(PaymentRequestKind::BankDetailsChange)
        ->and($result->fields->llmAssistedFields())->toBe(['amount'])
        ->and($result->warnings)->toBe(['LOW_CONFIDENCE_AMOUNT'])
        ->and($result->files)->toBe([['file_id' => 5, 'pages' => 2, 'ocr_used' => true]]);
});

it('extends the workspace roles with the domain hierarchy', function (): void {
    expect(WorkspaceRole::values())->toBe(['owner', 'admin', 'approver', 'bookkeeper', 'member', 'auditor'])
        ->and(WorkspaceRole::Owner->satisfiesApprovalRole(WorkspaceRole::Admin))->toBeTrue()
        ->and(WorkspaceRole::Approver->satisfiesApprovalRole(WorkspaceRole::Admin))->toBeFalse()
        ->and(WorkspaceRole::Bookkeeper->satisfiesApprovalRole(WorkspaceRole::Approver))->toBeFalse()
        ->and(WorkspaceRole::Bookkeeper->rank())->toBe(30)
        ->and(WorkspaceRole::Auditor->rank())->toBe(20);
});

it('grants the permission matrix of the contract', function (WorkspaceRole $role, array $granted): void {
    $domain = array_values(array_filter(
        (array) config('permissions.abilities'),
        static fn (string $ability): bool => str_starts_with($ability, 'payment_requests.') || str_starts_with($ability, 'vendors.')
            || in_array(explode('.', $ability)[0], ['bank_details', 'blocklist', 'policies', 'reports', 'evidence'], true),
    ));

    foreach ($domain as $ability) {
        expect($role->allows($ability))->toBe(in_array($ability, $granted, true), $role->value.' '.$ability);
    }
})->with([
    'owner' => [WorkspaceRole::Owner, [
        'payment_requests.create', 'payment_requests.view_all', 'payment_requests.review', 'payment_requests.verify', 'payment_requests.approve',
        'payment_requests.cancel', 'payment_requests.mark_paid', 'payment_requests.revoke', 'payment_requests.reassign', 'bank_details.reveal',
        'vendors.view', 'vendors.manage', 'vendors.view_bank', 'vendors.verify_bank', 'blocklist.manage', 'policies.view', 'policies.manage',
        'reports.view', 'reports.export', 'evidence.verify',
    ]],
    'admin' => [WorkspaceRole::Admin, [
        'payment_requests.create', 'payment_requests.view_all', 'payment_requests.review', 'payment_requests.verify', 'payment_requests.approve',
        'payment_requests.cancel', 'payment_requests.mark_paid', 'payment_requests.revoke', 'payment_requests.reassign', 'bank_details.reveal',
        'vendors.view', 'vendors.manage', 'vendors.view_bank', 'vendors.verify_bank', 'blocklist.manage', 'policies.view', 'policies.manage',
        'reports.view', 'reports.export', 'evidence.verify',
    ]],
    'approver' => [WorkspaceRole::Approver, [
        'payment_requests.create', 'payment_requests.view_all', 'payment_requests.review', 'payment_requests.verify', 'payment_requests.approve',
        'vendors.view', 'vendors.view_bank', 'policies.view', 'reports.view',
    ]],
    'bookkeeper' => [WorkspaceRole::Bookkeeper, [
        'payment_requests.create', 'payment_requests.view_all', 'payment_requests.review', 'payment_requests.verify', 'payment_requests.mark_paid',
        'bank_details.reveal', 'vendors.view', 'vendors.manage', 'vendors.view_bank', 'policies.view', 'reports.view', 'reports.export',
    ]],
    'member' => [WorkspaceRole::Member, ['payment_requests.create']],
    'auditor' => [WorkspaceRole::Auditor, [
        'payment_requests.view_all', 'vendors.view', 'vendors.view_bank', 'policies.view', 'reports.view', 'reports.export', 'evidence.verify',
    ]],
]);

it('grants the foundation abilities required for the new roles', function (): void {
    foreach (WorkspaceRole::cases() as $role) {
        expect($role->allows('integrations.manage'))->toBeTrue()
            ->and($role->allows('assistant.use'))->toBeTrue()
            ->and($role->allows('knowledge.view'))->toBeTrue();
    }

    expect(WorkspaceRole::Auditor->allows('assistant.history'))->toBeTrue()
        ->and(WorkspaceRole::Auditor->allows('audit.view'))->toBeTrue()
        ->and(WorkspaceRole::Approver->allows('billing.view'))->toBeFalse()
        ->and(WorkspaceRole::Bookkeeper->allows('knowledge.manage'))->toBeTrue();
});
