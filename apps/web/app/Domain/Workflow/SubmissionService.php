<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

use App\Audit\Auditor;
use App\Domain\Approvals\RouteBuilder;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Exceptions\InvalidTransition;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\Pipeline;
use App\Domain\Intake\PipelineService;
use App\Domain\Notifications\DomainNotifier;
use App\Domain\Policy\PaymentSettings;
use App\Domain\Risk\Enums\AssessmentTrigger;
use App\Domain\Risk\RiskAssessor;
use App\Domain\Vendors\Enums\VendorStatus;
use App\Domain\Vendors\Models\BlockedBankAccount;
use App\Domain\Vendors\Models\Vendor;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

final readonly class SubmissionService
{
    public function __construct(
        private PipelineService $pipeline,
        private RiskAssessor $assessor,
        private RouteBuilder $routes,
        private DomainNotifier $notifier,
        private EvidenceLedger $ledger,
        private Auditor $auditor,
        private ClockInterface $clock,
    ) {}

    public function submit(PaymentRequest $subject, User $actor, bool $unknownPayeeAcknowledged = false, bool $confirmAiFields = false): PaymentRequest
    {
        return DB::transaction(function () use ($subject, $actor, $unknownPayeeAcknowledged, $confirmAiFields): PaymentRequest {
            $request = $this->pipeline->lock((int) $subject->getAttribute('workspace_id'), (int) $subject->getKey());
            /** @var PaymentRequestStatus $status */
            $status = $request->getAttribute('status');
            if ($status !== PaymentRequestStatus::Review) {
                throw InvalidTransition::between($status, PaymentRequestStatus::AwaitingApproval);
            }

            $workspace = Workspace::query()->findOrFail((int) $request->getAttribute('workspace_id'));
            $pipeline = Pipeline::of($request);

            if ($pipeline->aiFields() !== []) {
                if (! $confirmAiFields) {
                    throw new WorkflowException('confirm_ai_fields', 'confirm_ai_fields');
                }

                $fields = $pipeline->aiFields();
                $pipeline->addManualFields($fields)->setAiFields([])->writeTo($request);
                $this->ledger->append(
                    workspaceId: $workspace->id,
                    event: 'request.ai_fields_confirmed',
                    subjectType: 'payment_request',
                    subjectId: (int) $request->getKey(),
                    payload: ['fields' => $fields],
                    actorUserId: $actor->id,
                    paymentRequestId: (int) $request->getKey(),
                );
            }

            $this->guardCompleteness($request, $workspace, $unknownPayeeAcknowledged);

            if ($request->getAttribute('vendor_id') === null && $unknownPayeeAcknowledged) {
                $pipeline->put('unknown_payee_acknowledged', 'yes')->writeTo($request);
            }

            $now = CarbonImmutable::instance($this->clock->now());
            $request->forceFill(['submitted_by' => $actor->id, 'submitted_at' => $now])->save();
            $this->assessor->assess($request, AssessmentTrigger::Submit, $actor->id, refreshComparison: true);
            $request->refresh();

            if (is_string($fp = $request->getAttribute('payee_fingerprint')) && BlockedBankAccount::query()->forWorkspace($workspace)->where('fingerprint', $fp)->exists()) {
                throw new WorkflowException('blocklisted');
            }

            $required = (bool) $request->getAttribute('verification_required');
            $reasons = $request->getAttribute('verification_reasons');
            $reasons = is_array($reasons) ? array_values(array_filter($reasons, 'is_string')) : [];

            $this->ledger->append(
                workspaceId: $workspace->id,
                event: 'request.submitted',
                subjectType: 'payment_request',
                subjectId: (int) $request->getKey(),
                payload: ['verification_required' => $required, 'reasons' => $reasons, 'unknown_payee_acknowledged' => $request->getAttribute('vendor_id') === null],
                actorUserId: $actor->id,
                paymentRequestId: (int) $request->getKey(),
            );
            $this->auditor->record('payment_request.submitted', $request, ['status' => ['review', $required ? 'awaiting_verification' : 'awaiting_approval']], actor: $actor, workspace: $workspace, subjectLabel: (string) $request->getAttribute('reference'));

            if ($required) {
                $request->forceFill(['status' => PaymentRequestStatus::AwaitingVerification])->save();
                $this->notifyVerification($request, $workspace, $actor);

                return $request;
            }

            $request->forceFill(['status' => PaymentRequestStatus::AwaitingApproval])->save();
            $this->routes->build($request, $actor);

            return $request;
        });
    }

    public function reopen(PaymentRequest $subject, User $actor): PaymentRequest
    {
        return DB::transaction(function () use ($subject, $actor): PaymentRequest {
            $request = $this->pipeline->lock((int) $subject->getAttribute('workspace_id'), (int) $subject->getKey());
            /** @var PaymentRequestStatus $status */
            $status = $request->getAttribute('status');

            if ($status !== PaymentRequestStatus::Expired) {
                throw InvalidTransition::between($status, PaymentRequestStatus::AwaitingApproval);
            }

            $workspace = Workspace::query()->findOrFail((int) $request->getAttribute('workspace_id'));
            $this->assessor->assess($request, AssessmentTrigger::Reopen, $actor->id, refreshComparison: true);
            $request->refresh();
            $required = (bool) $request->getAttribute('verification_required');
            $fresh = $this->verificationFresh($request, $workspace);
            $request->forceFill(['decided_at' => null, 'decided_by' => null, 'current_route_id' => null, 'expires_at' => null]);

            if ($required && ! $fresh) {
                $request->forceFill([
                    'status' => PaymentRequestStatus::AwaitingVerification,
                    'verification_satisfied_at' => null,
                    'satisfying_verification_id' => null,
                ])->save();
            } else {
                $request->forceFill(['status' => PaymentRequestStatus::AwaitingApproval])->save();
            }

            $nextAttempt = (int) $request->routes()->max('attempt') + 1;
            $this->ledger->append(
                workspaceId: $workspace->id,
                event: 'request.reopened',
                subjectType: 'payment_request',
                subjectId: (int) $request->getKey(),
                payload: ['attempt' => $nextAttempt],
                actorUserId: $actor->id,
                paymentRequestId: (int) $request->getKey(),
            );
            $this->auditor->record('payment_request.reopened', $request, ['status' => ['expired', (string) $request->getAttribute('status')?->value]], actor: $actor, workspace: $workspace, subjectLabel: (string) $request->getAttribute('reference'));

            if ($request->getAttribute('status') === PaymentRequestStatus::AwaitingApproval) {
                $this->routes->build($request, $actor);
            } else {
                $this->notifyVerification($request, $workspace, $actor);
            }

            return $request;
        });
    }

    public function verificationFresh(PaymentRequest $request, Workspace $workspace): bool
    {
        $satisfied = $request->getAttribute('verification_satisfied_at');

        if (! $satisfied instanceof \DateTimeInterface || ! is_int($request->getAttribute('satisfying_verification_id'))) {
            return false;
        }

        $days = (int) PaymentSettings::for($workspace)->getAttribute('verification_valid_days');
        $threshold = CarbonImmutable::instance($this->clock->now())->subDays($days);

        return CarbonImmutable::instance($satisfied)->greaterThanOrEqualTo($threshold)
            && Pipeline::of($request)->value('verified_fingerprint') === $request->getAttribute('payee_fingerprint');
    }

    private function guardCompleteness(PaymentRequest $request, Workspace $workspace, bool $unknownPayeeAcknowledged): void
    {
        $kind = $request->getAttribute('kind');
        $announcement = in_array($kind, [PaymentRequestKind::BankDetailsChange, PaymentRequestKind::PayrollChange], true);
        $amount = $request->getAttribute('amount_minor');
        $currency = $request->getAttribute('currency');

        if (! $announcement || $amount !== null) {
            if (! is_int($amount) || $amount <= 0) {
                throw new WorkflowException('amount_required', 'amount');
            }

            if (! is_string($currency) || $currency === '') {
                throw new WorkflowException('currency_required', 'currency');
            }
        }

        $vendorId = $request->getAttribute('vendor_id');

        if (! is_int($vendorId) && ! $unknownPayeeAcknowledged) {
            throw new WorkflowException('vendor_required', 'vendor_id');
        }

        if (is_int($vendorId)) {
            $vendor = Vendor::query()->forWorkspace($workspace)->withTrashed()->find($vendorId);
            $status = $vendor?->getAttribute('status');

            if ($vendor === null || $vendor->trashed() || in_array($status, [VendorStatus::OnHold, VendorStatus::Archived], true)) {
                throw new WorkflowException('vendor_not_active', 'vendor_id');
            }
        }

        if ($kind !== PaymentRequestKind::Other && $request->getAttribute('payee_account_number') === null) {
            throw new WorkflowException('payee_required', 'payee');
        }

        if (is_string($fp = $request->getAttribute('payee_fingerprint')) && BlockedBankAccount::query()->forWorkspace($workspace)->where('fingerprint', $fp)->exists()) {
            throw new WorkflowException('blocklisted');
        }
    }

    private function notifyVerification(PaymentRequest $request, Workspace $workspace, User $actor): void
    {
        $recipients = [
            ...DomainNotifier::users([is_int($s = $request->getAttribute('submitted_by')) ? $s : null]),
            ...DomainNotifier::membersWithAbility($workspace, 'payment_requests.verify'),
        ];
        $this->notifier->notify(
            'verification_required',
            $recipients,
            $workspace->id,
            $request,
            [DomainNotifier::DATABASE, DomainNotifier::TELEGRAM],
            ReassessmentService::verificationExtra($request),
            'warning',
            '/payment-requests/'.$request->getKey().'?tab=verification',
        );
    }
}
