<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

use App\Domain\Intake\Files\FileVault;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class WorkspacePurger
{
    public const array TABLES = [
        'risk_signals', 'risk_assessments', 'bank_comparisons', 'email_messages', 'extractions', 'sp_notifications', 'employee_alerts',
        'report_exports', 'approvals', 'approval_routes', 'verifications', 'evidence_entries', 'evidence_heads', 'domain_intel_sightings',
        'payment_request_files', 'payment_requests', 'blocked_bank_accounts', 'vendor_bank_accounts', 'vendor_contacts', 'vendor_domains',
        'vendors', 'approval_rules', 'member_limits', 'exchange_rates', 'workspace_sequences', 'workspace_payment_settings',
    ];

    public function purge(Workspace $workspace): void
    {
        $workspaceId = $workspace->id;
        DB::statement("SET LOCAL scampause.allow_evidence_purge = 'on'");
        DB::table('payment_requests')->where('workspace_id', $workspaceId)->update([
            'risk_assessment_id' => null,
            'current_route_id' => null,
            'satisfying_verification_id' => null,
            'matched_bank_account_id' => null,
        ]);
        DB::table('vendor_bank_accounts')->where('workspace_id', $workspaceId)->update(['verification_id' => null, 'status' => 'pending_verification']);
        DB::table('verifications')->where('workspace_id', $workspaceId)->update(['evidence_entry_id' => null]);

        foreach (self::TABLES as $table) {
            DB::table($table)->where('workspace_id', $workspaceId)->delete();
        }

        DB::afterCommit(static function () use ($workspaceId): void {
            Storage::disk(FileVault::disk())->deleteDirectory('scampause/'.$workspaceId);
        });
    }
}
