<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

use App\Domain\Evidence\EvidenceRetention;
use App\Domain\Intake\Files\FileVault;
use App\Domain\Intake\Models\PaymentRequestFile;
use App\Domain\Reports\Enums\ExportStatus;
use App\Domain\Reports\Models\ReportExport;
use App\Models\Workspace;
use App\Privacy\RetentionContributor;
use App\Privacy\RetentionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Psr\Clock\ClockInterface;

final readonly class RetentionPurger implements RetentionContributor
{
    public const array CATEGORIES = ['scampause_files', 'scampause_evidence', 'scampause_exports', 'scampause_notifications', 'scampause_domain_intel'];

    public function __construct(
        private ClockInterface $clock,
        private RetentionPolicy $policy,
        private EvidenceRetention $evidence,
    ) {}

    /** @return array<string, int> */
    public function purge(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::instance($this->clock->now());
        $counts = array_fill_keys(self::CATEGORIES, 0);

        Workspace::query()->with('subscription')->select(['id', 'retention'])->chunkById(200, function ($workspaces) use ($now, &$counts): void {
            foreach ($workspaces as $workspace) {
                $counts['scampause_files'] += $this->purgeFiles($workspace, $now->subDays($this->policy->days('scampause_files', $workspace)));
                $counts['scampause_evidence'] += array_sum($this->evidence->purge($workspace->id, $now->subDays($this->policy->days('scampause_evidence', $workspace))));
                $counts['scampause_notifications'] += $this->purgeNotifications($workspace->id, $now->subDays($this->policy->days('scampause_notifications', $workspace)));
            }
        });

        $counts['scampause_exports'] = $this->expireExports($now);
        $counts['scampause_domain_intel'] = DB::table('domain_intel')->where('checked_at', '<', $now->subDays($this->policy->default('scampause_domain_intel')))->delete();

        return $counts;
    }

    public static function exportExpiry(Workspace $workspace, CarbonImmutable $from): CarbonImmutable
    {
        return $from->addDays(app(RetentionPolicy::class)->days('scampause_exports', $workspace));
    }

    private function expireExports(CarbonImmutable $now): int
    {
        $expired = 0;

        foreach (ReportExport::query()->withoutGlobalScopes()->where('status', ExportStatus::Ready->value)->where('expires_at', '<', $now)->limit(500)->get() as $export) {
            $path = $export->getAttribute('path');

            if (is_string($path)) {
                Storage::disk(FileVault::disk())->delete($path);
            }

            $export->forceFill(['status' => ExportStatus::Expired, 'path' => null])->save();
            $expired++;
        }

        return $expired;
    }

    private function purgeNotifications(int $workspaceId, CarbonImmutable $cutoff): int
    {
        return DB::table('sp_notifications')->where('workspace_id', $workspaceId)->where('created_at', '<', $cutoff)->delete()
            + DB::table('employee_alerts')->where('workspace_id', $workspaceId)->where('created_at', '<', $cutoff)->delete();
    }

    private function purgeFiles(Workspace $workspace, CarbonImmutable $cutoff): int
    {
        $purged = 0;
        $requestIds = [];
        $now = CarbonImmutable::instance($this->clock->now());

        foreach (PaymentRequestFile::query()->forWorkspace($workspace)->whereNull('purged_at')->where('created_at', '<', $cutoff)->limit(1000)->get() as $file) {
            Storage::disk((string) $file->getAttribute('disk'))->delete((string) $file->getAttribute('path'));
            DB::table('payment_request_files')->where('id', $file->getKey())->update(['purged_at' => $now]);
            $requestIds[] = (int) $file->getAttribute('payment_request_id');
            $purged++;
        }

        $requestIds = array_values(array_unique($requestIds));

        if ($requestIds !== []) {
            DB::table('extractions')->where('workspace_id', $workspace->id)->whereIn('payment_request_id', $requestIds)->update(['text' => null, 'fields' => null, 'updated_at' => $now]);
            DB::table('email_messages')->where('workspace_id', $workspace->id)->whereIn('payment_request_id', $requestIds)->update(['to_emails' => '[]', 'subject' => null, 'updated_at' => $now]);
        }

        return $purged;
    }
}
