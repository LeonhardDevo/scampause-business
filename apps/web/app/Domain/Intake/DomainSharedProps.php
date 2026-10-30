<?php

declare(strict_types=1);

namespace App\Domain\Intake;

use App\Billing\PlanLimits;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Billing\PaymentRequestsMonthlyUsage;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Models\PaymentRequest;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final readonly class DomainSharedProps
{
    public function __construct(private CurrentWorkspace $current, private PlanLimits $limits) {}

    /**
     * @return array{scampause: \Closure(): (array<string, mixed>|null)}
     */
    public function share(Request $request): array
    {
        return ['scampause' => fn (): ?array => $this->resolve($request)];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(Request $request): ?array
    {
        $workspace = $this->current->get();
        $user = $request->user();

        if ($workspace === null || ! $user instanceof User) {
            return null;
        }

        $counts = Cache::remember('sp:counts:'.$workspace->id.':'.$user->id, 30, function () use ($workspace, $user): array {
            $visible = PaymentRequest::query()->forWorkspace($workspace)
                ->when(! $this->current->allows('payment_requests.view_all'), fn ($query) => $query->where('requested_by', $user->id));

            return [
                'review' => (clone $visible)->where('status', PaymentRequestStatus::Review->value)->count(),
                'awaiting_my_approval' => Approval::query()->forWorkspace($workspace)
                    ->where('approver_id', $user->id)
                    ->where('status', ApprovalStatus::Pending->value)
                    ->whereHas('paymentRequest', fn ($query) => $query->where('status', PaymentRequestStatus::AwaitingApproval->value))
                    ->count(),
                'awaiting_verification' => (clone $visible)->where('status', PaymentRequestStatus::AwaitingVerification->value)->count(),
            ];
        });

        $approvers = $workspace->members()
            ->wherePivotIn('role', [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value, WorkspaceRole::Approver->value])
            ->count();

        return [
            'counts' => $counts,
            'solo_mode' => $approvers === 1,
            'plan_usage' => [
                'payment_requests' => [
                    'used' => $this->limits->used($workspace, PaymentRequestsMonthlyUsage::KEY),
                    'limit' => $this->limits->limit($workspace, PaymentRequestsMonthlyUsage::KEY),
                ],
            ],
            'base_currency' => strtoupper($workspace->currency),
        ];
    }
}
