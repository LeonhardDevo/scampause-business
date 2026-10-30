<?php

declare(strict_types=1);

use App\Domain\Approvals\ApprovalService;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Evidence\Models\EvidenceEntry;
use App\Domain\Intake\Enums\DecisionReason;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Vendors\BankAccountService;
use App\Domain\Vendors\Enums\BankAccountStatus;
use App\Domain\Vendors\Enums\BankScheme;
use App\Domain\Vendors\Models\BlockedBankAccount;
use App\Domain\Vendors\Models\VendorBankAccount;
use App\Domain\Vendors\Models\VendorContact;
use App\Domain\Verification\Data\VerificationInput;
use App\Domain\Verification\Enums\VerificationMethod;
use App\Domain\Verification\Enums\VerificationOutcome;
use App\Domain\Verification\Models\Verification;
use App\Domain\Verification\VerificationService;
use App\Domain\Workflow\SubmissionService;
use App\Domain\Workflow\WorkflowException;
use App\Enums\WorkspaceRole;
use App\Models\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Domain\Scenario;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * @return array{0: Scenario, 1: PaymentRequest, 2: VendorContact}
 */
function bankChangeScenario(array $roles = []): array
{
    $s = $roles === [] ? Scenario::make() : Scenario::make($roles);
    $vendor = $s->vendor();
    $s->verifiedAccount($vendor);
    $contact = VendorContact::query()->withoutGlobalScopes()->where('vendor_id', $vendor->getKey())->where('is_callback', true)->firstOrFail();
    $request = $s->request('bookkeeper', [
        'vendorId' => (int) $vendor->getKey(),
        'kind' => PaymentRequestKind::BankDetailsChange,
        'amountMinor' => 1840000,
        'payeeAccountNumber' => '000483929876',
    ]);

    return [$s, $request, $contact];
}

it('requires a callback for changed bank details and blocks submission parties from recording it', function (): void {
    [$s, $request, $contact] = bankChangeScenario();
    $codes = EvidenceEntry::query()->withoutGlobalScopes()->where('event', 'request.assessed')->orderByDesc('seq')->value('payload')['signals'];

    expect($codes)->toContain('BANK_DETAILS_CHANGED')
        ->and($request->getAttribute('verification_required'))->toBeTrue();

    app(SubmissionService::class)->submit($request, $s->user('bookkeeper'));
    expect($request->refresh()->getAttribute('status'))->toBe(PaymentRequestStatus::AwaitingVerification);

    expect(fn () => app(VerificationService::class)->record($request, $s->user('bookkeeper'), $s->verification($contact)))
        ->toThrow(WorkflowException::class, 'sod_verifier');
});

it('moves a confirmed callback to approval and promotes the account to verified after approval', function (): void {
    [$s, $request, $contact] = bankChangeScenario();
    app(SubmissionService::class)->submit($request, $s->user('bookkeeper'));

    app(VerificationService::class)->record($request, $s->user('admin'), $s->verification($contact));
    $request->refresh();
    $verification = Verification::query()->withoutGlobalScopes()->where('payment_request_id', $request->getKey())->firstOrFail();

    expect($request->getAttribute('status'))->toBe(PaymentRequestStatus::AwaitingApproval)
        ->and($request->getAttribute('satisfying_verification_id'))->toBe((int) $verification->getKey())
        ->and($verification->getAttribute('phone_dialed_last4'))->toBe('0142')
        ->and($verification->getAttribute('evidence_entry_id'))->not->toBeNull();

    $approvals = Approval::query()->withoutGlobalScopes()->where('payment_request_id', $request->getKey())->get();
    expect($approvals->pluck('approver_id')->all())->not->toContain($s->user('bookkeeper')->id);

    foreach ($approvals as $approval) {
        $user = collect($s->users)->first(fn ($u) => $u->id === (int) $approval->getAttribute('approver_id'));
        app(ApprovalService::class)->approve($approval, $user);
    }

    expect($request->refresh()->getAttribute('status'))->toBe(PaymentRequestStatus::Approved)
        ->and(VendorBankAccount::query()->withoutGlobalScopes()->where('vendor_id', $request->getAttribute('vendor_id'))->where('last4', '9876')->value('status'))->toBe(BankAccountStatus::Verified)
        ->and(app(EvidenceLedger::class)->verify($s->workspace->id)['ok'])->toBeTrue();
});

it('rejects as fraud when the vendor denies, blocks the account and counts the prevented amount', function (): void {
    [$s, $request, $contact] = bankChangeScenario();
    app(SubmissionService::class)->submit($request, $s->user('bookkeeper'));

    app(VerificationService::class)->record($request, $s->user('admin'), $s->verification($contact, VerificationOutcome::Denied, DecisionReason::FraudConfirmed));
    $request->refresh();

    expect($request->getAttribute('status'))->toBe(PaymentRequestStatus::Rejected)
        ->and($request->getAttribute('prevented_category')?->value)->toBe('fraud')
        ->and($request->getAttribute('prevented_amount_base_minor'))->toBe(1840000)
        ->and(BlockedBankAccount::query()->withoutGlobalScopes()->where('last4', '9876')->value('reason')?->value)->toBe('fraud_confirmed')
        ->and(OutboxMessage::query()->where('topic', 'email.send')->exists())->toBeTrue();

    $again = $s->request('bookkeeper', ['vendorId' => (int) $request->getAttribute('vendor_id'), 'payeeAccountNumber' => '000483929876']);
    $codes = EvidenceEntry::query()->withoutGlobalScopes()->where('payment_request_id', $again->getKey())->where('event', 'request.assessed')->value('payload')['signals'];

    expect($codes)->toContain('BANK_ACCOUNT_BLOCKLISTED')
        ->and(fn () => app(SubmissionService::class)->submit($again, $s->user('bookkeeper')))->toThrow(WorkflowException::class, 'blocklisted');
});

it('keeps unreachable attempts in awaiting verification', function (): void {
    [$s, $request, $contact] = bankChangeScenario();
    app(SubmissionService::class)->submit($request, $s->user('bookkeeper'));
    app(VerificationService::class)->record($request, $s->user('admin'), $s->verification($contact, VerificationOutcome::Unreachable));

    expect($request->refresh()->getAttribute('status'))->toBe(PaymentRequestStatus::AwaitingVerification)
        ->and(Verification::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('refuses unverified, freshly changed and portal checks for bank changes', function (): void {
    [$s, $request] = bankChangeScenario();
    app(SubmissionService::class)->submit($request, $s->user('bookkeeper'));
    $vendor = $request->vendor()->withoutGlobalScopes()->firstOrFail();
    $pending = VendorContact::query()->withoutGlobalScopes()->forceCreate([
        'workspace_id' => $s->workspace->id, 'vendor_id' => $vendor->getKey(), 'name' => 'Someone', 'phone_e164' => '+18285550100',
        'phone_hash' => str_repeat('a', 64), 'phone_last4' => '0100', 'is_callback' => true, 'status' => 'pending_verification',
        'created_by' => $s->user('bookkeeper')->id, 'source' => 'contract', 'source_ref' => 'Contract §1',
    ]);
    $fresh = $s->callbackContact($vendor, '+18285550111');

    expect(fn () => app(VerificationService::class)->record($request, $s->user('admin'), $s->verification($pending)))->toThrow(WorkflowException::class, 'contact_not_verified')
        ->and(fn () => app(VerificationService::class)->record($request, $s->user('admin'), $s->verification($fresh)))->toThrow(WorkflowException::class, 'callback_contact_recently_changed')
        ->and(fn () => app(VerificationService::class)->record($request, $s->user('admin'), new VerificationInput(
            method: VerificationMethod::VendorPortal, contactId: null, spokenToName: 'Portal', outcome: VerificationOutcome::Confirmed,
            occurredAt: CarbonImmutable::now(), checklist: ['account_last4' => true],
        )))->toThrow(WorkflowException::class, 'method_not_allowed');
});

it('validates the time of the call and the checklist', function (): void {
    [$s, $request, $contact] = bankChangeScenario();
    app(SubmissionService::class)->submit($request, $s->user('bookkeeper'));
    $base = $s->verification($contact);

    expect(fn () => app(VerificationService::class)->record($request, $s->user('admin'), new VerificationInput($base->method, $base->contactId, $base->spokenToName, $base->outcome, CarbonImmutable::now()->addHour(), $base->checklist)))
        ->toThrow(WorkflowException::class, 'occurred_at_out_of_range')
        ->and(fn () => app(VerificationService::class)->record($request, $s->user('admin'), new VerificationInput($base->method, $base->contactId, $base->spokenToName, $base->outcome, $base->occurredAt, ['amount' => true])))
        ->toThrow(WorkflowException::class, 'checklist_last4_required');
});

it('requires four eyes on master-data accounts unless solo', function (): void {
    $s = Scenario::make();
    $vendor = $s->vendor();
    $contact = $s->callbackContact($vendor, '+18285550142', CarbonImmutable::now()->subDays(30));
    $account = app(BankAccountService::class)->add($vendor, $s->user('admin'), [
        'scheme' => BankScheme::Iban, 'account_number' => 'DE89370400440532013000',
    ]);

    expect(fn () => app(VerificationService::class)->verifyAccount($account, $s->user('admin'), $s->verification($contact)))
        ->toThrow(WorkflowException::class, 'four_eyes_required');

    app(VerificationService::class)->verifyAccount($account, $s->user('owner'), $s->verification($contact));
    expect($account->refresh()->getAttribute('status'))->toBe(BankAccountStatus::Verified);
});

it('lets a lone approver verify their own work with sod waived', function (): void {
    $s = Scenario::make([WorkspaceRole::Owner, WorkspaceRole::Bookkeeper, WorkspaceRole::Admin]);
    $vendor = $s->vendor();
    $s->workspace->members()->updateExistingPivot($s->user('admin')->id, ['role' => WorkspaceRole::Bookkeeper->value]);
    $contact = $s->callbackContact($vendor, '+18285550142', CarbonImmutable::now()->subDays(30));
    $account = app(BankAccountService::class)->add($vendor, $s->user('owner'), [
        'scheme' => BankScheme::Iban, 'account_number' => 'DE89370400440532013000',
    ]);
    $verification = app(VerificationService::class)->verifyAccount($account, $s->user('owner'), $s->verification($contact));

    expect($verification->getAttribute('sod_waived'))->toBeTrue()
        ->and($account->refresh()->getAttribute('sod_waived'))->toBeTrue();
});

it('offers the vendor verified account as the one a confirmed bank change replaces', function (): void {
    [$s, $request] = bankChangeScenario();
    $old = VendorBankAccount::query()->withoutGlobalScopes()->where('last4', '4417')->firstOrFail();
    $this->actingAs($s->user('admin'));

    $this->get(route('payment-requests.show', $request->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('verification.replaceable_accounts', 1)
            ->where('verification.replaceable_accounts.0.id', (int) $old->getKey())
            ->where('verification.replaceable_accounts.0.label', fn (string $label): bool => str_ends_with($label, '4417')));
});
