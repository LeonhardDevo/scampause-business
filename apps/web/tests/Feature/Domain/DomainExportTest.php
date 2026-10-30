<?php

declare(strict_types=1);

use App\Domain\Intake\Files\FileVault;
use App\Domain\Intake\Models\PaymentRequestFile;
use App\Domain\Privacy\DomainExportProvider;
use App\Models\DataExport;
use App\Privacy\DataExports;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Domain\Scenario;

const FOUNDATION_WORKSPACE_TABLES = [
    'ai_queries', 'audit_logs', 'data_exports', 'documents', 'email_suppressions', 'legal_acceptances', 'outbox_messages',
    'processed_stripe_events', 'subscriptions', 'telegram_accounts', 'telegram_link_tokens', 'workspace_invitations', 'workspace_user',
];

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('documents');
    Storage::fake(FileVault::disk());
    config(['mail.default' => 'outbox']);
});

function domainZipEntries(DataExport $export): array
{
    $file = tempnam(sys_get_temp_dir(), 'domain-zip-');
    file_put_contents($file, Storage::disk((string) $export->disk)->get((string) $export->path));
    $zip = new ZipArchive;
    $zip->open($file);
    $entries = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entries[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }

    $zip->close();
    @unlink($file);

    return $entries;
}

it('exports every workspace-scoped domain table of the schema', function (): void {
    $tables = collect(DB::select("SELECT table_name FROM information_schema.columns WHERE table_schema = current_schema() AND column_name = 'workspace_id'"))
        ->map(fn (object $row): string => (string) $row->table_name)
        ->diff(FOUNDATION_WORKSPACE_TABLES)
        ->sort()
        ->values()
        ->all();

    expect($tables)->toBe(collect(DomainExportProvider::tables())->sort()->values()->all());
});

it('adds masked NDJSON tables, decrypted originals and the chain status to the workspace export', function (): void {
    $s = Scenario::make();
    $vendor = $s->vendor();
    $s->verifiedAccount($vendor);
    $request = $s->request('bookkeeper', ['vendorId' => (int) $vendor->getKey(), 'payeeAccountNumber' => '000483924417', 'payeeRoutingCode' => '021000021']);
    $stored = app(FileVault::class)->store($s->workspace->id, (int) $request->getKey(), 'INVOICE ORIGINAL BYTES', 'pdf');
    $file = PaymentRequestFile::query()->forceCreate([
        'workspace_id' => $s->workspace->id,
        'payment_request_id' => $request->getKey(),
        'role' => 'original',
        'disk' => $stored['disk'],
        'path' => $stored['path'],
        'original_name' => 'invoice 88390.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => 22,
        'sha256' => hash('sha256', 'INVOICE ORIGINAL BYTES'),
        'created_at' => now(),
    ]);

    $export = app(DataExports::class)->request($s->user('owner'), DataExport::SCOPE_WORKSPACE, $s->workspace);
    $this->internal('POST', "/internal/v1/privacy/exports/{$export->id}/build")->assertOk()->assertJsonPath('status', 'ready');
    $entries = domainZipEntries($export->refresh());
    $manifest = json_decode($entries['scampause_manifest.json'], true);
    $requests = array_map(fn (string $line): array => json_decode($line, true), array_filter(explode("\n", $entries['scampause/payment_requests.ndjson'])));
    $tables = implode("\n", array_filter($entries, fn (string $name): bool => str_starts_with($name, 'scampause/') && str_ends_with($name, '.ndjson'), ARRAY_FILTER_USE_KEY));

    foreach (DomainExportProvider::tables() as $table) {
        expect($entries)->toHaveKey("scampause/{$table}.ndjson");
    }

    expect($manifest['evidence_chain']['ok'])->toBeTrue()
        ->and($manifest['tables']['payment_requests'])->toBe(1)
        ->and($requests[0]['payee_account_number'])->toBe('[masked]')
        ->and($requests[0]['payee_last4'])->toBe('4417')
        ->and($tables)->not->toContain('000483924417')
        ->and($tables)->not->toContain('nonce_hash')
        ->and($entries)->toHaveKey(sprintf('scampause/files/%d-%s', $file->getKey(), FileVault::sanitizeName('invoice 88390.pdf')))
        ->and($entries[sprintf('scampause/files/%d-%s', $file->getKey(), FileVault::sanitizeName('invoice 88390.pdf'))])->toBe('INVOICE ORIGINAL BYTES');
});

it('adds the requests, verifications and decisions of a user to the personal export', function (): void {
    $s = Scenario::make();
    $vendor = $s->vendor();
    $s->verifiedAccount($vendor);
    $s->request('bookkeeper', ['vendorId' => (int) $vendor->getKey()]);

    $export = app(DataExports::class)->request($s->user('bookkeeper'), DataExport::SCOPE_USER);
    $this->internal('POST', "/internal/v1/privacy/exports/{$export->id}/build")->assertOk();
    $activity = json_decode(domainZipEntries($export->refresh())['scampause_activity.json'], true);

    expect($activity['payment_requests_created'])->toHaveCount(1)
        ->and($activity['payment_requests_created'][0])->not->toHaveKey('payee_account_number')
        ->and($activity)->toHaveKeys(['verifications_performed', 'approvals_decided']);
});
