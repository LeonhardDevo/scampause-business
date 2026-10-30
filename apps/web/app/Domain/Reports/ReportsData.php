<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Intake\Enums\BankComparisonOutcome;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Enums\PreventedCategory;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Risk\Enums\RiskLevel;
use App\Domain\Risk\SignalPresenter;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ReportsData
{
    /**
     * @param  Builder<PaymentRequest>|null  $scope
     * @return Builder<PaymentRequest>
     */
    public static function base(Workspace $workspace, ?Builder $scope = null): Builder
    {
        return $scope !== null ? clone $scope : PaymentRequest::query()->forWorkspace($workspace);
    }

    /**
     * @param  Builder<PaymentRequest>|null  $scope
     * @return array<string, mixed>
     */
    public static function kpis(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to, ?Builder $scope = null): array
    {
        $decided = self::base($workspace, $scope)->whereBetween('decided_at', [$from, $to]);
        $prevented = (clone $decided)->where('status', PaymentRequestStatus::Rejected->value)->whereNotNull('prevented_category');

        return [
            'checks' => self::base($workspace, $scope)->whereBetween('created_at', [$from, $to])->count(),
            'prevented_minor' => (int) (clone $prevented)->sum('prevented_amount_base_minor'),
            'prevented_cases' => (clone $prevented)->count(),
            'duplicates_prevented' => (clone $prevented)->where('prevented_category', PreventedCategory::Duplicate->value)->count(),
            'bank_changes_caught' => self::withSignal(clone $decided, 'BANK_DETAILS_CHANGED')->where('status', PaymentRequestStatus::Rejected->value)->count(),
            'legit_changes_verified' => (clone $decided)
                ->whereIn('status', [PaymentRequestStatus::Approved->value, PaymentRequestStatus::Paid->value])
                ->whereNotNull('satisfying_verification_id')
                ->whereExists(fn ($query) => $query->select(DB::raw(1))->from('bank_comparisons')
                    ->whereColumn('bank_comparisons.payment_request_id', 'payment_requests.id')
                    ->where('bank_comparisons.outcome', BankComparisonOutcome::Changed->value))
                ->count(),
            'median_decision_hours' => self::medianHours(clone $decided),
            'verification_compliance' => self::compliance(clone $decided),
            'expired_rate' => self::expiredRate(clone $decided),
        ];
    }

    /**
     * @param  Builder<PaymentRequest>  $query
     * @return Builder<PaymentRequest>
     */
    public static function withSignal(Builder $query, string $code): Builder
    {
        return $query->whereExists(fn ($inner) => $inner->select(DB::raw(1))->from('risk_signals')
            ->whereColumn('risk_signals.risk_assessment_id', 'payment_requests.risk_assessment_id')
            ->where('risk_signals.code', $code));
    }

    /**
     * @param  Builder<PaymentRequest>  $decided
     */
    public static function medianHours(Builder $decided): ?float
    {
        $rows = $decided->whereIn('status', [PaymentRequestStatus::Approved->value, PaymentRequestStatus::Paid->value, PaymentRequestStatus::Rejected->value])
            ->whereNotNull('submitted_at')
            ->get(['submitted_at', 'decided_at']);
        $values = [];

        foreach ($rows as $row) {
            $submitted = $row->getAttribute('submitted_at');
            $decidedAt = $row->getAttribute('decided_at');

            if ($submitted instanceof \DateTimeInterface && $decidedAt instanceof \DateTimeInterface) {
                $values[] = max(0, $decidedAt->getTimestamp() - $submitted->getTimestamp());
            }
        }

        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);
        $seconds = $count % 2 === 1 ? $values[$middle] : intdiv($values[$middle - 1] + $values[$middle], 2);

        return round($seconds / 3600, 1);
    }

    /**
     * @param  Builder<PaymentRequest>  $decided
     */
    public static function compliance(Builder $decided): ?int
    {
        $approved = $decided->whereIn('status', [PaymentRequestStatus::Approved->value, PaymentRequestStatus::Paid->value])->where('verification_required', true);
        $total = (clone $approved)->count();

        if ($total === 0) {
            return null;
        }

        return intdiv((clone $approved)->whereNotNull('satisfying_verification_id')->count() * 100, $total);
    }

    /**
     * @param  Builder<PaymentRequest>  $decided
     */
    public static function expiredRate(Builder $decided): ?int
    {
        $total = (clone $decided)->whereIn('status', [PaymentRequestStatus::Approved->value, PaymentRequestStatus::Paid->value, PaymentRequestStatus::Rejected->value, PaymentRequestStatus::Expired->value])->count();

        if ($total === 0) {
            return null;
        }

        return intdiv((clone $decided)->where('status', PaymentRequestStatus::Expired->value)->count() * 100, $total);
    }

    /**
     * @param  Builder<PaymentRequest>|null  $scope
     * @return list<array{week: string, low: int, medium: int, high: int, critical: int}>
     */
    public static function riskByWeek(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to, ?Builder $scope = null): array
    {
        $timezone = $workspace->timezone ?: 'UTC';
        $buckets = [];
        $cursor = $from->setTimezone($timezone)->startOfWeek(CarbonImmutable::MONDAY);
        $end = $to->setTimezone($timezone);

        for ($i = 0; $i < 60 && $cursor->lessThanOrEqualTo($end); $i++) {
            $buckets[$cursor->toDateString()] = ['week' => $cursor->toDateString(), 'low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
            $cursor = $cursor->addWeek();
        }

        foreach (self::base($workspace, $scope)->whereBetween('created_at', [$from, $to])->whereNotNull('risk_level')->get(['created_at', 'risk_level']) as $row) {
            $created = $row->getAttribute('created_at');
            $level = $row->getAttribute('risk_level');

            if (! $created instanceof \DateTimeInterface || ! $level instanceof RiskLevel) {
                continue;
            }

            $week = CarbonImmutable::instance($created)->setTimezone($timezone)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();

            if (isset($buckets[$week])) {
                $buckets[$week][$level->value]++;
            }
        }

        return array_values($buckets);
    }

    /**
     * @param  Builder<PaymentRequest>|null  $scope
     * @return list<array{month: string, fraud_minor: int, duplicate_minor: int, approved_minor: int}>
     */
    public static function byMonth(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to, ?Builder $scope = null): array
    {
        $timezone = $workspace->timezone ?: 'UTC';
        $buckets = [];
        $cursor = $from->setTimezone($timezone)->startOfMonth();
        $end = $to->setTimezone($timezone);

        for ($i = 0; $i < 36 && $cursor->lessThanOrEqualTo($end); $i++) {
            $buckets[$cursor->format('Y-m')] = ['month' => $cursor->format('Y-m'), 'fraud_minor' => 0, 'duplicate_minor' => 0, 'approved_minor' => 0];
            $cursor = $cursor->addMonthNoOverflow()->startOfMonth();
        }

        $rows = self::base($workspace, $scope)->whereBetween('decided_at', [$from, $to])
            ->whereIn('status', [PaymentRequestStatus::Rejected->value, PaymentRequestStatus::Approved->value, PaymentRequestStatus::Paid->value])
            ->get(['decided_at', 'status', 'prevented_category', 'prevented_amount_base_minor', 'amount_base_minor']);

        foreach ($rows as $row) {
            $decided = $row->getAttribute('decided_at');

            if (! $decided instanceof \DateTimeInterface) {
                continue;
            }

            $month = CarbonImmutable::instance($decided)->setTimezone($timezone)->format('Y-m');

            if (! isset($buckets[$month])) {
                continue;
            }

            $status = $row->getAttribute('status');
            $category = $row->getAttribute('prevented_category');

            if ($status === PaymentRequestStatus::Rejected && $category instanceof PreventedCategory) {
                $buckets[$month][$category === PreventedCategory::Fraud ? 'fraud_minor' : 'duplicate_minor'] += (int) $row->getAttribute('prevented_amount_base_minor');
            } elseif ($status !== PaymentRequestStatus::Rejected) {
                $buckets[$month]['approved_minor'] += (int) $row->getAttribute('amount_base_minor');
            }
        }

        return array_values($buckets);
    }

    /**
     * @param  Builder<PaymentRequest>|null  $scope
     * @return list<array{code: string, title: string, count: int, category: string}>
     */
    public static function signals(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to, int $limit, ?Builder $scope = null): array
    {
        $ids = self::base($workspace, $scope)->whereBetween('created_at', [$from, $to])->whereNotNull('risk_assessment_id')->pluck('risk_assessment_id')->all();

        if ($ids === []) {
            return [];
        }

        $rows = DB::table('risk_signals')
            ->where('workspace_id', $workspace->id)
            ->whereIn('risk_assessment_id', $ids)
            ->where('points', '>', 0)
            ->groupBy('code', 'category')
            ->orderByRaw('count(*) desc')
            ->orderBy('code')
            ->limit($limit)
            ->get(['code', 'category', DB::raw('count(*) as total')]);

        return array_values($rows->map(static fn (object $row): array => [
            'code' => (string) $row->code,
            'title' => SignalPresenter::title((string) $row->code),
            'count' => (int) $row->total,
            'category' => (string) $row->category,
        ])->all());
    }

    /**
     * @param  Builder<PaymentRequest>|null  $scope
     * @return list<array{bucket: string, count: int}>
     */
    public static function decisionHistogram(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to, ?Builder $scope = null): array
    {
        $buckets = ['<4h' => 0, '4-24h' => 0, '1-2d' => 0, '2-3d' => 0, '>3d' => 0];
        $rows = self::base($workspace, $scope)->whereBetween('decided_at', [$from, $to])->whereNotNull('submitted_at')
            ->whereIn('status', [PaymentRequestStatus::Approved->value, PaymentRequestStatus::Paid->value, PaymentRequestStatus::Rejected->value])
            ->get(['submitted_at', 'decided_at']);

        foreach ($rows as $row) {
            $submitted = $row->getAttribute('submitted_at');
            $decided = $row->getAttribute('decided_at');

            if (! $submitted instanceof \DateTimeInterface || ! $decided instanceof \DateTimeInterface) {
                continue;
            }

            $hours = ($decided->getTimestamp() - $submitted->getTimestamp()) / 3600;
            $key = match (true) {
                $hours < 4 => '<4h',
                $hours < 24 => '4-24h',
                $hours < 48 => '1-2d',
                $hours < 72 => '2-3d',
                default => '>3d',
            };
            $buckets[$key]++;
        }

        return array_map(static fn (string $bucket, int $count): array => ['bucket' => $bucket, 'count' => $count], array_keys($buckets), $buckets);
    }

    /**
     * @param  Builder<PaymentRequest>|null  $scope
     * @return list<array{kind: string, count: int, prevented_minor: int}>
     */
    public static function byKind(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to, ?Builder $scope = null): array
    {
        $out = [];
        /** @var Collection<int, PaymentRequest> $rows */
        $rows = self::base($workspace, $scope)->whereBetween('created_at', [$from, $to])->get(['kind', 'prevented_amount_base_minor', 'status']);

        foreach (PaymentRequestKind::cases() as $kind) {
            $matching = $rows->filter(static fn (PaymentRequest $row): bool => $row->getAttribute('kind') === $kind);
            $out[] = [
                'kind' => $kind->value,
                'count' => $matching->count(),
                'prevented_minor' => (int) $matching->filter(static fn (PaymentRequest $row): bool => $row->getAttribute('status') === PaymentRequestStatus::Rejected)->sum(static fn (PaymentRequest $row): int => (int) $row->getAttribute('prevented_amount_base_minor')),
            ];
        }

        return $out;
    }

    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable, preset: string}
     */
    public static function period(Workspace $workspace, ?string $preset, ?string $from, ?string $to, CarbonImmutable $now): array
    {
        $timezone = $workspace->timezone ?: 'UTC';
        $local = $now->setTimezone($timezone);

        if ($from !== null && $to !== null) {
            $start = CarbonImmutable::parse($from, $timezone)->startOfDay();
            $end = CarbonImmutable::parse($to, $timezone)->endOfDay();

            if ($end->lessThan($start)) {
                [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
            }

            return ['from' => $start->utc(), 'to' => $end->utc(), 'preset' => 'custom'];
        }

        $preset = in_array($preset, ['30d', '90d', 'ytd', '12m'], true) ? $preset : '30d';
        $start = match ($preset) {
            '90d' => $local->subDays(89)->startOfDay(),
            'ytd' => $local->startOfYear(),
            '12m' => $local->subMonthsNoOverflow(11)->startOfMonth(),
            default => $local->subDays(29)->startOfDay(),
        };

        return ['from' => $start->utc(), 'to' => $local->endOfDay()->utc(), 'preset' => $preset];
    }
}
