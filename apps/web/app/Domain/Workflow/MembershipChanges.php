<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

use App\Domain\Approvals\ApprovalPublisher;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Enums\RouteStatus;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Approvals\Models\ApprovalRoute;
use App\Domain\Approvals\RouteBuilder;
use App\Domain\Evidence\Enums\EvidenceChannel;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Psr\Clock\ClockInterface;

final readonly class MembershipChanges
{
    public function __construct(
        private GovernanceRecorder $governance,
        private RouteBuilder $routes,
        private ApprovalPublisher $publisher,
        private EvidenceLedger $ledger,
        private ClockInterface $clock,
    ) {}

    public function roleChanged(Workspace $workspace, int $userId, ?string $from, ?string $to, ?User $actor): void
    {
        $this->governance->record($workspace, 'member.role_changed', 'user', $userId, ['user_id' => $userId, 'from' => $from, 'to' => $to], $actor, '/settings/members');
        $role = $to !== null ? WorkspaceRole::tryFrom($to) : null;
        $this->revokeIneligible($workspace, $userId, $role);
    }

    public function removed(Workspace $workspace, int $userId, ?string $from, ?User $actor): void
    {
        $this->governance->record($workspace, 'member.role_changed', 'user', $userId, ['user_id' => $userId, 'from' => $from, 'to' => null], $actor, '/settings/members');
        $this->revokeIneligible($workspace, $userId, null);
    }

    private function revokeIneligible(Workspace $workspace, int $userId, ?WorkspaceRole $role): void
    {
        $pending = Approval::query()->forWorkspace($workspace)
            ->where('approver_id', $userId)
            ->where('status', ApprovalStatus::Pending->value)
            ->orderBy('id')
            ->get();

        foreach ($pending as $approval) {
            $route = ApprovalRoute::query()->forWorkspace($workspace)->whereKey((int) $approval->getAttribute('approval_route_id'))->lockForUpdate()->first();

            if ($route === null || $route->getAttribute('status') !== RouteStatus::Active) {
                continue;
            }

            $roles = is_array($r = $route->getAttribute('approver_roles')) ? $r : [];

            if ($role !== null && $role->allows('payment_requests.approve') && in_array($role->value, $roles, true)) {
                continue;
            }

            $approval->forceFill(['status' => ApprovalStatus::Revoked, 'decided_at' => CarbonImmutable::instance($this->clock->now()), 'telegram_nonce_hash' => null])->save();
            $this->ledger->append(
                workspaceId: $workspace->id,
                event: 'approval.revoked_membership',
                subjectType: 'approval',
                subjectId: (int) $approval->getKey(),
                payload: ['approval_id' => (int) $approval->getKey(), 'user_id' => $userId],
                channel: EvidenceChannel::System,
                paymentRequestId: (int) $approval->getAttribute('payment_request_id'),
            );
            $this->publisher->stripButtons($approval, 'revoked');

            $added = $this->routes->fillMissing($route);
            $live = Approval::query()->forWorkspace($workspace)->where('approval_route_id', $route->getKey())
                ->whereIn('status', [ApprovalStatus::Pending->value, ApprovalStatus::Approved->value])->count();

            if ($added === 0 && $live === 0) {
                $request = RequestLock::lock($workspace->id, (int) $route->getAttribute('payment_request_id'));

                if ($request->getAttribute('status') === PaymentRequestStatus::AwaitingApproval) {
                    $this->routes->markIncomplete($request, $route, 'membership');
                }
            }
        }
    }
}
