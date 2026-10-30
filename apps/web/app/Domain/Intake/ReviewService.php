<?php

declare(strict_types=1);

namespace App\Domain\Intake;

use App\Audit\Auditor;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Intake\Data\ManualEntry;
use App\Domain\Intake\Enums\FileRole;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Enums\VendorMatchMethod;
use App\Domain\Intake\Exceptions\InvalidTransition;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\Models\PaymentRequestFile;
use App\Domain\Risk\Enums\AssessmentTrigger;
use App\Domain\Risk\RiskAssessor;
use App\Domain\Support\Normalize\EmailNormalizer;
use App\Domain\Support\Normalize\InvoiceNumber;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

final readonly class ReviewService
{
    private const array AUDITED = [
        'title', 'kind', 'amount_minor', 'currency', 'invoice_number', 'invoice_date', 'due_date', 'payee_last4', 'payee_scheme',
        'sender_email', 'vendor_id',
    ];

    public function __construct(
        private PipelineService $pipeline,
        private RiskAssessor $assessor,
        private IntakePublisher $publisher,
        private EvidenceLedger $ledger,
        private Auditor $auditor,
    ) {}

    public function update(PaymentRequest $subject, User $user, ManualEntry $entry): PaymentRequest
    {
        return DB::transaction(function () use ($subject, $user, $entry): PaymentRequest {
            $request = $this->lockForReview($subject);
            $before = $this->snapshot($request);
            $changed = [];

            if ($entry->has('title') && $entry->title !== null) {
                $request->setAttribute('title', mb_substr(trim($entry->title), 0, 200, 'UTF-8'));
            }

            if ($entry->has('kind') && $entry->kind !== null) {
                $request->setAttribute('kind', $entry->kind);
            }

            if ($entry->has('description')) {
                $request->setAttribute('description', $entry->description !== null && trim($entry->description) !== '' ? mb_substr(trim($entry->description), 0, 2000, 'UTF-8') : null);
            }

            if ($entry->has('amount') || $entry->has('currency')) {
                $request->forceFill([
                    'amount_minor' => $entry->has('amount') ? $entry->amountMinor : $request->getAttribute('amount_minor'),
                    'currency' => $entry->has('currency') && $entry->currency !== null ? strtoupper($entry->currency) : $request->getAttribute('currency'),
                ]);
            }

            if ($entry->has('invoice_number')) {
                $request->forceFill([
                    'invoice_number' => $entry->invoiceNumber !== null && trim($entry->invoiceNumber) !== '' ? mb_substr(trim($entry->invoiceNumber), 0, 64) : null,
                    'invoice_number_normalized' => InvoiceNumber::normalize($entry->invoiceNumber),
                ]);
            }

            if ($entry->has('invoice_date')) {
                $request->setAttribute('invoice_date', $entry->invoiceDate?->toDateString());
            }

            if ($entry->has('due_date')) {
                $request->setAttribute('due_date', $entry->dueDate?->toDateString());
            }

            if ($entry->has('payee')) {
                if ($entry->payeeScheme !== null && $entry->payeeAccountNumber !== null && trim($entry->payeeAccountNumber) !== '') {
                    PayeeWriter::apply(
                        $request,
                        $entry->payeeScheme,
                        $entry->payeeAccountNumber,
                        $entry->payeeRoutingCode,
                        $entry->payeeBic,
                        $entry->payeeAccountHolder,
                        $entry->payeeBankName,
                        $entry->payeeBankCountry,
                        $entry->payeeCorrespondentAccount,
                    );
                } else {
                    $request->forceFill(PayeeWriter::cleared());
                }
            }

            $senderChanged = false;

            if ($entry->has('sender_email')) {
                $email = EmailNormalizer::normalize($entry->senderEmail);
                $senderChanged = $email !== $request->getAttribute('sender_email');
                $request->forceFill(['sender_email' => $email, 'sender_domain' => EmailNormalizer::domain($email)]);
            }

            if ($entry->has('vendor_id')) {
                $this->assignVendor($request, $entry->vendorId);
            }

            foreach ($request->getDirty() as $key => $value) {
                $changed[] = self::fieldName($key);
            }

            $changed = array_values(array_unique(array_filter($changed)));

            if ($changed === []) {
                return $request;
            }

            $request->setAttribute('revision', (int) $request->getAttribute('revision') + 1);
            $pipeline = Pipeline::of($request)->addManualFields($changed);
            $workspace = Workspace::query()->findOrFail((int) $request->getAttribute('workspace_id'));

            if ($senderChanged && $request->getAttribute('sender_email') !== null) {
                $pipeline = $pipeline->resetForRevision(skipExtract: true);
                $pipeline->writeTo($request);
                StateMachine::assert(PaymentRequestStatus::Review, PaymentRequestStatus::Processing);
                $request->setAttribute('status', PaymentRequestStatus::Processing);
                $request->save();
                $this->recordUpdate($request, $user, $changed, $before);
                $this->publisher->received($request, $workspace, [], true);

                return $request;
            }

            $pipeline->writeTo($request);
            $request->save();
            $this->recordUpdate($request, $user, $changed, $before);
            $this->assessor->assess($request, AssessmentTrigger::ManualEdit, $user->id, refreshComparison: true);

            return $request;
        });
    }

    public function setVendor(PaymentRequest $subject, User $user, ?int $vendorId): PaymentRequest
    {
        return $this->update($subject, $user, new ManualEntry(provided: ['vendor_id'], vendorId: $vendorId));
    }

    public function reassess(PaymentRequest $subject, User $user, bool $reextract = false): PaymentRequest
    {
        return DB::transaction(function () use ($subject, $user, $reextract): PaymentRequest {
            $request = $this->lockForReview($subject);
            $workspace = Workspace::query()->findOrFail((int) $request->getAttribute('workspace_id'));
            $fileIds = PaymentRequestFile::query()->forWorkspace($workspace)
                ->where('payment_request_id', $request->getKey())
                ->where('role', FileRole::Original->value)
                ->whereNull('purged_at')
                ->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();

            if ($reextract && $fileIds !== []) {
                $request->setAttribute('revision', (int) $request->getAttribute('revision') + 1);
                Pipeline::of($request)->resetForRevision(skipExtract: false)->writeTo($request);
                StateMachine::assert(PaymentRequestStatus::Review, PaymentRequestStatus::Processing);
                $request->forceFill(['status' => PaymentRequestStatus::Processing, 'extraction_failed' => false])->save();
                $this->auditor->record('payment_request.reextract', $request, actor: $user, workspace: $workspace, subjectLabel: (string) $request->getAttribute('reference'));
                $this->publisher->received($request, $workspace, array_values($fileIds), false);

                return $request;
            }

            $this->assessor->assess($request, AssessmentTrigger::ManualEdit, $user->id, refreshComparison: true);
            $this->auditor->record('payment_request.reassessed', $request, actor: $user, workspace: $workspace, subjectLabel: (string) $request->getAttribute('reference'));

            return $request;
        });
    }

    private function lockForReview(PaymentRequest $subject): PaymentRequest
    {
        $request = $this->pipeline->lock((int) $subject->getAttribute('workspace_id'), (int) $subject->getKey());

        if ($request->getAttribute('status') !== PaymentRequestStatus::Review) {
            /** @var PaymentRequestStatus $status */
            $status = $request->getAttribute('status');
            throw InvalidTransition::between($status, PaymentRequestStatus::Review);
        }

        return $request;
    }

    private function assignVendor(PaymentRequest $request, ?int $vendorId): void
    {
        $request->forceFill([
            'vendor_id' => $vendorId,
            'vendor_match_method' => $vendorId !== null ? VendorMatchMethod::Manual : null,
            'vendor_match_confidence' => $vendorId !== null ? 100 : null,
        ]);
    }

    /**
     * @param  list<string>  $fields
     * @param  array<string, mixed>  $before
     */
    private function recordUpdate(PaymentRequest $request, User $user, array $fields, array $before): void
    {
        $this->ledger->append(
            workspaceId: (int) $request->getAttribute('workspace_id'),
            event: 'request.fields_updated',
            subjectType: 'payment_request',
            subjectId: (int) $request->getKey(),
            payload: ['fields' => $fields],
            actorUserId: $user->id,
            paymentRequestId: (int) $request->getKey(),
        );

        $this->auditor->record(
            'payment_request.updated',
            $request,
            changes: Auditor::diff($before, $this->snapshot($request), self::AUDITED),
            actor: $user,
            workspace: (int) $request->getAttribute('workspace_id'),
            subjectLabel: (string) $request->getAttribute('reference'),
        );
    }

    /** @return array<string, mixed> */
    private function snapshot(PaymentRequest $request): array
    {
        $snapshot = [];

        foreach (self::AUDITED as $key) {
            $value = $request->getAttribute($key);
            $snapshot[$key] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }

        return $snapshot;
    }

    private static function fieldName(string $column): ?string
    {
        return match (true) {
            str_starts_with($column, 'payee_') => 'payee',
            in_array($column, ['amount_minor', 'currency'], true) => 'amount',
            in_array($column, ['invoice_number', 'invoice_number_normalized'], true) => 'invoice_number',
            in_array($column, ['sender_email', 'sender_domain'], true) => 'sender',
            in_array($column, ['vendor_id', 'vendor_match_method', 'vendor_match_confidence'], true) => 'vendor',
            in_array($column, ['title', 'kind', 'description', 'invoice_date', 'due_date'], true) => $column,
            default => null,
        };
    }
}
