<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Domain\Approvals\Data\ApproverCandidate;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Evidence\Models\EvidenceEntry;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Policy\Models\MemberLimit;
use App\Domain\Policy\PaymentSettings;
use App\Domain\Policy\PolicyEvaluator;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

final readonly class ApproverPool
{
    public function __construct(private ClockInterface $clock) {}

    /**
     * @return list<ApproverCandidate>
     */
    public function members(Workspace $workspace): array
    {
        $candidates = [];

        foreach ($workspace->members()->orderBy('users.id')->get() as $user) {
            $role = $user->membership?->role;
            $role = $role instanceof WorkspaceRole ? $role : WorkspaceRole::tryFrom((string) $role);

            if ($role === null || $user->getAttribute('deleted_at') !== null || ! $role->allows('payment_requests.approve')) {
                continue;
            }

            $candidates[] = new ApproverCandidate($user, $role);
        }

        return $candidates;
    }

    public function soloMode(Workspace $workspace): bool
    {
        return count($this->members($workspace)) === 1;
    }

    /**
     * @param  list<string>  $approverRoles
     * @param  list<int>  $excludedUserIds
     * @return list<ApproverCandidate>
     */
    public function eligible(
        Workspace $workspace,
        array $approverRoles,
        ?int $amountBaseMinor,
        array $excludedUserIds,
        ?int $excludeRequestId = null,
        bool $solo = false,
    ): array {
        $eligible = [];
        $graceHours = (int) PaymentSettings::for($workspace)->getAttribute('new_approver_grace_hours');

        foreach ($this->members($workspace) as $candidate) {
            if ($this->isEligible($workspace, $candidate, $approverRoles, $amountBaseMinor, $excludedUserIds, $excludeRequestId, $solo, $graceHours)) {
                $eligible[] = $candidate;
            }
        }

        return $this->order($workspace, $eligible);
    }

    /**
     * @param  list<string>  $approverRoles
     * @param  list<int>  $excludedUserIds
     */
    public function isEligible(
        Workspace $workspace,
        ApproverCandidate $candidate,
        array $approverRoles,
        ?int $amountBaseMinor,
        array $excludedUserIds,
        ?int $excludeRequestId = null,
        bool $solo = false,
        ?int $graceHours = null,
    ): bool {
        $userId = $candidate->user->id;

        if (! in_array($candidate->role->value, $approverRoles, true) || ! $candidate->role->allows('payment_requests.approve')) {
            return false;
        }

        if (! $solo && in_array($userId, $excludedUserIds, true)) {
            return false;
        }

        if (! $this->withinLimits($workspace, $userId, $amountBaseMinor, $excludeRequestId)) {
            return false;
        }

        if ($solo) {
            return true;
        }

        $grace = $graceHours ?? (int) PaymentSettings::for($workspace)->getAttribute('new_approver_grace_hours');

        return ! $this->inGracePeriod($workspace->id, $userId, $grace);
    }

    public function withinLimits(Workspace $workspace, int $userId, ?int $amountBaseMinor, ?int $excludeRequestId = null): bool
    {
        $limit = MemberLimit::query()->forWorkspace($workspace)->where('user_id', $userId)->first();
        $perPayment = $limit?->getAttribute('approval_limit_base_minor');
        $monthly = $limit?->getAttribute('monthly_limit_base_minor');

        if ($amountBaseMinor === null) {
            return $perPayment === null && $monthly === null;
        }

        if (is_int($perPayment) && $perPayment < $amountBaseMinor) {
            return false;
        }

        if (is_int($monthly)) {
            return $this->approvedThisMonth($workspace, $userId, $excludeRequestId) + $amountBaseMinor <= $monthly;
        }

        return true;
    }

    public function approvedThisMonth(Workspace $workspace, int $userId, ?int $excludeRequestId = null): int
    {
        $timezone = $workspace->timezone ?: 'UTC';
        $start = CarbonImmutable::instance($this->clock->now())->setTimezone($timezone)->startOfMonth()->utc();
        $end = $start->setTimezone($timezone)->addMonthNoOverflow()->startOfMonth()->utc();

        $approved = (int) DB::table('approvals')
            ->join('payment_requests', 'payment_requests.id', '=', 'approvals.payment_request_id')
            ->where('approvals.workspace_id', $workspace->id)
            ->where('approvals.approver_id', $userId)
            ->where('approvals.status', ApprovalStatus::Approved->value)
            ->whereIn('payment_requests.status', [PaymentRequestStatus::Approved->value, PaymentRequestStatus::Paid->value])
            ->where('payment_requests.decided_at', '>=', $start)
            ->where('payment_requests.decided_at', '<', $end)
            ->when($excludeRequestId !== null, fn ($query) => $query->where('payment_requests.id', '<>', $excludeRequestId))
            ->sum('payment_requests.amount_base_minor');

        $reserved = (int) DB::table('approvals')
            ->join('payment_requests', 'payment_requests.id', '=', 'approvals.payment_request_id')
            ->where('approvals.workspace_id', $workspace->id)
            ->where('approvals.approver_id', $userId)
            ->where('approvals.status', ApprovalStatus::Pending->value)
            ->where('payment_requests.status', PaymentRequestStatus::AwaitingApproval->value)
            ->when($excludeRequestId !== null, fn ($query) => $query->where('payment_requests.id', '<>', $excludeRequestId))
            ->sum('payment_requests.amount_base_minor');

        return $approved + $reserved;
    }

    public function inGracePeriod(int $workspaceId, int $userId, int $graceHours): bool
    {
        if ($graceHours <= 0) {
            return false;
        }

        $since = CarbonImmutable::instance($this->clock->now())->subHours($graceHours);

        return EvidenceEntry::query()->forWorkspace($workspaceId)
            ->where('event', 'member.role_changed')
            ->where('subject_id', $userId)
            ->where('occurred_at', '>', $since)
            ->exists();
    }

    public function lock(int $workspaceId, int $userId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['sp-approver:'.$workspaceId.':'.$userId]);
    }

    /**
     * @param  list<ApproverCandidate>  $candidates
     * @return list<ApproverCandidate>
     */
    public function order(Workspace $workspace, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $ids = array_map(static fn (ApproverCandidate $candidate): int => $candidate->user->id, $candidates);
        $open = DB::table('approvals')
            ->where('workspace_id', $workspace->id)
            ->whereIn('approver_id', $ids)
            ->where('status', ApprovalStatus::Pending->value)
            ->groupBy('approver_id')
            ->selectRaw('approver_id, count(*) as total')
            ->pluck('total', 'approver_id')
            ->all();
        $last = DB::table('approvals')
            ->where('workspace_id', $workspace->id)
            ->whereIn('approver_id', $ids)
            ->groupBy('approver_id')
            ->selectRaw('approver_id, max(created_at) as latest')
            ->pluck('latest', 'approver_id')
            ->all();

        usort($candidates, static function (ApproverCandidate $a, ApproverCandidate $b) use ($open, $last): int {
            $openA = (int) ($open[$a->user->id] ?? 0);
            $openB = (int) ($open[$b->user->id] ?? 0);

            if ($openA !== $openB) {
                return $openA <=> $openB;
            }

            $lastA = isset($last[$a->user->id]) ? CarbonImmutable::parse((string) $last[$a->user->id])->getTimestamp() : null;
            $lastB = isset($last[$b->user->id]) ? CarbonImmutable::parse((string) $last[$b->user->id])->getTimestamp() : null;

            if ($lastA !== $lastB) {
                if ($lastA === null) {
                    return -1;
                }

                if ($lastB === null) {
                    return 1;
                }

                return $lastA <=> $lastB;
            }

            return $a->user->id <=> $b->user->id;
        });

        return $candidates;
    }

    /**
     * @param  list<ApproverCandidate>  $eligible
     * @param  list<string>  $requiredRoles
     * @return list<ApproverCandidate>
     */
    public static function pickSlots(array $eligible, int $slots, array $requiredRoles, ?int $verifierId): array
    {
        $picked = [];
        $taken = [];
        $verifierSlots = 0;

        foreach ([...PolicyEvaluator::ordered($requiredRoles), null] as $role) {
            foreach ($eligible as $candidate) {
                if (count($picked) >= $slots) {
                    break 2;
                }

                $id = $candidate->user->id;
                $isVerifier = $verifierId !== null && $id === $verifierId;

                if (isset($taken[$id]) || ($isVerifier && $slots >= 2 && $verifierSlots >= 1)) {
                    continue;
                }

                if ($role !== null && PolicyEvaluator::roleRank($candidate->role->value) < PolicyEvaluator::roleRank($role)) {
                    continue;
                }

                $picked[] = $candidate;
                $taken[$id] = true;
                $verifierSlots += $isVerifier ? 1 : 0;

                if ($role !== null) {
                    break;
                }
            }
        }

        return $picked;
    }

    /**
     * @param  list<string>  $requiredRoles
     * @param  list<string>  $approverRoles
     */
    public static function rolesCovered(array $requiredRoles, array $approverRoles): bool
    {
        $remaining = $approverRoles;

        foreach (PolicyEvaluator::ordered($requiredRoles) as $role) {
            $found = null;

            foreach ($remaining as $index => $have) {
                if (PolicyEvaluator::roleRank($have) >= PolicyEvaluator::roleRank($role)) {
                    $found = $index;

                    break;
                }
            }

            if ($found === null) {
                return false;
            }

            unset($remaining[$found]);
        }

        return true;
    }

    public static function roleOf(User $user, Workspace $workspace): ?WorkspaceRole
    {
        return $user->roleIn($workspace);
    }
}
