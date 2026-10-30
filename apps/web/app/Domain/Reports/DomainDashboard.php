<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Approvals\ApproverPool;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Intake\Enums\BankComparisonOutcome;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Models\BankComparison;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\Presenters\CaseFilePresenter;
use App\Domain\Intake\Presenters\PaymentRequestRows;
use App\Domain\Intake\Presenters\Refs;
use App\Domain\Policy\Models\ApprovalRule;
use App\Domain\Reports\Contracts\DashboardData;
use App\Domain\Risk\Enums\RiskLevel;
use App\Domain\Vendors\Enums\ContactStatus;
use App\Domain\Vendors\Models\Vendor;
use App\Domain\Vendors\Models\VendorContact;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Psr\Clock\ClockInterface;

final readonly class DomainDashboard implements DashboardData
{
    public function __construct(
        private CurrentWorkspace $current,
        private CaseFilePresenter $presenter,
        private ApproverPool $pool,
        private ClockInterface $clock,
    ) {}

    public function props(Workspace $workspace, User $user): array
    {
        $now = CarbonImmutable::instance($this->clock->now());
        $timezone = $workspace->timezone ?: 'UTC';
        $from = $now->setTimezone($timezone)->subDays(29)->startOfDay()->utc();
        $scope = $this->scope($workspace, $user);
        $kpis = ReportsData::kpis($workspace, $from, $now, $scope);
        $base = strtoupper($workspace->currency);
        $myApprovals = Approval::query()->forWorkspace($workspace)
            ->where('approver_id', $user->id)
            ->where('status', ApprovalStatus::Pending->value)
            ->whereHas('paymentRequest', fn ($query) => $query->where('status', PaymentRequestStatus::AwaitingApproval->value))
            ->count();

        return [
            'period' => ['from' => $from->setTimezone($timezone)->toDateString(), 'to' => $now->setTimezone($timezone)->toDateString()],
            'kpis' => [
                'open_checks' => (clone $scope)->whereIn('status', array_map(static fn (PaymentRequestStatus $s): string => $s->value, PaymentRequestStatus::open()))->count(),
                'awaiting_my_approval' => $myApprovals,
                'awaiting_verification' => (clone $scope)->where('status', PaymentRequestStatus::AwaitingVerification->value)->count(),
                'high_or_critical_30d' => (clone $scope)->where('created_at', '>=', $from)->whereIn('risk_level', [RiskLevel::High->value, RiskLevel::Critical->value])->count(),
                'prevented_amount_30d' => Refs::money((int) $kpis['prevented_minor'], $base),
                'prevented_cases_30d' => (int) $kpis['prevented_cases'],
                'median_decision_hours_30d' => $kpis['median_decision_hours'],
                'verification_compliance_30d' => $kpis['verification_compliance'],
            ],
            'attention' => $this->attention($workspace, $user, $scope, $now),
            'recent_bank_changes' => $this->bankChanges($workspace, $user, $scope),
            'charts' => [
                'risk_by_week' => ReportsData::riskByWeek($workspace, $now->setTimezone($timezone)->startOfWeek(CarbonImmutable::MONDAY)->subWeeks(11)->utc(), $now, $scope),
                'prevented_vs_approved' => array_map(static fn (array $row): array => [
                    'month' => $row['month'],
                    'prevented_minor' => $row['fraud_minor'] + $row['duplicate_minor'],
                    'approved_minor' => $row['approved_minor'],
                ], ReportsData::byMonth($workspace, $now->setTimezone($timezone)->startOfMonth()->subMonthsNoOverflow(5)->utc(), $now, $scope)),
                'top_signals' => array_map(static fn (array $row): array => ['code' => $row['code'], 'title' => $row['title'], 'count' => $row['count']], ReportsData::signals($workspace, $now->subDays(90), $now, 8, $scope)),
                'decision_sla' => [
                    'on_time' => (clone $scope)->whereIn('status', [PaymentRequestStatus::Approved->value, PaymentRequestStatus::Paid->value])->where('decided_at', '>=', $from)->count(),
                    'expired' => (clone $scope)->where('status', PaymentRequestStatus::Expired->value)->where('decided_at', '>=', $from)->count(),
                    'pending' => (clone $scope)->where('status', PaymentRequestStatus::AwaitingApproval->value)->count(),
                ],
            ],
            'onboarding' => [
                'has_vendors' => Vendor::query()->forWorkspace($workspace)->exists(),
                'has_callback_contacts' => VendorContact::query()->forWorkspace($workspace)->where('is_callback', true)->where('status', ContactStatus::Verified->value)->exists(),
                'has_rules' => ApprovalRule::query()->forWorkspace($workspace)->where('is_active', true)->exists(),
                'telegram_linked' => $user->telegramAccount !== null,
                'has_second_approver' => count($this->pool->members($workspace)) >= 2,
            ],
        ];
    }

    /**
     * @return Builder<PaymentRequest>
     */
    private function scope(Workspace $workspace, User $user): Builder
    {
        $query = PaymentRequest::query()->forWorkspace($workspace);

        if (! $this->current->allows('payment_requests.view_all')) {
            $query->where('requested_by', $user->id);
        }

        return $query;
    }

    /**
     * @param  Builder<PaymentRequest>  $scope
     * @return list<array<string, mixed>>
     */
    private function attention(Workspace $workspace, User $user, Builder $scope, CarbonImmutable $now): array
    {
        $ids = [];
        $mine = Approval::query()->forWorkspace($workspace)->where('approver_id', $user->id)->where('status', ApprovalStatus::Pending->value)
            ->orderBy('created_at')->limit(6)->pluck('payment_request_id')->all();
        $queries = [
            PaymentRequest::query()->forWorkspace($workspace)->whereIn('id', $mine)->where('status', PaymentRequestStatus::AwaitingApproval->value),
            (clone $scope)->where('status', PaymentRequestStatus::AwaitingVerification->value)->orderByDesc('risk_score'),
            (clone $scope)->where('status', PaymentRequestStatus::Review->value)->where('risk_level', RiskLevel::Critical->value)->orderByDesc('created_at'),
            (clone $scope)->where('status', PaymentRequestStatus::AwaitingApproval->value)->where('expires_at', '<', $now->addHours(12))->orderBy('expires_at'),
        ];

        foreach ($queries as $query) {
            foreach ($query->limit(6)->pluck('id')->all() as $id) {
                if (count($ids) < 6 && ! in_array((int) $id, $ids, true)) {
                    $ids[] = (int) $id;
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        $requests = PaymentRequest::query()->forWorkspace($workspace)->whereIn('id', $ids)->get()->sortBy(static fn (PaymentRequest $request): int => (int) array_search((int) $request->getKey(), $ids, true))->values();

        return PaymentRequestRows::many($requests, $user);
    }

    /**
     * @param  Builder<PaymentRequest>  $scope
     * @return list<array<string, mixed>>
     */
    private function bankChanges(Workspace $workspace, User $user, Builder $scope): array
    {
        $requestIds = BankComparison::query()->forWorkspace($workspace)
            ->where('outcome', BankComparisonOutcome::Changed->value)
            ->orderByDesc('id')
            ->limit(50)
            ->pluck('payment_request_id')
            ->unique()
            ->values()
            ->all();
        $requests = (clone $scope)->whereIn('id', $requestIds)->orderByDesc('created_at')->limit(5)->get();
        $rows = PaymentRequestRows::many($requests, $user);
        $out = [];

        foreach ($requests->values() as $index => $request) {
            $diff = $this->presenter->bankDiff($request, $workspace->id);
            $out[] = [
                'request' => $rows[$index],
                'diff' => [
                    'outcome' => $diff['outcome'] ?? null,
                    'previous' => $diff['previous'] ?? null,
                    'incoming' => $diff['incoming'] ?? null,
                ],
            ];
        }

        return $out;
    }
}
