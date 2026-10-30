<?php

declare(strict_types=1);

use App\Domain\Approvals\Models\Approval;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Evidence\Models\EvidenceEntry;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Enums\PreventedCategory;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Reports\Enums\ExportStatus;
use App\Domain\Reports\ExportService;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Vendors\Models\VendorContact;
use App\Domain\Verification\Models\Verification;
use App\Domain\Workflow\SubmissionService;
use App\Enums\WorkspaceRole;
use App\Models\OutboxMessage;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\Domain\Scenario;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * @return array{0: Scenario, 1: PaymentRequest, 2: VendorContact}
 */
function routesScenario(bool $bankChange = false): array
{
    $s = Scenario::make([WorkspaceRole::Owner, WorkspaceRole::Admin, WorkspaceRole::Approver, WorkspaceRole::Bookkeeper, WorkspaceRole::Member, WorkspaceRole::Auditor]);
    $vendor = $s->vendor();
    $s->verifiedAccount($vendor);
    $contact = VendorContact::query()->withoutGlobalScopes()->where('vendor_id', $vendor->getKey())->where('is_callback', true)->firstOrFail();
    $overrides = ['vendorId' => (int) $vendor->getKey()];

    if ($bankChange) {
        $overrides = [...$overrides, 'kind' => PaymentRequestKind::BankDetailsChange, 'amountMinor' => 1840000, 'payeeAccountNumber' => '000483929876'];
    }

    return [$s, $s->request('bookkeeper', $overrides), $contact];
}

/**
 * @return array<string, mixed>
 */
function callbackPayload(VendorContact $contact, string $outcome = 'confirmed', array $extra = []): array
{
    return [
        'method' => 'callback_known_number',
        'contact_id' => $contact->getKey(),
        'spoken_to_name' => 'Karen Whitfield',
        'outcome' => $outcome,
        'occurred_at' => CarbonImmutable::now()->subMinutes(2)->toIso8601String(),
        'checklist' => ['account_last4' => true, 'amount' => true, 'change_initiated_by_vendor' => true],
        ...$extra,
    ];
}

it('runs submit, callback, approvals, payment and evidence packet through the web routes', function (): void {
    [$s, $request, $contact] = routesScenario(bankChange: true);
    $id = $request->getKey();

    $this->actingAs($s->user('member'))->post(route('payment-requests.submit', $id))->assertForbidden();
    $this->actingAs($s->user('bookkeeper'))->post(route('payment-requests.submit', $id))->assertRedirect();
    expect($request->refresh()->getAttribute('status'))->toBe(PaymentRequestStatus::AwaitingVerification);

    $this->actingAs($s->user('bookkeeper'))->post(route('payment-requests.verifications.store', $id), callbackPayload($contact))->assertSessionHasErrors();
    $this->actingAs($s->user('admin'))->post(route('payment-requests.verifications.store', $id), callbackPayload($contact, 'confirmed', ['checklist' => ['amount' => true]]))->assertSessionHasErrors();
    $this->actingAs($s->user('admin'))->post(route('payment-requests.verifications.store', $id), callbackPayload($contact))->assertSessionHasNoErrors();
    expect($request->refresh()->getAttribute('status'))->toBe(PaymentRequestStatus::AwaitingApproval);

    foreach (Approval::query()->withoutGlobalScopes()->where('payment_request_id', $id)->where('status', 'pending')->orderBy('slot')->get() as $approval) {
        $approver = $s->workspace->members()->whereKey($approval->getAttribute('approver_id'))->firstOrFail();
        $this->actingAs($approver)->post(route('payment-requests.approvals.approve', ['paymentRequest' => $id, 'approval' => $approval->getKey()]))->assertSessionHasNoErrors();
    }

    expect($request->refresh()->getAttribute('status'))->toBe(PaymentRequestStatus::Approved);

    $this->actingAs($s->user('member'))->post(route('payment-requests.mark-paid', $id), ['paid_at' => CarbonImmutable::now()->toIso8601String(), 'payment_reference' => 'ACH 1023-BRP'])->assertForbidden();
    $this->actingAs($s->user('bookkeeper'))->post(route('payment-requests.mark-paid', $id), ['paid_at' => CarbonImmutable::now()->addDay()->toIso8601String(), 'payment_reference' => 'ACH 1023-BRP'])->assertSessionHasErrors('paid_at');
    $this->actingAs($s->user('bookkeeper'))->post(route('payment-requests.mark-paid', $id), ['paid_at' => CarbonImmutable::now()->toIso8601String(), 'payment_reference' => 'ACH 1023-BRP'])->assertSessionHasNoErrors();

    $paid = $request->refresh();
    expect($paid->getAttribute('status'))->toBe(PaymentRequestStatus::Paid)
        ->and($paid->getAttribute('payment_reference'))->toBe('ACH 1023-BRP')
        ->and(EvidenceEntry::query()->withoutGlobalScopes()->where('payment_request_id', $id)->where('event', 'request.paid')->value('payload'))->toBe(['payment_reference_last4' => '-BRP']);

    ReportExport::query()->withoutGlobalScopes()->where('payment_request_id', $id)->delete();
    $this->actingAs($s->user('auditor'))->post(route('payment-requests.evidence-packet', $id))->assertSessionHasNoErrors();
    expect(ReportExport::query()->withoutGlobalScopes()->where('payment_request_id', $id)->where('status', ExportStatus::Queued->value)->count())->toBe(1)
        ->and(OutboxMessage::query()->where('topic', 'evidence.packet.requested')->exists())->toBeTrue()
        ->and(app(EvidenceLedger::class)->verify($s->workspace->id)['ok'])->toBeTrue();
});

it('reveals full bank details only to permitted roles and records the governance event', function (): void {
    [$s, $request] = routesScenario(bankChange: true);
    $url = route('payment-requests.reveal', $request->getKey());

    $this->actingAs($s->user('auditor'))->postJson($url)->assertForbidden();
    $this->actingAs($s->user('member'))->postJson($url)->assertForbidden();

    $response = $this->actingAs($s->user('admin'))->postJson($url)->assertOk();

    expect($response->json('incoming.account'))->toBe('000483929876')
        ->and($response->json('previous.account'))->toBe('000483924417')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(EvidenceEntry::query()->withoutGlobalScopes()->where('workspace_id', $s->workspace->id)->where('event', 'bank_account.revealed')->value('payload'))->toBe(['last4' => '9876'])
        ->and(DB::table('audit_logs')->where('action', 'payment_request.bank_details_revealed')->count())->toBe(1);
});

it('rejects from review with prevented accounting and lets only permitted users cancel', function (): void {
    [$s, $request] = routesScenario(bankChange: true);
    $id = $request->getKey();

    $this->actingAs($s->user('admin'))->post(route('payment-requests.reject', $id), ['reason' => 'other'])->assertSessionHasErrors('comment');
    $this->actingAs($s->user('admin'))->post(route('payment-requests.reject', $id), ['reason' => 'fraud_confirmed'])->assertSessionHasNoErrors();

    $rejected = $request->refresh();
    expect($rejected->getAttribute('status'))->toBe(PaymentRequestStatus::Rejected)
        ->and($rejected->getAttribute('prevented_category'))->toBe(PreventedCategory::Fraud)
        ->and($rejected->getAttribute('prevented_amount_base_minor'))->toBe(1840000)
        ->and(DB::table('blocked_bank_accounts')->where('workspace_id', $s->workspace->id)->where('last4', '9876')->exists())->toBeTrue();

    [$s2, $other] = routesScenario();
    $this->actingAs($s2->user('member'))->post(route('payment-requests.cancel', $other->getKey()), ['reason' => 'withdrawn'])->assertForbidden();
    $this->actingAs($s2->user('bookkeeper'))->post(route('payment-requests.cancel', $other->getKey()), ['reason' => 'other'])->assertSessionHasErrors('comment');
    $this->actingAs($s2->user('bookkeeper'))->post(route('payment-requests.cancel', $other->getKey()), ['reason' => 'duplicate_upload'])->assertSessionHasNoErrors();

    expect($other->refresh()->getAttribute('status'))->toBe(PaymentRequestStatus::Cancelled);
});

it('revokes an approved request with a reason and a comment', function (): void {
    [$s, $request] = routesScenario();
    app(SubmissionService::class)->submit($request, $s->user('bookkeeper'));

    foreach (Approval::query()->withoutGlobalScopes()->where('payment_request_id', $request->getKey())->where('status', 'pending')->get() as $approval) {
        $approver = $s->workspace->members()->whereKey($approval->getAttribute('approver_id'))->firstOrFail();
        $this->actingAs($approver)->post(route('payment-requests.approvals.approve', ['paymentRequest' => $request->getKey(), 'approval' => $approval->getKey()]));
    }

    expect($request->refresh()->getAttribute('status'))->toBe(PaymentRequestStatus::Approved);

    $this->actingAs($s->user('approver'))->post(route('payment-requests.revoke', $request->getKey()), ['reason' => 'not_ordered', 'comment' => 'Goods were never ordered'])->assertForbidden();
    $this->actingAs($s->user('owner'))->post(route('payment-requests.revoke', $request->getKey()), ['reason' => 'not_ordered'])->assertSessionHasErrors('comment');
    $this->actingAs($s->user('owner'))->post(route('payment-requests.revoke', $request->getKey()), ['reason' => 'not_ordered', 'comment' => 'Goods were never ordered'])->assertSessionHasNoErrors();

    $revoked = $request->refresh();
    expect($revoked->getAttribute('status'))->toBe(PaymentRequestStatus::Rejected)
        ->and($revoked->getAttribute('prevented_category'))->toBe(PreventedCategory::Fraud)
        ->and(EvidenceEntry::query()->withoutGlobalScopes()->where('payment_request_id', $request->getKey())->where('event', 'request.revoked')->exists())->toBeTrue();
});

it('queues journal exports and serves ready files only on plans with evidence exports', function (): void {
    [$s] = routesScenario();
    Subscription::query()->updateOrCreate(['workspace_id' => $s->workspace->id], ['plan' => 'pro', 'status' => 'active', 'interval' => 'month', 'current_period_end' => now()->addMonth()]);
    $payload = ['kind' => 'audit_journal', 'format' => 'csv', 'from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString()];

    $this->actingAs($s->user('member'))->post(route('reports.exports.store'), $payload)->assertForbidden();
    $this->actingAs($s->user('owner'))->post(route('reports.exports.store'), [...$payload, 'to' => now()->subDays(40)->toDateString()])->assertSessionHasErrors('to');
    $this->actingAs($s->user('owner'))->post(route('reports.exports.store'), $payload)->assertSessionHasNoErrors();

    $export = ReportExport::query()->withoutGlobalScopes()->where('workspace_id', $s->workspace->id)->where('kind', 'audit_journal')->sole();
    expect(OutboxMessage::query()->where('topic', 'audit.journal.requested')->exists())->toBeTrue();

    app(ExportService::class)->storeFile($export, "id;event\n1;request.created\n", hash('sha256', "id;event\n1;request.created\n"), true);
    $url = URL::temporarySignedRoute('reports.exports.download', now()->addMinutes(5), ['export' => $export->getKey()]);

    $this->actingAs($s->user('owner'))->get(route('reports.exports.download', ['export' => $export->getKey()]))->assertForbidden();
    $download = $this->actingAs($s->user('owner'))->get($url)->assertOk();
    expect($download->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($download->getContent())->toContain('request.created');

    Subscription::query()->where('workspace_id', $s->workspace->id)->update(['plan' => 'free']);
    $this->actingAs($s->user('owner'))->get($url)->assertStatus(402);
});

it('finds, exports and redacts a vendor contact for the owner while the chain stays valid', function (): void {
    [$s, $request, $contact] = routesScenario(bankChange: true);
    app(SubmissionService::class)->submit($request, $s->user('bookkeeper'));
    $this->actingAs($s->user('admin'))->post(route('payment-requests.verifications.store', $request->getKey()), callbackPayload($contact, 'unreachable', ['checklist' => []]));
    $vendorId = $contact->getAttribute('vendor_id');
    $url = route('vendors.data-subject', $vendorId);
    $subject = ['type' => 'contact', 'id' => $contact->getKey()];

    $this->actingAs($s->user('admin'))->post($url, ['action' => 'find', 'subject' => $subject])->assertForbidden();
    $this->actingAs($s->user('owner'))->post($url, ['action' => 'find', 'subject' => $subject])->assertSessionHasNoErrors()->assertSessionHas('success');

    $export = $this->actingAs($s->user('owner'))->post($url, ['action' => 'export', 'subject' => $subject])->assertOk();
    expect($export->json('contacts.0.name'))->toBe('Karen Whitfield')
        ->and($export->json('verifications'))->not->toBeEmpty();

    $this->actingAs($s->user('owner'))->post($url, ['action' => 'redact', 'subject' => $subject])->assertSessionHasNoErrors();

    $redacted = VendorContact::query()->withoutGlobalScopes()->withTrashed()->findOrFail($contact->getKey());
    expect($redacted->getAttribute('phone_e164'))->toBeNull()
        ->and($redacted->trashed())->toBeTrue()
        ->and(Verification::query()->withoutGlobalScopes()->where('contact_id', $contact->getKey())->pluck('spoken_to_name')->filter()->all())->toBe([])
        ->and(EvidenceEntry::query()->withoutGlobalScopes()->where('workspace_id', $s->workspace->id)->where('event', 'privacy.subject_redacted')->exists())->toBeTrue()
        ->and(app(EvidenceLedger::class)->verify($s->workspace->id)['ok'])->toBeTrue();

    $this->actingAs($s->user('owner'))->post($url, ['action' => 'find', 'subject' => ['type' => 'sender']])->assertSessionHasErrors();
});
