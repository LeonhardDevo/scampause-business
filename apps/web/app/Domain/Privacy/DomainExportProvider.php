<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Intake\Files\FileVault;
use App\Domain\Intake\Models\PaymentRequestFile;
use App\Models\User;
use App\Models\Workspace;
use App\Privacy\ExportAttachmentContributor;
use App\Privacy\ExportContributor;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final readonly class DomainExportProvider implements ExportAttachmentContributor, ExportContributor
{
    public const string DIRECTORY = 'scampause';

    public const array MASKED = [
        'vendor_bank_accounts' => ['account_number', 'routing_code', 'correspondent_account'],
        'payment_requests' => ['payee_account_number', 'payee_routing_code', 'payee_correspondent_account'],
    ];

    public const array DECRYPTED = [
        'vendors' => ['notes'],
        'vendor_contacts' => ['phone_e164'],
        'vendor_bank_accounts' => ['account_holder'],
        'payment_requests' => ['description', 'payee_account_holder', 'decision_note'],
        'extractions' => ['text', 'fields'],
        'approvals' => ['comment'],
        'verifications' => ['phone_dialed', 'spoken_to_name', 'notes'],
        'blocked_bank_accounts' => ['note'],
    ];

    private const array OMITTED = ['telegram_nonce_hash', 'nonce_hash', 'token_hash', 'telegram_message'];

    public function __construct(
        private FileVault $vault,
        private EvidenceLedger $ledger,
    ) {}

    /** @return list<string> */
    public static function tables(): array
    {
        return WorkspacePurger::TABLES;
    }

    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        return [
            'scampause_activity' => [
                'payment_requests_created' => DB::table('payment_requests')
                    ->where('requested_by', $user->id)
                    ->orderBy('id')
                    ->get(['workspace_id', 'reference', 'kind', 'status', 'title', 'amount_minor', 'currency', 'payee_last4', 'risk_level', 'created_at', 'decided_at'])
                    ->all(),
                'verifications_performed' => DB::table('verifications')
                    ->where('performed_by', $user->id)
                    ->orderBy('id')
                    ->get(['workspace_id', 'payment_request_id', 'method', 'outcome', 'phone_dialed_last4', 'phone_source', 'occurred_at'])
                    ->all(),
                'approvals_decided' => DB::table('approvals')
                    ->where('approver_id', $user->id)
                    ->whereNotNull('decided_at')
                    ->orderBy('id')
                    ->get(['workspace_id', 'payment_request_id', 'slot', 'status', 'channel', 'reject_reason', 'decided_at'])
                    ->all(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function forWorkspace(Workspace $workspace): array
    {
        $tables = [];

        foreach (self::tables() as $table) {
            $tables[$table] = DB::table($table)->where('workspace_id', $workspace->id)->count();
        }

        return [
            'scampause_manifest' => [
                'format' => 'ndjson',
                'directory' => self::DIRECTORY,
                'tables' => $tables,
                'masked_columns' => self::MASKED,
                'evidence_chain' => $this->ledger->verify($workspace->id),
            ],
        ];
    }

    /** @return iterable<string, string> */
    public function workspaceAttachments(Workspace $workspace): iterable
    {
        foreach (self::tables() as $table) {
            yield self::DIRECTORY.'/'.$table.'.ndjson' => $this->ndjson($table, $workspace->id);
        }

        $files = PaymentRequestFile::query()->forWorkspace($workspace)->whereNull('purged_at')->orderBy('id')->get();

        foreach ($files as $file) {
            $contents = $this->vault->read($file);

            if ($contents !== null) {
                $name = FileVault::sanitizeName((string) $file->getAttribute('original_name'));

                yield sprintf('%s/files/%d-%s', self::DIRECTORY, (int) $file->getKey(), $name) => $contents;
            }
        }
    }

    private function ndjson(string $table, int $workspaceId): string
    {
        $columns = array_values(array_diff(Schema::getColumnListing($table), self::OMITTED));
        $query = DB::table($table)->where('workspace_id', $workspaceId)->select($columns);
        $order = in_array('id', $columns, true) ? 'id' : $columns[0];
        $lines = [];

        foreach ($query->orderBy($order)->cursor() as $row) {
            $lines[] = json_encode($this->row($table, (array) $row), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function row(string $table, array $row): array
    {
        foreach (self::MASKED[$table] ?? [] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = $row[$column] === null ? null : '[masked]';
            }
        }

        foreach (self::DECRYPTED[$table] ?? [] as $column) {
            if (is_string($row[$column] ?? null)) {
                $row[$column] = self::decrypt($row[$column]);
            }
        }

        foreach ($row as $column => $value) {
            if (is_string($value) && in_array($value[0] ?? '', ['{', '['], true)) {
                $decoded = json_decode($value, true);
                $row[$column] = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
            }
        }

        return $row;
    }

    private static function decrypt(string $value): mixed
    {
        try {
            $plain = Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }

        $decoded = json_decode($plain, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : $plain;
    }
}
