<?php

declare(strict_types=1);

namespace App\Domain\Evidence;

use App\Domain\Evidence\Enums\EvidenceChannel;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class EvidenceRetention
{
    public const string CHECKPOINT_EVENT = 'ledger.checkpoint';

    private const int BATCH = 500;

    public function __construct(private EvidenceLedger $ledger) {}

    /** @return array{entries: int, records: int} */
    public function purge(int $workspaceId, CarbonImmutable $cutoff): array
    {
        return DB::transaction(function () use ($workspaceId, $cutoff): array {
            DB::statement("SET LOCAL scampause.allow_evidence_purge = 'on'");
            DB::table('evidence_heads')->where('workspace_id', $workspaceId)->lockForUpdate()->first();

            return [
                'records' => $this->purgeClosedRequests($workspaceId, $cutoff),
                'entries' => $this->purgeLedgerPrefix($workspaceId, $cutoff),
            ];
        });
    }

    private function purgeLedgerPrefix(int $workspaceId, CarbonImmutable $cutoff): int
    {
        $entries = DB::table('evidence_entries')->where('workspace_id', $workspaceId);
        $first = (clone $entries)->min('seq');

        if ($first === null) {
            return 0;
        }

        $boundary = (clone $entries)->where('occurred_at', '>=', $cutoff)->min('seq');
        $last = $boundary === null ? (int) (clone $entries)->max('seq') : (int) $boundary - 1;

        if ($last < (int) $first) {
            return 0;
        }

        $lastHash = (string) (clone $entries)->where('seq', $last)->value('hash');
        $purged = (clone $entries)->where('seq', '<=', $last)->select('id');

        DB::table('verifications')->where('workspace_id', $workspaceId)->whereIn('evidence_entry_id', $purged)->update(['evidence_entry_id' => null]);
        $deleted = (clone $entries)->where('seq', '<=', $last)->delete();

        $this->ledger->append(
            $workspaceId,
            self::CHECKPOINT_EVENT,
            'workspace',
            $workspaceId,
            ['last_purged_hash' => $lastHash, 'purged_to_seq' => $last],
            channel: EvidenceChannel::System,
        );

        return $deleted;
    }

    private function purgeClosedRequests(int $workspaceId, CarbonImmutable $cutoff): int
    {
        $ids = DB::table('payment_requests')
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', array_map(static fn (PaymentRequestStatus $status): string => $status->value, PaymentRequestStatus::decided()))
            ->whereRaw('COALESCE(decided_at, paid_at, updated_at) < ?', [$cutoff])
            ->where(static fn ($query) => $query
                ->whereExists(static fn ($inner) => $inner->selectRaw('1')->from('verifications')->whereColumn('verifications.payment_request_id', 'payment_requests.id'))
                ->orWhereExists(static fn ($inner) => $inner->selectRaw('1')->from('approvals')->whereColumn('approvals.payment_request_id', 'payment_requests.id'))
                ->orWhereExists(static fn ($inner) => $inner->selectRaw('1')->from('risk_assessments')->whereColumn('risk_assessments.payment_request_id', 'payment_requests.id')))
            ->orderBy('id')
            ->limit(self::BATCH)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return 0;
        }

        DB::table('payment_requests')->whereIn('id', $ids)->update([
            'risk_assessment_id' => null,
            'satisfying_verification_id' => null,
            'current_route_id' => null,
        ]);

        $verifications = DB::table('verifications')->where('workspace_id', $workspaceId)->whereIn('payment_request_id', $ids)->select('id');
        DB::table('vendor_bank_accounts')->where('workspace_id', $workspaceId)->whereIn('verification_id', $verifications)->update(['verification_id' => null]);

        DB::table('approvals')->where('workspace_id', $workspaceId)->whereIn('payment_request_id', $ids)->update(['replaced_approval_id' => null]);
        $count = DB::table('approvals')->where('workspace_id', $workspaceId)->whereIn('payment_request_id', $ids)->delete();
        DB::table('approval_routes')->where('workspace_id', $workspaceId)->whereIn('payment_request_id', $ids)->delete();
        $count += DB::table('risk_assessments')->where('workspace_id', $workspaceId)->whereIn('payment_request_id', $ids)->delete();
        $count += DB::table('verifications')->where('workspace_id', $workspaceId)->whereIn('payment_request_id', $ids)->delete();

        return $count;
    }
}
