<?php

declare(strict_types=1);

namespace App\Domain\Verification;

use App\Audit\Auditor;
use App\Domain\Approvals\ApproverPool;
use App\Domain\Approvals\RouteBuilder;
use App\Domain\Evidence\Enums\EvidenceChannel;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Intake\Enums\DecisionReason;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Exceptions\InvalidTransition;
use App\Domain\Intake\Models\Extraction;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\Pipeline;
use App\Domain\Intake\PipelineService;
use App\Domain\Intake\StateMachine;
use App\Domain\Policy\Data\PolicyInput;
use App\Domain\Policy\PaymentSettings;
use App\Domain\Policy\RequestPolicy;
use App\Domain\Support\Fingerprinter;
use App\Domain\Vendors\BankAccountService;
use App\Domain\Vendors\Enums\BankAccountStatus;
use App\Domain\Vendors\Enums\ContactSource;
use App\Domain\Vendors\Enums\ContactStatus;
use App\Domain\Vendors\Enums\RetiredReason;
use App\Domain\Vendors\Enums\VendorKind;
use App\Domain\Vendors\Models\Vendor;
use App\Domain\Vendors\Models\VendorBankAccount;
use App\Domain\Vendors\Models\VendorContact;
use App\Domain\Verification\Data\VerificationInput;
use App\Domain\Verification\Enums\VerificationMethod;
use App\Domain\Verification\Enums\VerificationOutcome;
use App\Domain\Verification\Models\Verification;
use App\Domain\Workflow\DecisionService;
use App\Domain\Workflow\ReassessmentService;
use App\Domain\Workflow\WorkflowException;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

final readonly class VerificationService
{
    public const array CONTACT_METHODS = ['callback_known_number', 'video_call_known_contact', 'signed_letter_and_callback'];

    public function __construct(
        private PipelineService $pipeline,
        private RouteBuilder $routes,
        private DecisionService $decisions,
        private BankAccountService $accounts,
        private ReassessmentService $reassessment,
        private RequestPolicy $policy,
        private ApproverPool $pool,
        private EvidenceLedger $ledger,
        private Auditor $auditor,
        private ClockInterface $clock,
    ) {}

    public function record(PaymentRequest $subject, User $actor, VerificationInput $input): Verification
    {
        return DB::transaction(function () use ($subject, $actor, $input): Verification {
            $request = $this->pipeline->lock((int) $subject->getAttribute('workspace_id'), (int) $subject->getKey());
            /** @var PaymentRequestStatus $status */
            $status = $request->getAttribute('status');

            if ($status !== PaymentRequestStatus::AwaitingVerification) {
                throw InvalidTransition::between($status, PaymentRequestStatus::AwaitingVerification);
            }

            $workspace = Workspace::query()->findOrFail((int) $request->getAttribute('workspace_id'));
            $vendor = is_int($v = $request->getAttribute('vendor_id')) ? Vendor::query()->forWorkspace($workspace)->withTrashed()->find($v) : null;
            $codes = $this->policy->signalCodes($request);
            $bankChange = PolicyInput::bankChangeFrom($codes);
            $webOnly = array_intersect($codes, PolicyInput::webOnlySignals()) !== [];
            $solo = $this->pool->soloMode($workspace);
            $created = CarbonImmutable::instance($request->getAttribute('created_at'));

            if ($input->method === VerificationMethod::VendorPortal && $webOnly) {
                throw new WorkflowException('method_not_allowed', 'method');
            }

            $kind = $request->getAttribute('kind');

            if ($kind === PaymentRequestKind::PayrollChange) {
                $allowed = $input->method === VerificationMethod::InPerson
                    || ($input->method === VerificationMethod::CallbackKnownNumber && $vendor?->getAttribute('kind') === VendorKind::Employee);

                if (! $allowed) {
                    throw new WorkflowException('payroll_method', 'method');
                }
            }

            $contact = $this->contact($workspace, $vendor, $input, $bankChange);

            if ($contact !== null && $this->contactInMessage($request, $contact)) {
                throw new WorkflowException('callback_from_suspicious_message', 'contact_id');
            }

            $this->guardCommon($input, $created->subMinutes(10), $request->getAttribute('payee_last4') !== null);

            $isParty = in_array($actor->id, $this->routes->excluded($request), true);

            if ($isParty && ! $solo) {
                throw new WorkflowException('sod_verifier', 'workflow');
            }

            if ($input->outcome === VerificationOutcome::Denied && ! in_array($input->decisionReason, [DecisionReason::FraudConfirmed, DecisionReason::DetailsMismatch], true)) {
                throw new WorkflowException('denied_reason_required', 'decision_reason');
            }

            $this->guardReplaces($workspace, $vendor, $input->replacesAccountId);
            $verification = $this->store($workspace, $request, $vendor, null, $contact, $actor, $input, $isParty);

            if ($input->outcome === VerificationOutcome::Confirmed) {
                StateMachine::assert($status, PaymentRequestStatus::AwaitingApproval);
                Pipeline::of($request)->put('verified_fingerprint', is_string($fp = $request->getAttribute('payee_fingerprint')) ? $fp : null)->writeTo($request);
                $request->forceFill([
                    'status' => PaymentRequestStatus::AwaitingApproval,
                    'verification_satisfied_at' => CarbonImmutable::instance($this->clock->now()),
                    'satisfying_verification_id' => $verification->getKey(),
                ])->save();
                $this->routes->build($request, $actor);
            } elseif ($input->outcome === VerificationOutcome::Denied) {
                $this->decisions->reject($request, $input->decisionReason, null, $actor, EvidenceChannel::Web, verificationDenied: true);
            }

            return $verification;
        });
    }

    public function verifyAccount(VendorBankAccount $subject, User $actor, VerificationInput $input): Verification
    {
        return DB::transaction(function () use ($subject, $actor, $input): Verification {
            $workspace = Workspace::query()->findOrFail((int) $subject->getAttribute('workspace_id'));
            $account = VendorBankAccount::query()->forWorkspace($workspace)->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();

            if ($account->getAttribute('status') !== BankAccountStatus::PendingVerification) {
                throw new WorkflowException('invalid_account_transition', 'account');
            }

            $vendor = Vendor::query()->forWorkspace($workspace)->withTrashed()->findOrFail((int) $account->getAttribute('vendor_id'));
            $solo = $this->pool->soloMode($workspace);
            $self = (int) $account->getAttribute('created_by') === $actor->id;

            if ($self && ! $solo) {
                throw new WorkflowException('four_eyes_required', 'workflow');
            }

            if ($input->method === VerificationMethod::VendorPortal) {
                throw new WorkflowException('method_not_allowed', 'method');
            }

            $contact = $this->contact($workspace, $vendor, $input, false);
            $this->guardCommon($input, CarbonImmutable::instance($account->getAttribute('created_at'))->subMinutes(10), true);
            $this->guardReplaces($workspace, $vendor, $input->replacesAccountId);
            $verification = $this->store($workspace, null, $vendor, $account, $contact, $actor, $input, $self);

            if ($input->outcome === VerificationOutcome::Confirmed) {
                $this->accounts->markVerified($account, $verification, $actor, $self, EvidenceChannel::Web);
                $replaces = $input->replacesAccountId;

                if ($replaces !== null) {
                    $old = VendorBankAccount::query()->forWorkspace($workspace)->whereKey($replaces)->lockForUpdate()->first();

                    if ($old !== null && $old->getAttribute('status') === BankAccountStatus::Verified) {
                        $this->accounts->retireLocked($old, RetiredReason::Replaced, $actor, EvidenceChannel::Web);
                    }
                }
            } elseif ($input->outcome === VerificationOutcome::Denied) {
                $account->forceFill(['status' => BankAccountStatus::Rejected])->save();
                $this->auditor->record('vendor.bank_account_rejected', $account, ['status' => ['pending_verification', 'rejected']], actor: $actor, workspace: $workspace, subjectLabel: '•••• '.$account->getAttribute('last4'));
            }

            $this->reassessment->forVendorAfterCommit($workspace->id, (int) $vendor->getKey());

            return $verification;
        });
    }

    private function contact(Workspace $workspace, ?Vendor $vendor, VerificationInput $input, bool $bankChange): ?VendorContact
    {
        $needs = in_array($input->method->value, self::CONTACT_METHODS, true);

        if ($input->contactId === null) {
            if ($needs) {
                throw new WorkflowException($vendor === null ? 'vendor_required_for_callback' : 'contact_required', 'contact_id');
            }

            return null;
        }

        if ($vendor === null) {
            throw new WorkflowException('vendor_required_for_callback', 'contact_id');
        }

        $contact = VendorContact::query()->forWorkspace($workspace)
            ->where('vendor_id', $vendor->getKey())
            ->whereKey($input->contactId)
            ->first();

        if ($contact === null || ! (bool) $contact->getAttribute('is_callback')) {
            throw new WorkflowException('contact_not_callback', 'contact_id');
        }

        if ($contact->getAttribute('status') !== ContactStatus::Verified) {
            throw new WorkflowException('contact_not_verified', 'contact_id');
        }

        if ($bankChange && $this->recentlyChanged($workspace, $contact)) {
            throw new WorkflowException('callback_contact_recently_changed', 'contact_id', ['days' => (int) PaymentSettings::for($workspace)->getAttribute('callback_cooldown_days')]);
        }

        return $contact;
    }

    private function recentlyChanged(Workspace $workspace, VendorContact $contact): bool
    {
        $days = (int) PaymentSettings::for($workspace)->getAttribute('callback_cooldown_days');
        $threshold = CarbonImmutable::instance($this->clock->now())->subDays($days);

        foreach (['created_at', 'verified_at', 'phone_changed_at'] as $column) {
            $value = $contact->getAttribute($column);

            if ($value instanceof \DateTimeInterface && CarbonImmutable::instance($value)->greaterThanOrEqualTo($threshold)) {
                return true;
            }
        }

        return false;
    }

    private function contactInMessage(PaymentRequest $request, VendorContact $contact): bool
    {
        $hash = $contact->getAttribute('phone_hash');

        if (! is_string($hash)) {
            return false;
        }

        $workspaceId = (int) $request->getAttribute('workspace_id');
        $fingerprinter = Fingerprinter::fromConfig();

        foreach (Extraction::query()->forWorkspace($workspaceId)->where('payment_request_id', $request->getKey())->get() as $extraction) {
            $fields = $extraction->getAttribute('fields');

            foreach (is_array($fields) && is_array($fields['phones_e164'] ?? null) ? $fields['phones_e164'] : [] as $phone) {
                if (is_string($phone) && hash_equals($hash, $fingerprinter->phone($workspaceId, $phone))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function guardCommon(VerificationInput $input, CarbonImmutable $earliest, bool $payeePresent): void
    {
        $now = CarbonImmutable::instance($this->clock->now());

        if ($input->occurredAt->greaterThan($now->addMinute()) || $input->occurredAt->lessThan($earliest)) {
            throw new WorkflowException('occurred_at_out_of_range', 'occurred_at');
        }

        $length = mb_strlen(trim($input->spokenToName), 'UTF-8');

        if ($length < 2 || $length > 160) {
            throw new WorkflowException('spoken_to_name', 'spoken_to_name');
        }

        if ($input->outcome === VerificationOutcome::Confirmed && $payeePresent && ! ($input->checklist['account_last4'] ?? false)) {
            throw new WorkflowException('checklist_last4_required', 'checklist.account_last4');
        }
    }

    private function guardReplaces(Workspace $workspace, ?Vendor $vendor, ?int $replaces): void
    {
        if ($replaces === null) {
            return;
        }

        $valid = $vendor !== null && VendorBankAccount::query()->forWorkspace($workspace)
            ->where('vendor_id', $vendor->getKey())
            ->whereKey($replaces)
            ->where('status', BankAccountStatus::Verified->value)
            ->exists();

        if (! $valid) {
            throw new WorkflowException('replaces_account_invalid', 'replaces_account_id');
        }
    }

    private function store(Workspace $workspace, ?PaymentRequest $request, ?Vendor $vendor, ?VendorBankAccount $account, ?VendorContact $contact, User $actor, VerificationInput $input, bool $sodWaived): Verification
    {
        $phone = $contact?->getAttribute('phone_e164');
        $source = $contact?->getAttribute('source');
        $verification = new Verification;
        $verification->forceFill([
            'workspace_id' => $workspace->id,
            'payment_request_id' => $request?->getKey(),
            'vendor_id' => $vendor?->getKey(),
            'vendor_bank_account_id' => $account?->getKey(),
            'method' => $input->method,
            'contact_id' => $contact?->getKey(),
            'phone_dialed' => is_string($phone) ? $phone : null,
            'phone_dialed_hash' => $contact?->getAttribute('phone_hash'),
            'phone_dialed_last4' => $contact?->getAttribute('phone_last4'),
            'phone_source' => $source instanceof ContactSource ? $source : null,
            'spoken_to_name' => mb_substr(trim($input->spokenToName), 0, 160, 'UTF-8'),
            'outcome' => $input->outcome,
            'checklist' => $input->normalizedChecklist(),
            'replaces_account_id' => $input->replacesAccountId,
            'occurred_at' => $input->occurredAt->utc(),
            'notes' => $input->notes !== null && trim($input->notes) !== '' ? mb_substr(trim($input->notes), 0, 2000, 'UTF-8') : null,
            'performed_by' => $actor->id,
            'sod_waived' => $sodWaived,
            'created_at' => CarbonImmutable::instance($this->clock->now()),
        ])->save();

        $accountLast4 = $account !== null ? $account->getAttribute('last4') : $request?->getAttribute('payee_last4');
        $entry = $this->ledger->append(
            workspaceId: $workspace->id,
            event: 'verification.recorded',
            subjectType: 'verification',
            subjectId: (int) $verification->getKey(),
            payload: [
                'method' => $input->method->value,
                'outcome' => $input->outcome->value,
                'contact_id' => $contact !== null ? (int) $contact->getKey() : null,
                'phone_last4' => is_string($l = $contact?->getAttribute('phone_last4')) ? $l : null,
                'phone_source' => $source instanceof ContactSource ? $source->value : null,
                'account_last4' => is_string($accountLast4) ? $accountLast4 : null,
                'checklist' => $input->normalizedChecklist(),
                'sod_waived' => $sodWaived,
            ],
            actorUserId: $actor->id,
            paymentRequestId: $request !== null ? (int) $request->getKey() : null,
        );

        DB::table('verifications')->where('id', $verification->getKey())->update(['evidence_entry_id' => $entry->getKey()]);
        $verification->setAttribute('evidence_entry_id', (int) $entry->getKey());
        $verification->syncOriginalAttribute('evidence_entry_id');

        $this->auditor->record(
            'verification.recorded',
            $verification,
            metadata: ['method' => $input->method->value, 'outcome' => $input->outcome->value, 'sod_waived' => $sodWaived],
            actor: $actor,
            workspace: $workspace,
            subjectLabel: $request !== null ? (string) $request->getAttribute('reference') : (string) $vendor?->getAttribute('name'),
        );

        return $verification;
    }
}
