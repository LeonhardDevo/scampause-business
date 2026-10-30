<?php

declare(strict_types=1);

namespace App\Http\Controllers\Domain;

use App\Audit\Auditor;
use App\Domain\Billing\Entitlements;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Evidence\Models\EvidenceEntry;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\Presenters\PaymentRequestRows;
use App\Domain\Intake\Presenters\Refs;
use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Enums\ExportKind;
use App\Domain\Reports\Enums\ExportStatus;
use App\Domain\Reports\ExportService;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Reports\ReportsData;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Domain\Concerns\RunsWorkflow;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class ReportController extends Controller
{
    use RunsWorkflow;

    public function index(Request $request, CurrentWorkspace $current, Entitlements $entitlements, ClockInterface $clock): Response
    {
        $this->allow($current, 'reports.view');
        $workspace = $this->workspace($current);
        $user = $this->user($request);
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'preset' => ['nullable', Rule::in(['30d', '90d', 'ytd', '12m', 'custom'])],
        ]);
        $now = CarbonImmutable::instance($clock->now());
        $period = ReportsData::period($workspace, $validated['preset'] ?? null, $validated['from'] ?? null, $validated['to'] ?? null, $now);
        $from = $period['from'];
        $to = $period['to'];
        $timezone = $workspace->timezone ?: 'UTC';
        $base = strtoupper($workspace->currency);
        $kpis = ReportsData::kpis($workspace, $from, $to);
        $prevented = PaymentRequest::query()->forWorkspace($workspace)->where('status', PaymentRequestStatus::Rejected->value)
            ->whereNotNull('prevented_category')->whereBetween('decided_at', [$from, $to])->orderByDesc('decided_at')->limit(50)->get();
        $rows = PaymentRequestRows::many($prevented, $user);
        $allowed = $entitlements->allows($workspace, 'evidence_exports');
        $chain = Cache::get(self::chainKey($workspace));

        return Inertia::render('reports/Index', [
            'period' => ['from' => $from->setTimezone($timezone)->toDateString(), 'to' => $to->setTimezone($timezone)->toDateString(), 'preset' => $period['preset']],
            'kpis' => [
                'checks' => $kpis['checks'],
                'prevented_amount' => Refs::money($kpis['prevented_minor'], $base),
                'prevented_cases' => $kpis['prevented_cases'],
                'duplicates_prevented' => $kpis['duplicates_prevented'],
                'bank_changes_caught' => $kpis['bank_changes_caught'],
                'legit_changes_verified' => $kpis['legit_changes_verified'],
                'median_decision_hours' => $kpis['median_decision_hours'],
                'verification_compliance' => $kpis['verification_compliance'],
                'expired_rate' => $kpis['expired_rate'],
            ],
            'series' => [
                'checks_by_week' => ReportsData::riskByWeek($workspace, $from, $to),
                'prevented_by_month' => array_map(static fn (array $row): array => ['month' => $row['month'], 'fraud_minor' => $row['fraud_minor'], 'duplicate_minor' => $row['duplicate_minor']], ReportsData::byMonth($workspace, $from, $to)),
                'signals' => ReportsData::signals($workspace, $from, $to, 20),
                'decision_time_histogram' => ReportsData::decisionHistogram($workspace, $from, $to),
                'by_kind' => ReportsData::byKind($workspace, $from, $to),
            ],
            'prevented_cases' => array_map(static function (array $row, PaymentRequest $request) use ($base): array {
                return [
                    ...$row,
                    'decided_at' => (string) Refs::iso($request->getAttribute('decided_at')),
                    'decision_reason' => (string) $request->getAttribute('decision_reason'),
                    'prevented_amount' => Refs::money($request->getAttribute('prevented_amount_base_minor'), $base),
                ];
            }, $rows, $prevented->values()->all()),
            'exports' => array_values(ReportExport::query()->forWorkspace($workspace)->where('kind', '<>', ExportKind::EvidencePacket->value)
                ->orderByDesc('created_at')->limit(20)->get()
                ->map(static fn (ReportExport $export): array => self::exportDto($export, $allowed))->all()),
            'chain' => [
                'last_verified_at' => is_array($chain) ? ($chain['verified_at'] ?? null) : null,
                'ok' => is_array($chain) ? (bool) ($chain['ok'] ?? false) : null,
                'entries' => EvidenceEntry::query()->forWorkspace($workspace)->count(),
            ],
            'abilities' => [
                'export' => $current->allows('reports.export'),
                'verify_chain' => $current->allows('evidence.verify'),
                'exports_allowed_by_plan' => $allowed,
            ],
        ]);
    }

    public function storeExport(Request $request, CurrentWorkspace $current, ExportService $exports): RedirectResponse
    {
        $this->allow($current, 'reports.export');
        $validated = $request->validate([
            'kind' => ['required', Rule::in([ExportKind::AuditJournal->value, ExportKind::PreventedRisk->value])],
            'format' => ['required', Rule::enum(ExportFormat::class)],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $workspace = $this->workspace($current);
        $user = $this->user($request);

        return $this->run(fn () => $exports->requestJournal(
            $workspace,
            $user,
            ExportKind::from((string) $validated['kind']),
            ExportFormat::from((string) $validated['format']),
            CarbonImmutable::parse((string) $validated['from']),
            CarbonImmutable::parse((string) $validated['to']),
        ), 'domain.flash.export_requested');
    }

    public function retryExport(Request $request, int $export, CurrentWorkspace $current, ExportService $exports): RedirectResponse
    {
        $this->allow($current, 'reports.export');
        $model = ReportExport::query()->whereKey($export)->first();
        abort_if($model === null, 404);
        $user = $this->user($request);

        return $this->run(fn () => $exports->retry($model, $user), 'domain.flash.export_requested');
    }

    public function download(Request $request, int $export, CurrentWorkspace $current, ExportService $exports, Entitlements $entitlements, Auditor $auditor): HttpResponse
    {
        $this->allow($current, 'reports.export');
        $workspace = $this->workspace($current);
        abort_unless($entitlements->allows($workspace, 'evidence_exports'), 402);
        $model = ReportExport::query()->whereKey($export)->first();
        abort_if($model === null, 404);
        $bytes = $exports->read($model);
        abort_if($bytes === null, 404);
        $auditor->record('report.export_downloaded', $model, metadata: ['kind' => $model->getAttribute('kind')?->value], actor: $this->user($request), workspace: $workspace);

        return response($bytes, 200, [
            'Content-Type' => ExportService::contentType($model),
            'Content-Disposition' => 'attachment; filename="'.ExportService::filename($model).'"',
            'X-Content-Type-Options' => 'nosniff',
            'X-Sha256' => (string) $model->getAttribute('sha256'),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function verifyChain(Request $request, CurrentWorkspace $current, EvidenceLedger $ledger, Auditor $auditor, ClockInterface $clock): JsonResponse
    {
        $this->allow($current, 'evidence.verify');
        $workspace = $this->workspace($current);
        $result = $ledger->verify($workspace->id);
        $verifiedAt = CarbonImmutable::instance($clock->now())->utc()->toIso8601ZuluString();
        Cache::put(self::chainKey($workspace), ['ok' => $result['ok'], 'verified_at' => $verifiedAt], now()->addDays(30));
        $auditor->record('evidence.chain_verified', null, metadata: ['ok' => $result['ok'], 'checked' => $result['checked']], actor: $this->user($request), workspace: $workspace);

        return response()->json([...$result, 'verified_at' => $verifiedAt]);
    }

    public static function chainKey(Workspace $workspace): string
    {
        return 'sp:chain:'.$workspace->id;
    }

    /**
     * @return array<string, mixed>
     */
    public static function exportDto(ReportExport $export, bool $allowed): array
    {
        $ready = $export->getAttribute('status') === ExportStatus::Ready;

        return [
            'id' => (int) $export->getKey(),
            'kind' => (string) Refs::enum($export->getAttribute('kind')),
            'format' => (string) Refs::enum($export->getAttribute('format')),
            'status' => (string) Refs::enum($export->getAttribute('status')),
            'period_from' => Refs::date($export->getAttribute('period_from')),
            'period_to' => Refs::date($export->getAttribute('period_to')),
            'created_at' => (string) Refs::iso($export->getAttribute('created_at')),
            'sha256' => is_string($s = $export->getAttribute('sha256')) ? $s : null,
            'download_url' => $ready && $allowed ? URL::temporarySignedRoute('reports.exports.download', now()->addMinutes(5), ['export' => $export->getKey()]) : null,
        ];
    }
}
