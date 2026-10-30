<?php

declare(strict_types=1);

use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Evidence\Models\EvidenceEntry;
use App\Domain\Intake\Enums\FileRole;
use App\Domain\Intake\Enums\PaymentRequestSource;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\Models\PaymentRequestFile;
use App\Domain\Risk\Models\RiskAssessment;
use App\Domain\Support\Fingerprinter;
use App\Domain\Vendors\Enums\BankAccountSource;
use App\Domain\Vendors\Enums\BankAccountStatus;
use App\Domain\Vendors\Enums\BankScheme;
use App\Domain\Vendors\Models\VendorBankAccount;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\OutboxMessage;
use Database\Factories\Domain\VendorFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['scampause.fingerprint_key' => base64_encode('test-fingerprint-key')]);
});

function spPdf(string $body = 'Invoice INV-42 IBAN DE89370400440532013000 Total 1,250.00 USD'): string
{
    return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n% ".$body."\ntrailer << >>\n%%EOF\n";
}

function spEml(): string
{
    return "From: Blue Ridge AR <ar@blueridge-packaging.com>\r\nTo: ap@northwind.test\r\nSubject: Invoice BRP-2026-0418\r\nDate: Fri, 23 Oct 2026 10:00:00 +0000\r\nMessage-ID: <x@y>\r\n\r\nPlease find the invoice attached.\r\n";
}

it('creates a payment request from an uploaded PDF and hands it to the worker', function (): void {
    [$user, $workspace] = $this->signInMember(WorkspaceRole::Member);

    $response = $this->post(route('payment-requests.store'), [
        'files' => [UploadedFile::fake()->createWithContent('invoice.pdf', spPdf())],
        'description' => 'Got this from Alex',
    ]);

    $request = PaymentRequest::query()->sole();
    $response->assertRedirect(route('payment-requests.show', $request->id));

    expect($request->reference)->toBe('SP-00001')
        ->and($request->status)->toBe(PaymentRequestStatus::Received)
        ->and($request->source)->toBe(PaymentRequestSource::Upload)
        ->and($request->title)->toBe('invoice.pdf')
        ->and($request->description)->toBe('Got this from Alex')
        ->and($request->requested_by)->toBe($user->id)
        ->and($request->base_currency)->toBe('USD');

    $file = PaymentRequestFile::query()->sole();
    $raw = Storage::disk('local')->get($file->path);

    expect($file->role)->toBe(FileRole::Original)
        ->and($file->mime)->toBe('application/pdf')
        ->and($file->sha256)->toBe(hash('sha256', spPdf()))
        ->and($file->path)->toStartWith('scampause/'.$workspace->id.'/'.$request->id.'/')
        ->and($raw)->not->toContain('DE89370400440532013000')
        ->and($raw)->not->toContain('%PDF');

    $outbox = OutboxMessage::query()->where('topic', 'intake.received')->sole();

    expect($outbox->payload)->toEqual([
        'payment_request_id' => $request->id,
        'workspace_id' => $workspace->id,
        'revision' => 1,
        'file_ids' => [$file->id],
        'locale' => 'en',
        'country' => 'US',
        'skip_extract' => false,
    ])->and($outbox->workspace_id)->toBe($workspace->id);

    $entry = EvidenceEntry::query()->sole();

    expect($entry->event)->toBe('request.created')
        ->and($entry->payload)->toEqual(['files' => 1, 'kind' => 'invoice', 'source' => 'upload'])
        ->and(app(EvidenceLedger::class)->verify($workspace->id)['ok'])->toBeTrue()
        ->and(AuditLog::query()->where('action', 'payment_request.created')->exists())->toBeTrue();
});

it('marks e-mail uploads as eml intake', function (): void {
    $this->signInMember();

    $this->post(route('payment-requests.store'), [
        'files' => [UploadedFile::fake()->createWithContent('forwarded.eml', spEml())],
    ])->assertRedirect();

    expect(PaymentRequest::query()->sole()->source)->toBe(PaymentRequestSource::Eml)
        ->and(PaymentRequestFile::query()->sole()->mime)->toBe('message/rfc822');
});

it('rejects files whose content is not an allowed type', function (string $name, string $content): void {
    $this->signInMember();

    $this->from(route('payment-requests.create'))
        ->post(route('payment-requests.store'), ['files' => [UploadedFile::fake()->createWithContent($name, $content)]])
        ->assertSessionHasErrors('files.0');

    expect(PaymentRequest::query()->count())->toBe(0);
})->with([
    'text renamed to pdf' => ['invoice.pdf', 'just text'],
    'executable extension' => ['run.exe', 'MZ'],
    'pdf with javascript' => ['bad.pdf', "%PDF-1.4\n<< /JavaScript (app.alert(1)) >>\n%%EOF"],
    'encrypted pdf' => ['enc.pdf', "%PDF-1.4\ntrailer << /Encrypt 5 0 R >>\n%%EOF"],
]);

it('limits the number of files per request', function (): void {
    $this->signInMember();
    $files = array_map(fn (int $i): UploadedFile => UploadedFile::fake()->createWithContent("f{$i}.pdf", spPdf((string) $i)), range(1, 6));

    $this->post(route('payment-requests.store'), ['files' => $files])->assertSessionHasErrors('files');
});

it('blocks new intake once the monthly plan limit is reached', function (): void {
    config(['billing.plans.free.limits.payment_requests_monthly' => 0]);
    $this->signInMember();

    $this->from(route('payment-requests.create'))
        ->post(route('payment-requests.store'), ['files' => [UploadedFile::fake()->createWithContent('a.pdf', spPdf())]])
        ->assertRedirect(route('payment-requests.create'));

    expect(PaymentRequest::query()->count())->toBe(0);
});

it('forbids intake for auditors', function (): void {
    $this->signInMember(WorkspaceRole::Auditor);

    $this->post(route('payment-requests.store'), ['files' => [UploadedFile::fake()->createWithContent('a.pdf', spPdf())]])
        ->assertForbidden();
});

it('creates and synchronously assesses a manual request without a sender', function (): void {
    [$user, $workspace] = $this->signInMember(WorkspaceRole::Bookkeeper);

    $this->post(route('payment-requests.manual'), [
        'title' => 'Office rent October',
        'kind' => 'invoice',
        'amount' => '1250.00',
        'currency' => 'USD',
        'invoice_number' => 'INV-42',
        'payee' => ['scheme' => 'iban', 'account_number' => 'DE89 3704 0044 0532 0130 00', 'account_holder' => 'Landlord GmbH'],
    ])->assertRedirect();

    $request = PaymentRequest::query()->sole();

    expect($request->status)->toBe(PaymentRequestStatus::Review)
        ->and($request->amount_minor)->toBe(125000)
        ->and($request->amount_base_minor)->toBe(125000)
        ->and($request->invoice_number_normalized)->toBe('INV42')
        ->and($request->payee_account_number)->toBe('DE89370400440532013000')
        ->and($request->payee_last4)->toBe('3000')
        ->and($request->payee_bank_country)->toBe('DE')
        ->and($request->payee_checksum_valid)->toBeTrue()
        ->and($request->payee_fingerprint)->toBe(app(Fingerprinter::class)->bank($workspace->id, BankScheme::Iban, 'DE89370400440532013000'))
        ->and($request->risk_assessment_id)->not->toBeNull()
        ->and($request->risk_level?->value)->toBeString()
        ->and(OutboxMessage::query()->where('topic', 'intake.received')->exists())->toBeFalse()
        ->and(RiskAssessment::query()->sole()->trigger->value)->toBe('pipeline');

    $raw = (string) DB::table('payment_requests')->where('id', $request->id)->value('payee_account_number');

    expect($raw)->not->toContain('DE89370400440532013000');
});

it('runs the sender checks for a manual request with a sender e-mail', function (): void {
    [, $workspace] = $this->signInMember();

    $this->post(route('payment-requests.manual'), [
        'title' => 'Wire request',
        'kind' => 'urgent_wire',
        'amount' => '5000',
        'currency' => 'USD',
        'sender_email' => 'CEO@Gmail.com',
        'payee' => ['scheme' => 'us_ach', 'account_number' => '000123456789', 'routing_code' => '021000021'],
    ])->assertRedirect();

    $request = PaymentRequest::query()->sole();
    $outbox = OutboxMessage::query()->where('topic', 'intake.received')->sole();

    expect($request->status)->toBe(PaymentRequestStatus::Processing)
        ->and($request->sender_email)->toBe('ceo@gmail.com')
        ->and($request->sender_domain)->toBe('gmail.com')
        ->and($request->pipeline['extract']['state'])->toBe('skipped')
        ->and($outbox->payload['skip_extract'])->toBeTrue()
        ->and($outbox->payload['file_ids'])->toBe([])
        ->and($outbox->workspace_id)->toBe($workspace->id);
});

it('validates manual payee details with checksums', function (): void {
    $this->signInMember();

    $this->post(route('payment-requests.manual'), [
        'title' => 'Bad IBAN',
        'kind' => 'invoice',
        'amount' => '10.001',
        'currency' => 'USD',
        'payee' => ['scheme' => 'iban', 'account_number' => 'DE89370400440532013001'],
    ])->assertSessionHasErrors(['amount', 'payee.account_number']);

    $this->post(route('payment-requests.manual'), [
        'title' => 'Bad ABA',
        'kind' => 'invoice',
        'amount' => '10',
        'currency' => 'USD',
        'payee' => ['scheme' => 'us_ach', 'account_number' => '000123456789', 'routing_code' => '021000022'],
    ])->assertSessionHasErrors(['payee.routing_code']);

    $this->post(route('payment-requests.manual'), [
        'title' => 'Change announcement',
        'kind' => 'bank_details_change',
        'payee' => ['scheme' => 'iban', 'account_number' => 'DE89370400440532013000'],
    ])->assertSessionDoesntHaveErrors();
});

it('raises a bank-change signal against a confirmed vendor account', function (): void {
    [$user, $workspace] = $this->signInMember();
    $other = $this->member(WorkspaceRole::Admin, $workspace)[0];
    $vendor = VendorFactory::new()->create(['workspace_id' => $workspace->id, 'name' => 'Blue Ridge Packaging', 'created_by' => $user->id, 'created_at' => now()->subYear()]);
    $fingerprinter = app(Fingerprinter::class);
    $verification = DB::table('verifications')->insertGetId([
        'workspace_id' => $workspace->id, 'vendor_id' => $vendor->id, 'method' => 'in_person', 'outcome' => 'confirmed',
        'checklist' => '{}', 'occurred_at' => now()->subMonths(3), 'performed_by' => $other->id, 'created_at' => now()->subMonths(3),
    ]);
    $account = new VendorBankAccount;
    $account->forceFill([
        'workspace_id' => $workspace->id, 'vendor_id' => $vendor->id, 'scheme' => BankScheme::Iban, 'account_number' => 'LT093250024720018839',
        'fingerprint' => $fingerprinter->bank($workspace->id, BankScheme::Iban, 'LT093250024720018839'), 'last4' => '8839', 'bank_country' => 'LT',
        'status' => BankAccountStatus::Verified, 'source' => BankAccountSource::Manual, 'verification_id' => $verification,
        'verified_at' => now()->subMonths(3), 'verified_by' => $other->id, 'valid_from' => now()->subMonths(3), 'created_by' => $user->id,
    ])->save();

    $this->post(route('payment-requests.manual'), [
        'title' => 'October shipment',
        'kind' => 'invoice',
        'vendor_id' => $vendor->id,
        'amount' => '18400.00',
        'currency' => 'USD',
        'payee' => ['scheme' => 'iban', 'account_number' => 'DE89370400440532013000'],
    ])->assertRedirect();

    $request = PaymentRequest::query()->sole();
    $codes = DB::table('risk_signals')->where('payment_request_id', $request->id)->pluck('code')->all();

    expect($codes)->toContain('BANK_DETAILS_CHANGED', 'BANK_COUNTRY_CHANGED')
        ->and($request->risk_score)->toBeGreaterThanOrEqual(50)
        ->and($request->verification_required)->toBeTrue()
        ->and($request->verification_reasons)->toContain('BANK_DETAILS_CHANGED')
        ->and(DB::table('bank_comparisons')->where('payment_request_id', $request->id)->value('outcome'))->toBe('changed');
});
