<?php

declare(strict_types=1);

namespace Database\Seeders\Domain;

use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Money\Money;
use App\Domain\Notifications\DomainNotifier;
use App\Domain\Reports\Enums\ExportKind;
use App\Domain\Reports\Enums\ExportStatus;
use App\Domain\Reports\ExportService;
use App\Domain\Reports\Models\ReportExport;
use App\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Database\Seeders\Domain\Support\DemoWorkspace;
use Database\Seeders\Domain\Support\FrozenClock;
use Database\Seeders\Domain\Support\ReferenceAllocator;
use Illuminate\Support\Facades\DB;

final class DemoFinisher
{
    /**
     * @param  list<int>  $packetRefs
     * @param  list<array{0: int, 1: string}>  $unread
     */
    public static function finish(DemoWorkspace $demo, array $packetRefs, array $unread, ?string $ownerKey): void
    {
        $workspaceId = $demo->workspace->id;
        ReferenceAllocator::finish($workspaceId);
        self::deliveries($workspaceId);

        FrozenClock::at($demo->now, function () use ($demo, $packetRefs, $unread, $ownerKey): void {
            app(CurrentWorkspace::class)->runAs($demo->workspace, function () use ($demo, $packetRefs, $unread, $ownerKey): void {
                self::packets($demo, $packetRefs);
                self::notifications($demo, $unread, $ownerKey);
            });
        });
    }

    public static function deliveries(int $workspaceId): void
    {
        $rows = DB::table('sp_notifications')
            ->where('workspace_id', $workspaceId)
            ->whereIn('channel', [DomainNotifier::TELEGRAM, DomainNotifier::EMAIL])
            ->where('status', 'queued')
            ->orderBy('id')
            ->get(['id', 'channel', 'created_at']);
        $deliveries = [];

        foreach ($rows as $row) {
            $sentAt = CarbonImmutable::parse((string) $row->created_at)->addSeconds(4);
            $deliveries[] = [
                'reference_type' => 'sp_notification',
                'reference_id' => (string) $row->id,
                'channel' => (string) $row->channel,
                'status' => 'sent',
                'provider_message_id' => $row->channel === DomainNotifier::TELEGRAM ? (string) (700000 + (int) $row->id) : 'demo-'.$row->id.'@mail.test',
                'created_at' => $sentAt,
            ];
        }

        foreach (array_chunk($deliveries, 500) as $chunk) {
            DB::table('deliveries')->insert($chunk);
        }

        DB::table('sp_notifications')
            ->where('workspace_id', $workspaceId)
            ->whereIn('channel', [DomainNotifier::TELEGRAM, DomainNotifier::EMAIL])
            ->where('status', 'queued')
            ->update(['status' => 'sent']);
    }

    /**
     * @param  list<int>  $packetRefs
     */
    private static function packets(DemoWorkspace $demo, array $packetRefs): void
    {
        $workspaceId = $demo->workspace->id;
        ReportExport::query()->forWorkspace($workspaceId)
            ->where('kind', ExportKind::EvidencePacket->value)
            ->where('status', ExportStatus::Queued->value)
            ->delete();

        foreach ($packetRefs as $ref) {
            app(ExportService::class)->requestPacket($demo->request($ref), null);
        }
    }

    /**
     * @param  list<array{0: int, 1: string}>  $unread
     */
    private static function notifications(DemoWorkspace $demo, array $unread, ?string $ownerKey): void
    {
        $workspaceId = $demo->workspace->id;
        DB::table('notifications')
            ->whereNull('read_at')
            ->whereRaw("(data->>'workspace_id')::bigint = ?", [$workspaceId])
            ->update(['read_at' => DB::raw("created_at + interval '45 minutes'")]);
        DB::table('notifications')
            ->whereRaw("(data->>'workspace_id')::bigint = ?", [$workspaceId])
            ->where('read_at', '>', $demo->now)
            ->update(['read_at' => $demo->now]);

        if ($ownerKey === null) {
            return;
        }

        $owner = $demo->user($ownerKey);

        foreach ($unread as [$ref, $template]) {
            $id = DB::table('notifications')
                ->where('notifiable_id', $owner->id)
                ->whereRaw("(data->>'workspace_id')::bigint = ?", [$workspaceId])
                ->whereRaw("data->>'href' = ?", ['/payment-requests/'.$demo->requests[$ref]])
                ->whereRaw("data->'title'->>'key' = ?", ['domain.notifications.'.$template.'.title'])
                ->orderByDesc('created_at')
                ->value('id');

            if ($id === null) {
                app(DomainNotifier::class)->notify($template, [$owner], $workspaceId, $demo->request($ref), [DomainNotifier::DATABASE], [], 'warning');
            } else {
                DB::table('notifications')->where('id', $id)->update(['read_at' => null]);
            }
        }

        $weekAgo = $demo->now->subDays(7);
        $checked = PaymentRequest::query()->forWorkspace($workspaceId)->where('created_at', '>=', $weekAgo)->count();
        $prevented = (int) PaymentRequest::query()->forWorkspace($workspaceId)->where('decided_at', '>=', $weekAgo)->sum('prevented_amount_base_minor');
        $currency = strtoupper($demo->workspace->currency);
        app(DomainNotifier::class)->notify('weekly_summary', [$owner], $workspaceId, null, [DomainNotifier::DATABASE], [
            'count' => $checked,
            'prevented' => static fn (string $locale): string => Money::of($prevented, $currency)->format($locale),
        ], 'info', '/reports');
    }
}
