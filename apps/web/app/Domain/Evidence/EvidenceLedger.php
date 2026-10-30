<?php

declare(strict_types=1);

namespace App\Domain\Evidence;

use App\Domain\Evidence\Enums\EvidenceChannel;
use App\Domain\Evidence\Models\EvidenceEntry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;
use stdClass;

final readonly class EvidenceLedger
{
    public const string GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private ClockInterface $clock) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function append(
        int $workspaceId,
        string $event,
        string $subjectType,
        int $subjectId,
        array $payload = [],
        ?int $actorUserId = null,
        EvidenceChannel $channel = EvidenceChannel::Web,
        ?int $paymentRequestId = null,
        ?CarbonInterface $occurredAt = null,
    ): EvidenceEntry {
        $occurred = CarbonImmutable::instance($occurredAt ?? $this->clock->now())->utc();

        return DB::transaction(function () use ($workspaceId, $event, $subjectType, $subjectId, $payload, $actorUserId, $channel, $paymentRequestId, $occurred): EvidenceEntry {
            DB::statement(
                'INSERT INTO evidence_heads (workspace_id, last_seq, last_hash, updated_at) VALUES (?, 0, ?, now()) ON CONFLICT (workspace_id) DO NOTHING',
                [$workspaceId, self::GENESIS],
            );

            $head = DB::table('evidence_heads')->where('workspace_id', $workspaceId)->lockForUpdate()->first();

            if ($head === null) {
                throw new \RuntimeException('Evidence head is missing.');
            }

            $seq = (int) $head->last_seq + 1;
            $prevHash = (string) $head->last_hash;
            $material = self::material($seq, $workspaceId, $paymentRequestId, $subjectType, $subjectId, $event, $actorUserId, $channel->value, $occurred, $payload);
            $hash = self::hash($prevHash, $material);

            $entry = new EvidenceEntry;
            $entry->forceFill([
                'workspace_id' => $workspaceId,
                'seq' => $seq,
                'payment_request_id' => $paymentRequestId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'event' => $event,
                'actor_user_id' => $actorUserId,
                'channel' => $channel,
                'occurred_at' => $occurred->format('Y-m-d H:i:s.uP'),
                'payload' => $payload === [] ? new stdClass : $payload,
                'prev_hash' => $prevHash,
                'hash' => $hash,
            ]);
            $entry->save();

            DB::table('evidence_heads')->where('workspace_id', $workspaceId)->update([
                'last_seq' => $seq,
                'last_hash' => $hash,
                'updated_at' => $occurred->format('Y-m-d H:i:s.uP'),
            ]);

            return $entry;
        });
    }

    /**
     * @return array{ok: bool, checked: int, first_broken_seq: int|null, head_hash: string}
     */
    public function verify(int $workspaceId, ?int $fromSeq = null): array
    {
        $previous = self::GENESIS;
        $checked = 0;
        $expectedSeq = null;
        $query = DB::table('evidence_entries')->where('workspace_id', $workspaceId)->orderBy('seq');

        if ($fromSeq !== null) {
            $query->where('seq', '>=', $fromSeq);
        }

        foreach ($query->cursor() as $row) {
            $seq = (int) $row->seq;

            if ($expectedSeq === null) {
                $previous = match (true) {
                    $fromSeq !== null => (string) $row->prev_hash,
                    $seq === 1 => self::GENESIS,
                    default => self::checkpointAnchor($workspaceId, $seq - 1),
                };
                $expectedSeq = $seq;

                if ($previous === null) {
                    return ['ok' => false, 'checked' => 0, 'first_broken_seq' => $seq, 'head_hash' => self::GENESIS];
                }
            }

            $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
            $material = self::material(
                $seq,
                (int) $row->workspace_id,
                $row->payment_request_id === null ? null : (int) $row->payment_request_id,
                (string) $row->subject_type,
                (int) $row->subject_id,
                (string) $row->event,
                $row->actor_user_id === null ? null : (int) $row->actor_user_id,
                (string) $row->channel,
                CarbonImmutable::parse((string) $row->occurred_at)->utc(),
                is_array($payload) ? $payload : [],
            );

            if ($seq !== $expectedSeq || $row->prev_hash !== $previous || self::hash($previous, $material) !== $row->hash) {
                return ['ok' => false, 'checked' => $checked, 'first_broken_seq' => $seq, 'head_hash' => $previous];
            }

            $previous = (string) $row->hash;
            $expectedSeq++;
            $checked++;
        }

        return ['ok' => true, 'checked' => $checked, 'first_broken_seq' => null, 'head_hash' => $previous];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function material(
        int $seq,
        int $workspaceId,
        ?int $paymentRequestId,
        string $subjectType,
        int $subjectId,
        string $event,
        ?int $actorUserId,
        string $channel,
        CarbonInterface $occurredAt,
        array $payload,
    ): string {
        return CanonicalJson::object([
            'seq' => $seq,
            'workspace_id' => $workspaceId,
            'payment_request_id' => $paymentRequestId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'event' => $event,
            'actor_user_id' => $actorUserId,
            'channel' => $channel,
            'occurred_at' => self::timestamp($occurredAt),
            'payload' => $payload === [] ? new stdClass : $payload,
        ]);
    }

    public static function checkpointAnchor(int $workspaceId, int $purgedToSeq): ?string
    {
        $payload = DB::table('evidence_entries')
            ->where('workspace_id', $workspaceId)
            ->where('event', EvidenceRetention::CHECKPOINT_EVENT)
            ->whereRaw("(payload->>'purged_to_seq')::bigint = ?", [$purgedToSeq])
            ->orderByDesc('seq')
            ->value('payload');

        if (! is_string($payload)) {
            return null;
        }

        $decoded = json_decode($payload, true);
        $hash = is_array($decoded) ? ($decoded['last_purged_hash'] ?? null) : null;

        return is_string($hash) && preg_match('/^[0-9a-f]{64}$/', $hash) === 1 ? $hash : null;
    }

    public static function hash(string $prevHash, string $material): string
    {
        return hash('sha256', $prevHash."\n".$material);
    }

    public static function timestamp(CarbonInterface $at): string
    {
        return CarbonImmutable::instance($at)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
