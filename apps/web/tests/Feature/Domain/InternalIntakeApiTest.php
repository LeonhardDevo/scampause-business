<?php

declare(strict_types=1);

use App\Domain\Evidence\Models\EvidenceEntry;
use App\Domain\Intake\Enums\FileRole;
use App\Domain\Intake\Enums\PaymentRequestSource;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Enums\VendorMatchMethod;
use App\Domain\Intake\Files\FileVault;
use App\Domain\Intake\IntakeService;
use App\Domain\Intake\Models\EmailMessage;
use App\Domain\Intake\Models\Extraction;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\Models\PaymentRequestFile;
use App\Domain\Vendors\Models\VendorDomain;
use App\Enums\WorkspaceRole;
use App\Internal\InternalKeyring;
use App\Internal\InternalSignature;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Factories\Domain\VendorFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['scampause.fingerprint_key' => base64_encode('test-fingerprint-key'), 'scampause.domain_intel_driver' => 'fake']);
});

/**
 * @return array{0: User, 1: Workspace, 2: PaymentRequest}
 */
function spUploaded(object $test, string $content = "From: AR <ar@blueridge-packaging.com>\r\nSubject: Invoice BRP-0418\r\n\r\nBody", string $name = 'mail.eml', string $mime = 'message/rfc822'): array
{
    [$user, $workspace] = $test->member(WorkspaceRole::Owner);
    $request = app(IntakeService::class)->createFromUpload($workspace, $user, [['bytes' => $content, 'name' => $name, 'mime' => $mime]]);

    return [$user, $workspace, $request->refresh()];
}

/**
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function spExtraction(PaymentRequest $request, array $fields = [], string $status = 'succeeded', array $warnings = []): array
{
    return [
        'workspace_id' => $request->workspace_id,
        'payment_request_id' => $request->id,
        'revision' => $request->revision,
        'status' => $status,
        'extractor_version' => 'worker-extract@1.0.0',
        'ocr_used' => false,
        'pages' => 1,
        'text' => "Invoice BRP-0418\nPlease note our new bank details.\nCall us on +1 828 555 0199",
        'fields' => array_merge([
            'vendor_name' => ['value' => 'Blue Ridge Packaging', 'confidence' => 85, 'source' => 'text'],
            'invoice_number' => ['value' => 'BRP-0418', 'confidence' => 90, 'source' => 'text'],
            'invoice_date' => ['value' => '2026-10-12', 'confidence' => 90, 'source' => 'text'],
            'due_date' => ['value' => '2026-11-12', 'confidence' => 90, 'source' => 'text'],
            'amount_minor' => ['value' => 1840000, 'confidence' => 90, 'source' => 'text'],
            'currency' => ['value' => 'USD', 'confidence' => 90, 'source' => 'text'],
            'payee' => [
                'scheme' => ['value' => 'iban', 'confidence' => 90, 'source' => 'text'],
                'account_number' => ['value' => 'DE89370400440532013000', 'confidence' => 90, 'source' => 'text'],
                'account_holder' => ['value' => 'Blue Ridge Packaging LLC', 'confidence' => 80, 'source' => 'text'],
                'valid_checksum' => true,
            ],
            'sender' => [
                'from_name' => 'Blue Ridge AR', 'from_email' => 'ar@blueridge-packaging.com', 'reply_to' => 'pay@blueridge-packaging-billing.com',
                'return_path' => 'bounce@blueridge-packaging.com', 'to' => ['ap@northwind.test'], 'subject' => 'Invoice BRP-0418 - new details',
                'sent_at' => '2026-10-23T10:00:00Z', 'message_id' => '<m1@blueridge>',
                'auth' => ['spf' => 'pass', 'dkim' => 'pass', 'dmarc' => 'pass'], 'received_hops' => 2,
            ],
            'emails' => ['pay@blueridge-packaging-billing.com'],
            'domains' => [],
            'urls' => ['https://blueridge-packaging-billing.com/pay'],
            'phones_e164' => ['+18285550199'],
            'qr' => [],
            'kind_suggestion' => 'bank_details_change',
        ], $fields),
        'warnings' => $warnings,
        'error_code' => null,
        'duration_ms' => 850,
        'llm_assisted' => false,
        'files' => [],
    ];
}

it('rejects unsigned calls and other callers', function (): void {
    [, $workspace, $request] = spUploaded($this);

    $this->getJson("/internal/v1/scampause/payment-requests/{$request->id}/intake?workspace_id={$workspace->id}")->assertUnauthorized();
    $this->internal('GET', "/internal/v1/scampause/payment-requests/{$request->id}/intake?workspace_id={$workspace->id}", service: 'ai')
        ->assertForbidden()
        ->assertJson(['error' => 'caller_not_allowed']);
});

it('lists intake files and streams decrypted originals', function (): void {
    [, $workspace, $request] = spUploaded($this);
    $file = PaymentRequestFile::query()->sole();

    $this->internal('GET', "/internal/v1/scampause/payment-requests/{$request->id}/intake?workspace_id={$workspace->id}")
        ->assertOk()
        ->assertJson([
            'payment_request_id' => $request->id,
            'revision' => 1,
            'locale' => 'en',
            'country' => 'US',
            'files' => [['id' => $file->id, 'role' => 'original', 'mime' => 'message/rfc822', 'original_name' => 'mail.eml', 'parent_file_id' => null]],
        ]);

    $response = $this->internal('GET', "/internal/v1/scampause/files/{$file->id}?workspace_id={$workspace->id}")->assertOk();

    expect($response->headers->get('X-Sha256'))->toBe($file->sha256)
        ->and($response->getContent())->toContain('Subject: Invoice BRP-0418');
});

it('answers 404 for another workspace', function (): void {
    [, , $request] = spUploaded($this);
    $foreign = Workspace::factory()->create();

    $this->internal('GET', "/internal/v1/scampause/payment-requests/{$request->id}/intake?workspace_id={$foreign->id}")
        ->assertNotFound()
        ->assertJson(['error' => 'not_found']);
    $this->internal('POST', "/internal/v1/scampause/payment-requests/{$request->id}/assess", ['workspace_id' => $foreign->id, 'revision' => 1])
        ->assertNotFound();
});

it('runs the full pipeline from extraction to review with risk signals', function (): void {
    [$user, $workspace, $request] = spUploaded($this);
    $vendor = VendorFactory::new()->create(['workspace_id' => $workspace->id, 'name' => 'Blue Ridge Packaging', 'created_by' => $user->id]);
    VendorDomain::query()->create(['workspace_id' => $workspace->id, 'vendor_id' => $vendor->id, 'domain' => 'blueridge-packaging.com', 'source' => 'contract']);
    $base = "/internal/v1/scampause/payment-requests/{$request->id}";

    $this->internal('POST', "{$base}/pipeline", ['workspace_id' => $workspace->id, 'revision' => 1, 'stage' => 'extract', 'state' => 'running'])
        ->assertOk()->assertJson(['ok' => true]);
    expect($request->refresh()->status)->toBe(PaymentRequestStatus::Processing);

    $ingest = $this->internal('POST', "{$base}/extraction", spExtraction($request))->assertOk();

    expect($ingest->json('proceed'))->toBeTrue()
        ->and($ingest->json('domains'))->toContain('blueridge-packaging.com', 'blueridge-packaging-billing.com');

    $request->refresh();

    expect($request->vendor_id)->toBe($vendor->id)
        ->and($request->vendor_match_method)->toBe(VendorMatchMethod::SenderDomain)
        ->and($request->amount_minor)->toBe(1840000)
        ->and($request->currency)->toBe('USD')
        ->and($request->invoice_number_normalized)->toBe('BRP0418')
        ->and($request->kind->value)->toBe('bank_details_change')
        ->and($request->title)->toBe('Invoice BRP-0418 - new details')
        ->and($request->sender_domain)->toBe('blueridge-packaging.com')
        ->and($request->reply_to_domain)->toBe('blueridge-packaging-billing.com')
        ->and($request->payee_last4)->toBe('3000')
        ->and(EmailMessage::query()->sole()->from_domain)->toBe('blueridge-packaging.com')
        ->and(Extraction::query()->sole()->text)->toContain('new bank details');

    $this->internal('POST', "{$base}/extraction", spExtraction($request))->assertOk()->assertJson(['proceed' => true]);
    expect(Extraction::query()->count())->toBe(1);

    $this->internal('GET', "/internal/v1/scampause/domain-intel?workspace_id={$workspace->id}&domains=blueridge-packaging.com,blueridge-packaging-billing.com")
        ->assertOk()
        ->assertJson(['mode' => 'fake', 'facts' => [], 'stale' => ['blueridge-packaging.com', 'blueridge-packaging-billing.com']]);

    $this->internal('POST', '/internal/v1/scampause/domain-intel', ['workspace_id' => $workspace->id, 'facts' => [
        ['domain' => 'blueridge-packaging.com', 'has_mx' => true, 'has_spf' => true, 'dmarc_policy' => 'reject', 'registered_at' => '2014-05-01', 'registrar' => 'X', 'rdap_status' => 'ok', 'source' => 'fake', 'checked_at' => now()->toIso8601ZuluString()],
        ['domain' => 'blueridge-packaging-billing.com', 'dmarc_policy' => 'absent', 'registered_at' => now()->subDays(5)->toDateString(), 'rdap_status' => 'ok'],
    ]])->assertOk()->assertJson(['stored' => 2]);

    $this->internal('GET', "/internal/v1/scampause/domain-intel?workspace_id={$workspace->id}&domains=blueridge-packaging.com")
        ->assertOk()->assertJsonPath('facts.0.domain', 'blueridge-packaging.com')->assertJsonPath('stale', []);

    $this->internal('POST', "{$base}/bank-comparison", ['workspace_id' => $workspace->id, 'revision' => 1])
        ->assertOk()->assertJson(['outcome' => 'first_use']);

    $this->internal('POST', "{$base}/assess", ['workspace_id' => $workspace->id, 'revision' => 1])
        ->assertOk()->assertJson(['status' => 'review']);

    $request->refresh();
    $codes = DB::table('risk_signals')->where('risk_assessment_id', $request->risk_assessment_id)->pluck('code')->all();

    expect($request->status)->toBe(PaymentRequestStatus::Review)
        ->and($codes)->toContain('BANK_ACCOUNT_FIRST_USE', 'REPLY_TO_MISMATCH', 'CHANGE_REQUEST_LANGUAGE', 'PHONE_NOT_IN_MASTER', 'SENDER_DOMAIN_LOOKALIKE')
        ->and($request->verification_required)->toBeTrue()
        ->and($request->pipeline['score']['state'])->toBe('done')
        ->and(EvidenceEntry::query()->pluck('event')->all())->toBe(['request.created', 'request.extracted', 'request.assessed']);

    $this->internal('POST', "{$base}/assess", ['workspace_id' => $workspace->id, 'revision' => 1])->assertOk()->assertJson(['status' => 'review']);
    expect(EvidenceEntry::query()->count())->toBe(3);
});

it('answers 409 for a stale revision', function (): void {
    [, $workspace, $request] = spUploaded($this);

    $this->internal('POST', "/internal/v1/scampause/payment-requests/{$request->id}/pipeline", ['workspace_id' => $workspace->id, 'revision' => 2, 'stage' => 'extract', 'state' => 'running'])
        ->assertStatus(409)->assertJson(['error' => 'stale_revision']);
    $this->internal('POST', "/internal/v1/scampause/payment-requests/{$request->id}/extraction", array_merge(spExtraction($request), ['revision' => 5]))
        ->assertStatus(409)->assertJson(['error' => 'stale_revision']);
});

it('moves a request with a final extraction failure to review', function (): void {
    [, $workspace, $request] = spUploaded($this);

    $this->internal('POST', "/internal/v1/scampause/payment-requests/{$request->id}/pipeline", [
        'workspace_id' => $workspace->id, 'revision' => 1, 'stage' => 'extract', 'state' => 'failed', 'error' => 'parse_timeout',
    ])->assertOk();

    $request->refresh();

    expect($request->status)->toBe(PaymentRequestStatus::Review)
        ->and($request->extraction_failed)->toBeTrue()
        ->and($request->risk_assessment_id)->not->toBeNull()
        ->and($request->pipeline['extract'])->toMatchArray(['state' => 'failed', 'error' => 'parse_timeout']);
});

it('forces verification when a later stage fails finally', function (): void {
    [, $workspace, $request] = spUploaded($this);
    $base = "/internal/v1/scampause/payment-requests/{$request->id}";
    $this->internal('POST', "{$base}/extraction", spExtraction($request))->assertOk();

    $this->internal('POST', "{$base}/pipeline", ['workspace_id' => $workspace->id, 'revision' => 1, 'stage' => 'domain-check', 'state' => 'failed'])->assertOk();

    $request->refresh();
    $codes = DB::table('risk_signals')->where('risk_assessment_id', $request->risk_assessment_id)->pluck('code')->all();

    expect($request->status)->toBe(PaymentRequestStatus::Review)
        ->and($codes)->toContain('PIPELINE_INCOMPLETE')
        ->and($request->verification_required)->toBeTrue()
        ->and($request->verification_reasons)->toContain('PIPELINE_INCOMPLETE');
});

it('stores eml attachments once and previews per page', function (): void {
    [, $workspace, $request] = spUploaded($this);
    $parent = PaymentRequestFile::query()->sole();
    $pdf = "%PDF-1.4\n% attachment\n%%EOF\n";
    $body = ['workspace_id' => $workspace->id, 'revision' => 1, 'role' => 'attachment', 'parent_file_id' => $parent->id];

    $body = [...$body, 'name' => 'invoice.pdf', 'content_base64' => base64_encode($pdf)];
    $first = $this->internal('POST', "/internal/v1/scampause/payment-requests/{$request->id}/files", $body)->assertCreated();
    $second = $this->internal('POST', "/internal/v1/scampause/payment-requests/{$request->id}/files", $body)->assertOk();

    expect($second->json('file_id'))->toBe($first->json('file_id'));

    $attachment = PaymentRequestFile::query()->find($first->json('file_id'));

    expect($attachment?->role)->toBe(FileRole::Attachment)
        ->and($attachment?->parent_file_id)->toBe($parent->id)
        ->and(app(FileVault::class)->read($attachment))->toBe($pdf);

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $preview = ['workspace_id' => $workspace->id, 'revision' => 1, 'file_id' => $attachment?->id, 'page' => 1];
    $this->internal('POST', "/internal/v1/scampause/payment-requests/{$request->id}/previews", [...$preview, 'content_base64' => base64_encode((string) $png)])->assertCreated();
    $this->internal('POST', "/internal/v1/scampause/payment-requests/{$request->id}/previews", [...$preview, 'page' => 2, 'content_base64' => base64_encode('not a png')])->assertStatus(422);

    expect(PaymentRequestFile::query()->where('role', 'preview')->count())->toBe(1);
});

it('sweeps stuck pipelines by republishing, then completing', function (): void {
    [, , $request] = spUploaded($this);
    DB::table('payment_requests')->where('id', $request->id)->update(['updated_at' => now()->subMinutes(15)]);

    $this->internal('POST', '/internal/v1/scampause/pipeline/sweep', [])->assertOk()->assertJson(['republished' => 1, 'completed' => 0]);
    expect(OutboxMessage::query()->where('topic', 'intake.received')->count())->toBe(2);

    DB::table('payment_requests')->where('id', $request->id)->update(['updated_at' => now()->subMinutes(15)]);
    $this->internal('POST', '/internal/v1/scampause/pipeline/sweep', [])->assertOk()->assertJson(['republished' => 1]);
    DB::table('payment_requests')->where('id', $request->id)->update(['updated_at' => now()->subMinutes(15)]);
    $this->internal('POST', '/internal/v1/scampause/pipeline/sweep', [])->assertOk()->assertJson(['completed' => 1]);

    $request->refresh();

    expect($request->status)->toBe(PaymentRequestStatus::Review)
        ->and($request->pipeline['incomplete'])->toBeTrue();
});

it('keeps cache-only plans from storing domain facts', function (): void {
    config(['scampause.domain_intel_driver' => 'live']);
    [, $workspace] = $this->member();

    $this->internal('GET', "/internal/v1/scampause/domain-intel?workspace_id={$workspace->id}&domains=example.com")
        ->assertOk()->assertJson(['mode' => 'cache_only', 'facts' => [], 'stale' => []]);
    $this->internal('POST', '/internal/v1/scampause/domain-intel', ['workspace_id' => $workspace->id, 'facts' => [['domain' => 'example.com', 'rdap_status' => 'ok']]])
        ->assertOk()->assertJson(['stored' => 0]);
});

it('sets eml source from the original upload kind', function (): void {
    [, , $request] = spUploaded($this);

    expect($request->source)->toBe(PaymentRequestSource::Eml)
        ->and(CarbonImmutable::instance($request->created_at)->isToday())->toBeTrue();
});

it('accepts multipart attachments signed like the worker client', function (): void {
    [, $workspace, $request] = spUploaded($this);
    $parent = PaymentRequestFile::query()->sole();
    $pdf = "%PDF-1.4\n% multipart attachment\n%%EOF\n";
    $uri = "/internal/v1/scampause/payment-requests/{$request->id}/files";
    $send = function (string $sha) use ($uri, $workspace, $parent, $pdf) {
        $keyring = app(InternalKeyring::class);
        $signature = (new InternalSignature($keyring->secretFor('worker') ?? $keyring->fallbackSecret()))->sign('POST', $uri, '');
        $server = $this->transformHeadersToServerVars([
            'X-Service' => 'worker', 'X-Signature' => $signature, 'X-Content-Sha256' => $sha, 'Accept' => 'application/json',
        ]);

        return $this->call('POST', $uri, ['workspace_id' => $workspace->id, 'revision' => 1, 'role' => 'attachment', 'parent_file_id' => $parent->id], [], [
            'file' => UploadedFile::fake()->createWithContent('scan.pdf', $pdf),
        ], $server);
    };

    $send(hash('sha256', 'tampered'))->assertStatus(422)->assertJson(['error' => 'invalid_file']);
    $send(hash('sha256', $pdf))->assertCreated();

    expect(PaymentRequestFile::query()->where('role', 'attachment')->sole()->original_name)->toBe('scan.pdf');
});
