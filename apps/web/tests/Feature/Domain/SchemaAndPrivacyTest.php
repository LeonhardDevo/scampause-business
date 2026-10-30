<?php

declare(strict_types=1);

use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Intake\IntakeService;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Policy\Models\WorkspacePaymentSettings;
use App\Domain\Privacy\WorkspacePurger;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Providers\DomainServiceProvider;
use App\Workspaces\AccountDeletion;
use App\Workspaces\WorkspaceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['scampause.fingerprint_key' => base64_encode('test-fingerprint-key')]);
});

it('creates every domain table of the contract', function (): void {
    foreach ([...WorkspacePurger::TABLES, 'domain_intel'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue($table);
    }
});

it('creates payment settings for new workspaces with a market default', function (): void {
    $en = Workspace::factory()->create(['locale' => 'en']);
    $ru = Workspace::factory()->create(['locale' => 'ru', 'currency' => 'RUB']);

    $settings = WorkspacePaymentSettings::query()->findOrFail($en->id);

    expect($en->refresh()->country)->toBe('US')
        ->and($settings->business_days)->toBe([1, 2, 3, 4, 5])
        ->and($settings->reminder_offsets_hours)->toBe([4, 24])
        ->and($settings->default_expiry_hours)->toBe(48)
        ->and($settings->verification_amount_threshold_minor)->toBe(100000)
        ->and($ru->refresh()->country)->toBe('RU');
});

it('enforces enum and integrity check constraints', function (string $sql): void {
    [$user, $workspace] = $this->member();
    $vendor = DB::table('vendors')->insertGetId(['workspace_id' => $workspace->id, 'name' => 'Constraint vendor', 'created_by' => $user->id]);
    $sql = str_replace(['{ws}', '{user}', '{vendor}'], [(string) $workspace->id, (string) $user->id, (string) $vendor], $sql);

    expect(fn () => DB::statement($sql))->toThrow(QueryException::class, 'violates check constraint');
})->with([
    'unknown status' => ["INSERT INTO payment_requests (workspace_id, reference, source, status, title, base_currency, requested_by) VALUES ({ws}, 'SP-1', 'manual', 'teleported', 't', 'USD', {user})"],
    'score above 100' => ["INSERT INTO payment_requests (workspace_id, reference, source, title, base_currency, requested_by, risk_score) VALUES ({ws}, 'SP-2', 'manual', 't', 'USD', {user}, 101)"],
    'callback from invoice email' => ["INSERT INTO vendor_contacts (workspace_id, vendor_id, name, is_callback, source, created_by, phone_hash, source_ref) VALUES ({ws}, {vendor}, 'C', true, 'invoice_email', {user}, repeat('a',64), 'x')"],
    'callback without source ref' => ["INSERT INTO vendor_contacts (workspace_id, vendor_id, name, is_callback, source, created_by, phone_hash) VALUES ({ws}, {vendor}, 'C', true, 'contract', {user}, repeat('a',64))"],
    'self-verified contact' => ["INSERT INTO vendor_contacts (workspace_id, vendor_id, name, source, created_by, verified_by) VALUES ({ws}, {vendor}, 'C', 'contract', {user}, {user})"],
    'verified account without evidence' => ["INSERT INTO vendor_bank_accounts (workspace_id, vendor_id, scheme, account_number, fingerprint, last4, status, source, created_by) VALUES ({ws}, {vendor}, 'iban', 'x', repeat('b',64), '1234', 'verified', 'manual', {user})"],
    'expiry out of range' => ['UPDATE workspace_payment_settings SET default_expiry_hours = 2 WHERE workspace_id = {ws}'],
    'non-positive rate' => ["INSERT INTO exchange_rates (workspace_id, quote_currency, base_currency, rate, effective_date) VALUES ({ws}, 'EUR', 'USD', 0, '2026-10-01')"],
]);

it('keeps evidence entries and verifications immutable without the purge setting', function (): void {
    [$user, $workspace] = $this->member();
    $request = app(IntakeService::class)->createFromUpload($workspace, $user, [['bytes' => "%PDF-1.4\n%%EOF", 'name' => 'a.pdf', 'mime' => 'application/pdf']]);
    $verification = DB::table('verifications')->insertGetId([
        'workspace_id' => $workspace->id, 'payment_request_id' => $request->id, 'method' => 'in_person', 'outcome' => 'confirmed',
        'checklist' => '{}', 'occurred_at' => now(), 'performed_by' => $user->id, 'created_at' => now(), 'spoken_to_name' => 'x',
    ]);

    expect(fn () => DB::transaction(fn () => DB::table('evidence_entries')->update(['event' => 'tampered'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('evidence_entries')->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('verifications')->where('id', $verification)->update(['outcome' => 'denied'])))->toThrow(QueryException::class);

    $entry = (int) DB::table('evidence_entries')->value('id');
    DB::table('verifications')->where('id', $verification)->update(['evidence_entry_id' => $entry]);

    expect(fn () => DB::transaction(fn () => DB::table('verifications')->where('id', $verification)->update(['evidence_entry_id' => null])))->toThrow(QueryException::class);

    DB::transaction(function () use ($verification): void {
        DB::statement("SET LOCAL scampause.allow_pii_redaction = 'on'");
        DB::table('verifications')->where('id', $verification)->update(['spoken_to_name' => null]);
    });

    expect(DB::table('verifications')->where('id', $verification)->value('spoken_to_name'))->toBeNull()
        ->and(app(EvidenceLedger::class)->verify($workspace->id)['ok'])->toBeTrue();
});

it('detects a broken evidence chain', function (): void {
    [$user, $workspace] = $this->member();
    $ledger = app(EvidenceLedger::class);
    $ledger->append($workspace->id, 'request.created', 'payment_request', 1, ['files' => 0]);
    $ledger->append($workspace->id, 'request.assessed', 'payment_request', 1, ['score' => 10]);

    DB::transaction(function (): void {
        DB::statement("SET LOCAL scampause.allow_evidence_purge = 'on'");
        DB::table('evidence_entries')->where('seq', 2)->update(['payload' => json_encode(['score' => 0])]);
    });

    expect($ledger->verify($workspace->id))->toMatchArray(['ok' => false, 'first_broken_seq' => 2, 'checked' => 1]);
});

it('purges every domain row and file when a workspace is deleted', function (): void {
    [$user, $workspace] = $this->member();
    app(IntakeService::class)->createFromUpload($workspace, $user, [['bytes' => "%PDF-1.4\n%%EOF", 'name' => 'a.pdf', 'mime' => 'application/pdf']]);
    $request = PaymentRequest::query()->sole();
    DB::table('verifications')->insert([
        'workspace_id' => $workspace->id, 'payment_request_id' => $request->id, 'method' => 'in_person', 'outcome' => 'confirmed',
        'checklist' => '{}', 'occurred_at' => now(), 'performed_by' => $user->id, 'created_at' => now(),
    ]);

    expect(Storage::disk('local')->allFiles('scampause/'.$workspace->id))->not->toBeEmpty();

    app(WorkspaceService::class)->delete($workspace, $user);

    foreach (WorkspacePurger::TABLES as $table) {
        expect(DB::table($table)->where('workspace_id', $workspace->id)->count())->toBe(0, $table);
    }

    expect(Storage::disk('local')->allFiles('scampause/'.$workspace->id))->toBe([]);
});

it('pseudonymizes users referenced by evidence instead of deleting them', function (): void {
    [$owner, $workspace] = $this->member();
    [$approver] = $this->member(WorkspaceRole::Approver, $workspace);
    app(IntakeService::class)->createFromUpload($workspace, $approver, [['bytes' => "%PDF-1.4\n%%EOF", 'name' => 'a.pdf', 'mime' => 'application/pdf']]);

    app(AccountDeletion::class)->delete($approver);

    $fresh = User::query()->findOrFail($approver->id);

    expect($fresh->email)->toBe('deleted+'.$approver->id.'@invalid')
        ->and($fresh->name)->toBe('Former member #'.$approver->id)
        ->and($fresh->getAttribute('deleted_at'))->not->toBeNull()
        ->and(DB::table('workspace_user')->where('user_id', $approver->id)->exists())->toBeFalse()
        ->and(app(EvidenceLedger::class)->verify($workspace->id)['ok'])->toBeTrue()
        ->and(__('domain.former_member', ['id' => 5], 'ru'))->toBe('Бывший участник #5');

    $this->actingAs($owner);
    $loner = User::factory()->create();
    app(AccountDeletion::class)->delete($loner);

    expect(User::query()->find($loner->id))->toBeNull();
});

it('refuses to boot production with the fake domain intel driver or missing keys', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config(['scampause.domain_intel_driver' => 'fake', 'scampause.fingerprint_key' => null, 'scampause.file_key' => null]);

    try {
        expect(fn () => (new DomainServiceProvider(app()))->guardProduction())
            ->toThrow(RuntimeException::class, 'DOMAIN_INTEL_DRIVER must be live in production');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});
