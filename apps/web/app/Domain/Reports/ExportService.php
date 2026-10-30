<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Audit\Auditor;
use App\Domain\Approvals\ApprovalPublisher;
use App\Domain\Intake\Files\FileCipher;
use App\Domain\Intake\Files\FileVault;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Privacy\RetentionPurger;
use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Enums\ExportKind;
use App\Domain\Reports\Enums\ExportStatus;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Workflow\WorkflowException;
use App\Models\User;
use App\Models\Workspace;
use App\Outbox\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Clock\ClockInterface;

final readonly class ExportService
{
    public function __construct(
        private Outbox $outbox,
        private FileCipher $cipher,
        private Auditor $auditor,
        private ClockInterface $clock,
    ) {}

    public function requestPacket(PaymentRequest $request, ?User $requestedBy): ReportExport
    {
        $workspace = Workspace::query()->findOrFail((int) $request->getAttribute('workspace_id'));
        $pending = ReportExport::query()->forWorkspace($workspace)
            ->where('payment_request_id', $request->getKey())
            ->where('kind', ExportKind::EvidencePacket->value)
            ->where('status', ExportStatus::Queued->value)
            ->orderByDesc('id')
            ->first();

        if ($pending !== null) {
            return $pending;
        }

        $locale = $requestedBy !== null ? ($requestedBy->preferredLocale() === 'ru' ? 'ru' : 'en') : ($workspace->locale === 'ru' ? 'ru' : 'en');
        $export = new ReportExport;
        $export->forceFill([
            'workspace_id' => $workspace->id,
            'kind' => ExportKind::EvidencePacket,
            'format' => ExportFormat::Pdf,
            'locale' => $locale,
            'payment_request_id' => $request->getKey(),
            'status' => ExportStatus::Queued,
            'requested_by' => $requestedBy?->id,
        ])->save();

        $this->outbox->publish(ApprovalPublisher::PACKET, [
            'export_id' => (int) $export->getKey(),
            'payment_request_id' => (int) $request->getKey(),
            'workspace_id' => $workspace->id,
            'locale' => $locale,
        ], $workspace->id);

        return $export;
    }

    public function requestJournal(Workspace $workspace, User $user, ExportKind $kind, ExportFormat $format, CarbonImmutable $from, CarbonImmutable $to): ReportExport
    {
        if ($kind === ExportKind::EvidencePacket) {
            throw new WorkflowException('invalid_export_kind', 'kind');
        }

        $locale = $user->preferredLocale() === 'ru' ? 'ru' : 'en';
        $export = new ReportExport;
        $export->forceFill([
            'workspace_id' => $workspace->id,
            'kind' => $kind,
            'format' => $format,
            'locale' => $locale,
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'status' => ExportStatus::Queued,
            'requested_by' => $user->id,
        ])->save();

        $this->outbox->publish(ApprovalPublisher::JOURNAL, [
            'export_id' => (int) $export->getKey(),
            'workspace_id' => $workspace->id,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'format' => $format->value,
            'kind' => $kind->value,
            'locale' => $locale,
        ], $workspace->id);

        $this->auditor->record('report.export_requested', $export, metadata: ['kind' => $kind->value, 'format' => $format->value], actor: $user, workspace: $workspace, subjectLabel: $kind->value);

        return $export;
    }

    public function retry(ReportExport $export, User $user): ReportExport
    {
        if ($export->getAttribute('status') !== ExportStatus::Failed) {
            throw new WorkflowException('export_not_failed', 'export');
        }

        $export->forceFill(['status' => ExportStatus::Queued, 'error' => null])->save();
        $kind = $export->getAttribute('kind');

        if ($kind === ExportKind::EvidencePacket) {
            $this->outbox->publish(ApprovalPublisher::PACKET, [
                'export_id' => (int) $export->getKey(),
                'payment_request_id' => (int) $export->getAttribute('payment_request_id'),
                'workspace_id' => (int) $export->getAttribute('workspace_id'),
                'locale' => (string) $export->getAttribute('locale'),
            ], (int) $export->getAttribute('workspace_id'));
        } else {
            $this->outbox->publish(ApprovalPublisher::JOURNAL, [
                'export_id' => (int) $export->getKey(),
                'workspace_id' => (int) $export->getAttribute('workspace_id'),
                'from' => $export->getAttribute('period_from')?->toDateString(),
                'to' => $export->getAttribute('period_to')?->toDateString(),
                'format' => $export->getAttribute('format')?->value,
                'kind' => $kind instanceof ExportKind ? $kind->value : null,
                'locale' => (string) $export->getAttribute('locale'),
            ], (int) $export->getAttribute('workspace_id'));
        }

        $this->auditor->record('report.export_retried', $export, actor: $user, workspace: (int) $export->getAttribute('workspace_id'));

        return $export;
    }

    public function storeFile(ReportExport $export, string $bytes, string $sha256, bool $chainVerified): void
    {
        if (! hash_equals(hash('sha256', $bytes), strtolower($sha256))) {
            throw new WorkflowException('sha256_mismatch', 'sha256');
        }

        $format = $export->getAttribute('format');
        $extension = $format instanceof ExportFormat ? ($format === ExportFormat::Json ? 'ndjson' : $format->value) : 'bin';
        $path = sprintf('scampause/%d/exports/%s.%s', (int) $export->getAttribute('workspace_id'), (string) Str::uuid(), $extension);
        Storage::disk(FileVault::disk())->put($path, $this->cipher->encrypt($bytes));
        $previous = $export->getAttribute('path');

        if (is_string($previous) && $previous !== '') {
            Storage::disk(FileVault::disk())->delete($previous);
        }

        $now = CarbonImmutable::instance($this->clock->now());
        $export->forceFill([
            'status' => ExportStatus::Ready,
            'path' => $path,
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'chain_verified' => $chainVerified,
            'error' => null,
            'completed_at' => $now,
            'expires_at' => RetentionPurger::exportExpiry(Workspace::query()->findOrFail((int) $export->getAttribute('workspace_id')), $now),
        ])->save();
    }

    public function fail(ReportExport $export, string $error): void
    {
        $export->forceFill(['status' => ExportStatus::Failed, 'error' => mb_substr($error, 0, 500, 'UTF-8')])->save();
    }

    public function read(ReportExport $export): ?string
    {
        $path = $export->getAttribute('path');

        if ($export->getAttribute('status') !== ExportStatus::Ready || ! is_string($path)) {
            return null;
        }

        $disk = Storage::disk(FileVault::disk());

        return $disk->exists($path) ? $this->cipher->decrypt((string) $disk->get($path)) : null;
    }

    public static function contentType(ReportExport $export): string
    {
        return match ($export->getAttribute('format')) {
            ExportFormat::Pdf => 'application/pdf',
            ExportFormat::Csv => 'text/csv; charset=UTF-8',
            default => 'application/x-ndjson',
        };
    }

    public static function filename(ReportExport $export): string
    {
        $kind = $export->getAttribute('kind');
        $format = $export->getAttribute('format');
        $extension = $format === ExportFormat::Json ? 'ndjson' : ($format instanceof ExportFormat ? $format->value : 'bin');

        return sprintf('scampause-%s-%d.%s', $kind instanceof ExportKind ? str_replace('_', '-', $kind->value) : 'export', (int) $export->getKey(), $extension);
    }
}
