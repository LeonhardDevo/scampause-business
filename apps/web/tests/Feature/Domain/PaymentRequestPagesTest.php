<?php

declare(strict_types=1);

use App\Domain\Evidence\Models\EvidenceEntry;
use App\Domain\Intake\Data\ManualEntry;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\IntakeService;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\Models\PaymentRequestFile;
use App\Domain\Vendors\Enums\BankScheme;
use App\Enums\WorkspaceRole;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Models\Workspace;
use Database\Factories\Domain\VendorFactory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Storage::fake('local');
    config(['scampause.fingerprint_key' => base64_encode('test-fingerprint-key'), 'inertia.testing.ensure_pages_exist' => false]);
});

function spManual(User $user, Workspace $workspace, array $overrides = []): PaymentRequest
{
    $entry = new ManualEntry(...array_merge([
        'provided' => ['title', 'kind', 'amount', 'currency', 'payee'],
        'title' => 'Invoice INV-42',
        'kind' => PaymentRequestKind::Invoice,
        'amountMinor' => 125000,
        'currency' => 'USD',
        'invoiceNumber' => 'INV-42',
        'payeeScheme' => BankScheme::Iban,
        'payeeAccountNumber' => 'DE89370400440532013000',
        'payeeAccountHolder' => 'Landlord GmbH',
    ], $overrides));

    return app(IntakeService::class)->createManual($workspace, $user, $entry)->refresh();
}

it('renders the index with rows, counts and filters', function (): void {
    [$user, $workspace] = $this->signInMember(WorkspaceRole::Bookkeeper);
    $vendor = VendorFactory::new()->create(['workspace_id' => $workspace->id, 'created_by' => $user->id]);
    spManual($user, $workspace);
    spManual($user, $workspace, ['title' => 'Second invoice', 'vendorId' => $vendor->id, 'provided' => ['title', 'vendor_id']]);

    $this->get(route('payment-requests.index', ['status' => 'open']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payment-requests/Index')
            ->has('requests.data', 2)
            ->has('requests.data.0', fn (Assert $row) => $row
                ->hasAll(['id', 'reference', 'title', 'kind', 'source', 'status', 'vendor', 'amount', 'risk', 'verification_required', 'due_date', 'expires_at', 'requested_by', 'created_at', 'my_pending_approval_id'])
                ->where('reference', 'SP-00002')
                ->where('vendor.id', $vendor->id)
                ->where('status', 'review')
                ->etc())
            ->where('requests.data.1.amount', ['minor' => 125000, 'currency' => 'USD', 'formatted' => '$1,250.00'])
            ->where('counts', ['open' => 2, 'review' => 2, 'awaiting_verification' => 0, 'awaiting_approval' => 0, 'decided' => 0])
            ->where('filters', ['status' => 'open'])
            ->has('vendors', 1)
            ->has('requests.meta')
            ->has('requests.links'));
});

it('shows members only their own requests', function (): void {
    [$owner, $workspace] = $this->member(WorkspaceRole::Owner);
    [$member] = $this->member(WorkspaceRole::Member, $workspace);
    spManual($owner, $workspace);
    $mine = spManual($member, $workspace, ['title' => 'Mine']);
    $this->actingAs($member);

    $this->get(route('payment-requests.index'))
        ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.id', $mine->id)->where('counts.open', 1));

    $this->get(route('payment-requests.show', PaymentRequest::query()->where('requested_by', $owner->id)->value('id')))->assertForbidden();
    $this->get(route('payment-requests.show', $mine->id))->assertOk();
});

it('hides other workspaces', function (): void {
    [$owner, $workspace] = $this->member();
    $request = spManual($owner, $workspace);
    $this->signInMember();

    $this->get(route('payment-requests.show', $request->id))->assertNotFound();
    $this->get(route('payment-requests.status', $request->id))->assertNotFound();
    $this->patch(route('payment-requests.update', $request->id), ['title' => 'Hijack'])->assertNotFound();
});

it('renders the create page props', function (): void {
    $this->signInMember();

    $this->get(route('payment-requests.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payment-requests/Create')
            ->where('accept', ['.pdf', '.png', '.jpg', '.jpeg', '.webp', '.eml'])
            ->where('maxFiles', 5)
            ->where('maxSizeMb', 15)
            ->where('base_currency', 'USD')
            ->where('limit', ['used' => 0, 'limit' => 15])
            ->has('kinds', 6)
            ->has('currencies')
            ->has('vendors', 0));
});

it('renders every case-file prop', function (): void {
    [$user, $workspace] = $this->signInMember();
    $request = spManual($user, $workspace);

    $this->get(route('payment-requests.show', $request->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payment-requests/Show')
            ->hasAll([
                'request', 'pipeline', 'extraction', 'risk', 'bank_diff', 'sender', 'verification', 'route', 'past_routes', 'policy',
                'timeline', 'deliveries', 'files', 'preview_pages', 'source_excerpt', 'vendor_candidates', 'evidence_packet', 'options', 'abilities',
            ])
            ->has('request', fn (Assert $r) => $r
                ->hasAll(['description', 'invoice_number', 'invoice_date', 'amount_base', 'fx', 'vendor_match', 'payee', 'payee_checksum_valid',
                    'submitted_by', 'submitted_at', 'decided_by', 'decided_at', 'decision_reason', 'decision_note', 'paid_at', 'payment_reference',
                    'prevented', 'extraction_failed', 'employee_response', 'blocklisted'])
                ->where('payee.masked', 'DE89 •••• •••• 3000')
                ->where('payee.holder_masked', 'Landlo•••')
                ->where('payee_checksum_valid', true)
                ->etc())
            ->has('risk', fn (Assert $risk) => $risk->hasAll(['score', 'level', 'rules_version', 'floor_signal', 'assessed_at', 'signals'])->where('rules_version', '2026.10.2'))
            ->has('risk.signals.0', fn (Assert $signal) => $signal->hasAll(['code', 'category', 'severity', 'points', 'title', 'explanation', 'advice', 'evidence']))
            ->where('bank_diff.outcome', 'unmatched_vendor')
            ->has('bank_diff.rows', 8)
            ->where('sender', null)
            ->has('verification', fn (Assert $v) => $v->hasAll(['required', 'reasons', 'satisfied', 'attempts', 'callback_contacts', 'vendor_has_callback_contact', 'replaceable_accounts']))
            ->where('route', null)
            ->where('past_routes', [])
            ->has('timeline', 2)
            ->where('timeline.0.event', 'request.created')
            ->where('timeline.1.label', 'Risk signals assessed')
            ->has('options.verification_methods', 5)
            ->where('abilities.review', true)
            ->where('abilities.submit', true)
            ->where('abilities.verify', false)
            ->where('abilities.reveal', true)
            ->where('pipeline.status', 'review')
            ->has('pipeline.stages.extract'));
});

it('returns the pipeline status as JSON for polling', function (): void {
    [$user, $workspace] = $this->signInMember();
    $request = spManual($user, $workspace, ['senderEmail' => 'ar@example.org', 'provided' => ['sender_email']]);

    $this->getJson(route('payment-requests.status', $request->id))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson(['status' => 'processing', 'revision' => 1, 'stages' => ['extract' => ['state' => 'skipped'], 'domain' => ['state' => 'pending']], 'risk' => null]);
});

it('edits fields in review, bumps the revision and re-assesses', function (): void {
    [$user, $workspace] = $this->signInMember(WorkspaceRole::Approver);
    $request = spManual($user, $workspace);

    $this->patch(route('payment-requests.update', $request->id), [
        'amount' => '2500.00',
        'currency' => 'USD',
        'due_date' => '2026-12-01',
    ])->assertRedirect()->assertSessionHas('success');

    $request->refresh();

    expect($request->revision)->toBe(2)
        ->and($request->amount_minor)->toBe(250000)
        ->and($request->due_date?->toDateString())->toBe('2026-12-01')
        ->and($request->pipeline['manual_fields'])->toContain('amount', 'due_date')
        ->and(EvidenceEntry::query()->where('event', 'request.fields_updated')->sole()->payload)->toEqual(['fields' => ['amount', 'due_date']])
        ->and(DB::table('risk_assessments')->where('payment_request_id', $request->id)->where('trigger', 'manual_edit')->exists())->toBeTrue()
        ->and(DB::table('bank_comparisons')->where('payment_request_id', $request->id)->where('revision', 2)->exists())->toBeTrue();
});

it('switches to the sender pipeline when a sender e-mail is added in review', function (): void {
    [$user, $workspace] = $this->signInMember();
    $request = spManual($user, $workspace);

    $this->patch(route('payment-requests.update', $request->id), ['sender_email' => 'billing@vendor.example'])->assertRedirect();

    $request->refresh();

    expect($request->status)->toBe(PaymentRequestStatus::Processing)
        ->and($request->revision)->toBe(2)
        ->and(OutboxMessage::query()->where('topic', 'intake.received')->sole()->payload['skip_extract'])->toBeTrue();
});

it('refuses edits outside review with a localized message', function (): void {
    [$user, $workspace] = $this->signInMember();
    $request = spManual($user, $workspace);
    $request->forceFill(['status' => PaymentRequestStatus::AwaitingApproval])->save();

    $this->patch(route('payment-requests.update', $request->id), ['title' => 'Changed title'])
        ->assertRedirect()
        ->assertSessionHas('error', __('domain.flash.invalid_transition'));

    expect($request->refresh()->title)->toBe('Invoice INV-42');
});

it('forbids reviewing for members', function (): void {
    [$owner, $workspace] = $this->member();
    [$member] = $this->member(WorkspaceRole::Member, $workspace);
    $request = spManual($member, $workspace);
    $this->actingAs($member);

    $this->patch(route('payment-requests.update', $request->id), ['title' => 'Mine changed'])->assertForbidden();
    $this->put(route('payment-requests.vendor', $request->id), ['vendor_id' => null])->assertForbidden();
});

it('assigns a vendor manually and re-assesses', function (): void {
    [$user, $workspace] = $this->signInMember();
    $vendor = VendorFactory::new()->create(['workspace_id' => $workspace->id, 'created_by' => $user->id]);
    $foreignVendor = VendorFactory::new()->create(['workspace_id' => Workspace::factory()->create()->id, 'created_by' => $user->id]);
    $request = spManual($user, $workspace);

    $this->put(route('payment-requests.vendor', $request->id), ['vendor_id' => $foreignVendor->id])->assertSessionHasErrors('vendor_id');
    $this->put(route('payment-requests.vendor', $request->id), ['vendor_id' => $vendor->id])->assertSessionHas('success');

    $request->refresh();

    expect($request->vendor_id)->toBe($vendor->id)
        ->and($request->vendor_match_method?->value)->toBe('manual')
        ->and($request->vendor_match_confidence)->toBe(100)
        ->and(DB::table('bank_comparisons')->where('payment_request_id', $request->id)->latest('revision')->value('outcome'))->toBe('first_use');
});

it('reassesses and re-extracts on request', function (): void {
    [$user, $workspace] = $this->signInMember();
    $request = app(IntakeService::class)->createFromUpload($workspace, $user, [['bytes' => "%PDF-1.4\n%%EOF", 'name' => 'a.pdf', 'mime' => 'application/pdf']]);
    $request->forceFill(['status' => PaymentRequestStatus::Review])->save();

    $this->post(route('payment-requests.reassess', $request->id))->assertSessionHas('success');
    expect(DB::table('risk_assessments')->where('payment_request_id', $request->id)->count())->toBe(1);

    $this->post(route('payment-requests.reassess', $request->id), ['reextract' => true])->assertSessionHas('success');

    $request->refresh();

    expect($request->status)->toBe(PaymentRequestStatus::Processing)
        ->and($request->revision)->toBe(2)
        ->and(OutboxMessage::query()->where('topic', 'intake.received')->count())->toBe(2);
});

it('serves original files only through signed, audited downloads', function (): void {
    [$user, $workspace] = $this->signInMember();
    $request = app(IntakeService::class)->createFromUpload($workspace, $user, [['bytes' => "%PDF-1.4\nsecret IBAN\n%%EOF", 'name' => 'a.pdf', 'mime' => 'application/pdf']]);
    $file = PaymentRequestFile::query()->sole();

    $this->get(route('payment-requests.files.download', ['paymentRequest' => $request->id, 'file' => $file->id]))->assertForbidden();

    $url = URL::temporarySignedRoute('payment-requests.files.download', now()->addMinutes(5), ['paymentRequest' => $request->id, 'file' => $file->id]);
    $response = $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->getContent())->toBe("%PDF-1.4\nsecret IBAN\n%%EOF")
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')
        ->and(DB::table('audit_logs')->where('action', 'payment_request.file_downloaded')->exists())->toBeTrue();

    $this->signInMember();
    $this->get($url)->assertNotFound();
});
