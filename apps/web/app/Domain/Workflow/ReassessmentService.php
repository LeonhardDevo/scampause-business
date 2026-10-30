<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Enums\RouteStatus;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Approvals\Models\ApprovalRoute;
use App\Domain\Approvals\RouteBuilder;
use App\Domain\Evidence\Enums\EvidenceChannel;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\Presenters\CaseFilePresenter;
use App\Domain\Intake\StateMachine;
use App\Domain\Notifications\DomainNotifier;
use App\Domain\Risk\Enums\AssessmentTrigger;
use App\Domain\Risk\Enums\RiskLevel;
use App\Domain\Risk\RiskAssessor;
use App\Domain\Vendors\Enums\ContactStatus;
use App\Domain\Vendors\Models\VendorContact;
use App\Locale\WithLocale;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

final readonly class ReassessmentService
{
    public const array ACTIVE_STATUSES = ['review', 'awaiting_verification', 'awaiting_approval'];

    public function __construct(
        private RiskAssessor $assessor,
        private RouteBuilder $routes,
        private EvidenceLedger $ledger,
        private DomainNotifier $notifier,
        private ClockInterface $clock,
    ) {}

    public function reassess(PaymentRequest $request, AssessmentTrigger $trigger, ?int $actorId = null, EvidenceChannel $channel = EvidenceChannel::Web): void
    {
        $beforeLevel = $request->getAttribute('risk_level');
        $beforeRequired = (bool) $request->getAttribute('verification_required');
        $this->assessor->assess($request, $trigger, $actorId, $channel, refreshComparison: true);
        $request->refresh();

        if ($request->getAttribute('status') !== PaymentRequestStatus::AwaitingApproval) {
            return;
        }

        $afterLevel = $request->getAttribute('risk_level');
        $raised = $beforeLevel instanceof RiskLevel && $afterLevel instanceof RiskLevel && $afterLevel->rank() > $beforeLevel->rank();
        $newlyRequired = (bool) $request->getAttribute('verification_required') && ! $beforeRequired;

        if ($raised || $newlyRequired) {
            $this->requireVerification($request, $trigger, $actorId);
        }
    }

    public function requireVerification(PaymentRequest $request, AssessmentTrigger $trigger, ?int $actorId): void
    {
        $workspace = Workspace::query()->findOrFail((int) $request->getAttribute('workspace_id'));
        StateMachine::assert(PaymentRequestStatus::AwaitingApproval, PaymentRequestStatus::AwaitingVerification);
        $now = CarbonImmutable::instance($this->clock->now());
        $routeId = $request->getAttribute('current_route_id');
        $approverIds = [];

        if (is_int($routeId)) {
            $route = ApprovalRoute::query()->forWorkspace($workspace)->whereKey($routeId)->lockForUpdate()->first();

            if ($route !== null && $route->getAttribute('status') === RouteStatus::Active) {
                $approverIds = Approval::query()->forWorkspace($workspace)->where('approval_route_id', $route->getKey())
                    ->whereIn('status', [ApprovalStatus::Pending->value, ApprovalStatus::Approved->value])
                    ->pluck('approver_id')->map(static fn (mixed $id): int => (int) $id)->all();
                $route->forceFill(['status' => RouteStatus::Superseded, 'closed_at' => $now])->save();
                $this->routes->revokePending($route, $workspace, 'route_closed');
            }
        }

        $request->forceFill([
            'status' => PaymentRequestStatus::AwaitingVerification,
            'verification_required' => true,
            'verification_satisfied_at' => null,
            'satisfying_verification_id' => null,
            'current_route_id' => null,
            'expires_at' => null,
        ])->save();

        $this->ledger->append(
            workspaceId: $workspace->id,
            event: 'request.verification_required_again',
            subjectType: 'payment_request',
            subjectId: (int) $request->getKey(),
            payload: ['trigger' => $trigger->value],
            actorUserId: $actorId,
            channel: $actorId === null ? EvidenceChannel::System : EvidenceChannel::Web,
            paymentRequestId: (int) $request->getKey(),
        );

        $this->notifier->notify(
            'verification_required',
            [...DomainNotifier::users($approverIds), ...DomainNotifier::membersWithAbility($workspace, 'payment_requests.verify')],
            $workspace->id,
            $request,
            [DomainNotifier::DATABASE, DomainNotifier::TELEGRAM],
            self::verificationExtra($request),
            'warning',
            '/payment-requests/'.$request->getKey().'?tab=verification',
        );
    }

    public function forVendorAfterCommit(int $workspaceId, int $vendorId): void
    {
        DB::afterCommit(function () use ($workspaceId, $vendorId): void {
            $ids = PaymentRequest::query()->forWorkspace($workspaceId)
                ->where('vendor_id', $vendorId)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->orderBy('id')
                ->pluck('id')
                ->all();

            foreach ($ids as $id) {
                DB::transaction(function () use ($workspaceId, $id): void {
                    $request = RequestLock::lock($workspaceId, (int) $id);

                    if (in_array($request->getAttribute('status')?->value, self::ACTIVE_STATUSES, true)) {
                        $this->reassess($request, AssessmentTrigger::MasterDataChanged, null, EvidenceChannel::System);
                    }
                });
            }
        });
    }

    /**
     * @return array<string, string|\Closure(string): string>
     */
    public static function verificationExtra(PaymentRequest $request): array
    {
        $extra = [
            'reasons' => static function (string $locale) use ($request): string {
                $reasons = $request->getAttribute('verification_reasons');
                $labels = array_map(
                    static fn (string $reason): string => WithLocale::run($locale, static fn (): string => CaseFilePresenter::reasonLabel($reason)),
                    is_array($reasons) ? array_values(array_filter($reasons, 'is_string')) : [],
                );

                return implode(', ', array_values(array_unique($labels)));
            },
        ];
        $vendorId = $request->getAttribute('vendor_id');
        $contact = is_int($vendorId) ? VendorContact::query()->forWorkspace((int) $request->getAttribute('workspace_id'))
            ->where('vendor_id', $vendorId)
            ->where('is_callback', true)
            ->where('status', ContactStatus::Verified->value)
            ->orderBy('id')
            ->first() : null;

        if ($contact instanceof VendorContact && is_string($phone = $contact->getAttribute('phone_e164')) && $phone !== '') {
            $extra['callback_contact'] = (string) $contact->getAttribute('name').', '.$phone;
            $source = $contact->getAttribute('source_ref');
            $extra['source_ref'] = is_string($source) && $source !== '' ? $source : '—';
        }

        return $extra;
    }
}
