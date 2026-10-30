<?php

declare(strict_types=1);

namespace App\Domain\Intake;

use App\Domain\Evidence\Enums\EvidenceChannel;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Intake\Data\ExtractionResult;
use App\Domain\Intake\Enums\ExtractionStatus;
use App\Domain\Intake\Enums\FileRole;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Enums\PaymentRequestSource;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Enums\PipelineStage;
use App\Domain\Intake\Enums\PipelineState;
use App\Domain\Intake\Events\IntakeAssessed;
use App\Domain\Intake\Exceptions\InvalidTransition;
use App\Domain\Intake\Models\EmailMessage;
use App\Domain\Intake\Models\Extraction;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\Models\PaymentRequestFile;
use App\Domain\Risk\Enums\AssessmentTrigger;
use App\Domain\Risk\RiskAssessor;
use App\Domain\Support\Normalize\DomainNormalizer;
use App\Domain\Support\Normalize\EmailNormalizer;
use App\Domain\Support\Normalize\InvoiceNumber;
use App\Domain\Vendors\Bank\BankComparisonService;
use App\Domain\Vendors\Matching\VendorMatch;
use App\Domain\Vendors\Matching\VendorMatcher;
use App\Domain\Vendors\Models\Vendor;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

final readonly class PipelineService
{
    public function __construct(
        private RiskAssessor $assessor,
        private BankComparisonService $bank,
        private VendorMatcher $matcher,
        private EvidenceLedger $ledger,
        private IntakePublisher $publisher,
        private ClockInterface $clock,
    ) {}

    public function markStage(int $workspaceId, int $requestId, int $revision, PipelineStage $stage, PipelineState $state, ?string $error = null): PaymentRequest
    {
        return DB::transaction(function () use ($workspaceId, $requestId, $revision, $stage, $state, $error): PaymentRequest {
            $request = $this->lock($workspaceId, $requestId, $revision);
            $now = CarbonImmutable::instance($this->clock->now());
            $pipeline = Pipeline::of($request);
            /** @var PaymentRequestStatus $status */
            $status = $request->getAttribute('status');

            if ($stage === PipelineStage::Extract && $state === PipelineState::Running && $status === PaymentRequestStatus::Received) {
                StateMachine::assert($status, PaymentRequestStatus::Processing);
                $request->setAttribute('status', PaymentRequestStatus::Processing);
            }

            $pipeline->set($stage, $state, $now, $error);

            if ($state === PipelineState::Failed) {
                if ($stage === PipelineStage::Extract) {
                    $request->setAttribute('extraction_failed', true);
                } else {
                    $pipeline->markIncomplete();
                }
            }

            $pipeline->writeTo($request);
            $request->save();

            if ($state === PipelineState::Failed) {
                $this->finalize($request);
            }

            return $request;
        });
    }

    /**
     * @return array{proceed: bool, domains: list<string>}
     */
    public function ingest(PaymentRequest $locked, ExtractionResult $result): array
    {
        return DB::transaction(function () use ($locked, $result): array {
            $request = $this->lock((int) $locked->getAttribute('workspace_id'), (int) $locked->getKey(), $result->revision);
            $workspaceId = (int) $request->getAttribute('workspace_id');
            $existing = Extraction::query()->forWorkspace($workspaceId)
                ->where('payment_request_id', $request->getKey())
                ->where('revision', $result->revision)
                ->exists();

            if ($existing) {
                return $this->ingestResponse($request, $result);
            }

            /** @var PaymentRequestStatus $status */
            $status = $request->getAttribute('status');

            if ($status === PaymentRequestStatus::Received) {
                $request->setAttribute('status', PaymentRequestStatus::Processing);
                $status = PaymentRequestStatus::Processing;
            }

            $warnings = $result->warnings;
            $pipeline = Pipeline::of($request);
            $manual = $pipeline->manualFields();

            if ($status === PaymentRequestStatus::Processing && $result->status !== ExtractionStatus::Failed) {
                $match = $this->applyFields($request, $result, $manual);

                if ($match?->warning !== null) {
                    $warnings[] = $match->warning;
                }

                $pipeline->setAiFields(array_values(array_diff($result->fields->llmAssistedFields(), $manual)));
            }

            $extraction = new Extraction;
            $extraction->forceFill([
                'workspace_id' => $workspaceId,
                'payment_request_id' => $request->getKey(),
                'revision' => $result->revision,
                'status' => $result->status,
                'extractor_version' => $result->extractorVersion,
                'ocr_used' => $result->ocrUsed,
                'pages' => $result->pages,
                'text' => $result->text === '' ? null : $result->text,
                'fields' => $result->rawFields,
                'warnings' => array_values(array_unique($warnings)),
                'error_code' => $result->errorCode,
                'duration_ms' => $result->durationMs,
                'llm_assisted' => $result->llmAssisted,
            ])->save();

            foreach ($result->files as $file) {
                PaymentRequestFile::query()->forWorkspace($workspaceId)
                    ->where('payment_request_id', $request->getKey())
                    ->whereKey($file['file_id'])
                    ->update(['pages' => $file['pages']]);
            }

            if ($result->fields->sender !== null) {
                $this->storeEmail($request, $result);
            }

            $failed = $result->status === ExtractionStatus::Failed;
            $now = CarbonImmutable::instance($this->clock->now());
            $pipeline->set(PipelineStage::Extract, $failed ? PipelineState::Failed : PipelineState::Done, $now, $failed ? ($result->errorCode ?? 'no_text') : null);
            $pipeline->writeTo($request);
            $request->setAttribute('extraction_failed', $failed);
            $request->save();

            $this->ledger->append(
                workspaceId: $workspaceId,
                event: 'request.extracted',
                subjectType: 'payment_request',
                subjectId: (int) $request->getKey(),
                payload: ['status' => $result->status->value, 'ocr_used' => $result->ocrUsed, 'extractor_version' => $result->extractorVersion],
                channel: EvidenceChannel::Worker,
                paymentRequestId: (int) $request->getKey(),
            );

            if ($failed) {
                $this->finalize($request);
            }

            return $this->ingestResponse($request, $result);
        });
    }

    public function compareBank(int $workspaceId, int $requestId, int $revision): string
    {
        return DB::transaction(function () use ($workspaceId, $requestId, $revision): string {
            $request = $this->lock($workspaceId, $requestId, $revision);
            $comparison = $this->bank->compareAndStore($request);
            $pipeline = Pipeline::of($request);
            $pipeline->set(PipelineStage::Bank, PipelineState::Done, CarbonImmutable::instance($this->clock->now()));
            $pipeline->writeTo($request);
            $request->save();

            $outcome = $comparison->getAttribute('outcome');

            return $outcome instanceof \BackedEnum ? (string) $outcome->value : (string) $outcome;
        });
    }

    /**
     * @return array{score: int|null, level: string|null, status: string}
     */
    public function assess(int $workspaceId, int $requestId, int $revision): array
    {
        return DB::transaction(function () use ($workspaceId, $requestId, $revision): array {
            $request = $this->lock($workspaceId, $requestId, $revision);
            /** @var PaymentRequestStatus $status */
            $status = $request->getAttribute('status');

            if ($status->isPipelineRunning()) {
                $pipeline = Pipeline::of($request);

                if ($pipeline->state(PipelineStage::Domain) === PipelineState::Pending || $pipeline->state(PipelineStage::Domain) === PipelineState::Running) {
                    $pipeline->set(PipelineStage::Domain, PipelineState::Done, CarbonImmutable::instance($this->clock->now()));
                    $pipeline->writeTo($request);
                }

                $this->finalize($request);
            }

            $level = $request->getAttribute('risk_level');
            $score = $request->getAttribute('risk_score');
            $current = $request->getAttribute('status');

            return [
                'score' => is_int($score) ? $score : null,
                'level' => $level instanceof \BackedEnum ? (string) $level->value : null,
                'status' => $current instanceof \BackedEnum ? (string) $current->value : (string) $current,
            ];
        });
    }

    public function finalize(PaymentRequest $request): void
    {
        /** @var PaymentRequestStatus $status */
        $status = $request->getAttribute('status');

        if (! $status->isPipelineRunning()) {
            return;
        }

        $now = CarbonImmutable::instance($this->clock->now());
        $pipeline = Pipeline::of($request);

        foreach ([PipelineStage::Bank, PipelineStage::Score] as $stage) {
            if (in_array($pipeline->state($stage), [PipelineState::Pending, PipelineState::Running], true)) {
                $pipeline->set($stage, PipelineState::Done, $now);
            }
        }

        if ($pipeline->state(PipelineStage::Domain) === PipelineState::Pending && $request->getAttribute('sender_email') === null) {
            $pipeline->set(PipelineStage::Domain, PipelineState::Skipped, $now);
        }

        $pipeline->writeTo($request);
        StateMachine::assert($status, PaymentRequestStatus::Review);
        $request->setAttribute('status', PaymentRequestStatus::Review);
        $request->save();
        $this->assessor->assess($request, AssessmentTrigger::Pipeline, null, EvidenceChannel::Worker, refreshComparison: true);
        IntakeAssessed::dispatch($request);
    }

    /**
     * @return array{republished: int, completed: int}
     */
    public function sweep(): array
    {
        $now = CarbonImmutable::instance($this->clock->now());
        $cutoff = $now->subMinutes((int) config('scampause.intake.pipeline_timeout_minutes', 10));
        $maxRepublish = (int) config('scampause.intake.pipeline_max_republish', 2);
        $republished = 0;
        $completed = 0;
        $ids = PaymentRequest::query()->withoutGlobalScopes()
            ->whereIn('status', [PaymentRequestStatus::Received->value, PaymentRequestStatus::Processing->value])
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->limit(200)
            ->get(['id', 'workspace_id']);

        foreach ($ids as $row) {
            DB::transaction(function () use ($row, $maxRepublish, &$republished, &$completed): void {
                $request = PaymentRequest::query()->forWorkspace((int) $row->getAttribute('workspace_id'))->whereKey($row->getKey())->lockForUpdate()->first();

                if ($request === null || ! $request->getAttribute('status') instanceof PaymentRequestStatus || ! $request->getAttribute('status')->isPipelineRunning()) {
                    return;
                }

                $pipeline = Pipeline::of($request);

                if ($pipeline->republished() < $maxRepublish) {
                    $pipeline->bumpRepublished()->writeTo($request);
                    $request->setAttribute('updated_at', CarbonImmutable::instance($this->clock->now()));
                    $request->save();
                    $workspace = Workspace::query()->findOrFail((int) $request->getAttribute('workspace_id'));
                    $fileIds = PaymentRequestFile::query()->forWorkspace($workspace)
                        ->where('payment_request_id', $request->getKey())
                        ->where('role', FileRole::Original->value)
                        ->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
                    $this->publisher->received($request, $workspace, array_values($fileIds), in_array($pipeline->state(PipelineStage::Extract), [PipelineState::Skipped, PipelineState::Done], true));
                    $republished++;

                    return;
                }

                $pipeline->markIncomplete()->writeTo($request);
                $request->save();
                $this->finalize($request);
                $completed++;
            });
        }

        return ['republished' => $republished, 'completed' => $completed];
    }

    public function lock(int $workspaceId, int $requestId, ?int $revision = null): PaymentRequest
    {
        $request = PaymentRequest::query()->forWorkspace($workspaceId)->whereKey($requestId)->lockForUpdate()->first();

        if ($request === null) {
            throw new ModelNotFoundException;
        }

        if ($revision !== null && (int) $request->getAttribute('revision') !== $revision) {
            throw InvalidTransition::staleRevision();
        }

        return $request;
    }

    /**
     * @return array{proceed: bool, domains: list<string>}
     */
    private function ingestResponse(PaymentRequest $request, ExtractionResult $result): array
    {
        $proceed = $request->getAttribute('status') === PaymentRequestStatus::Processing && $result->status !== ExtractionStatus::Failed;

        return ['proceed' => $proceed, 'domains' => $proceed ? $this->domainsFor($request, $result) : []];
    }

    /** @return list<string> */
    private function domainsFor(PaymentRequest $request, ExtractionResult $result): array
    {
        $domains = [];
        $candidates = [
            $request->getAttribute('sender_domain'),
            $request->getAttribute('reply_to_domain'),
            $result->fields->sender?->returnPath !== null ? EmailNormalizer::domain($result->fields->sender->returnPath) ?? DomainNormalizer::registrable($result->fields->sender->returnPath) : null,
        ];

        foreach ($result->fields->emails as $email) {
            $candidates[] = EmailNormalizer::domain($email);
        }

        foreach ($result->fields->urls as $url) {
            $candidates[] = DomainNormalizer::fromUrl($url);
        }

        foreach ($result->fields->domains as $domain) {
            $candidates[] = DomainNormalizer::registrable($domain);
        }

        $free = (array) config('scampause.free_mail_domains', []);

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && ! in_array($candidate, $free, true) && ! in_array($candidate, $domains, true)) {
                $domains[] = $candidate;
            }
        }

        return array_slice($domains, 0, 20);
    }

    /**
     * @param  list<string>  $manual
     */
    private function applyFields(PaymentRequest $request, ExtractionResult $result, array $manual): ?VendorMatch
    {
        $fields = $result->fields;
        $workspaceId = (int) $request->getAttribute('workspace_id');
        $sender = $fields->sender;

        if ($sender !== null && ! in_array('sender', $manual, true)) {
            $from = EmailNormalizer::normalize($sender->fromEmail);
            $reply = EmailNormalizer::normalize($sender->replyTo);
            $request->forceFill([
                'sender_email' => $from ?? $request->getAttribute('sender_email'),
                'sender_domain' => EmailNormalizer::domain($from) ?? $request->getAttribute('sender_domain'),
                'reply_to_email' => $reply,
                'reply_to_domain' => EmailNormalizer::domain($reply),
            ]);
        }

        if ($sender?->subject !== null && $request->getAttribute('source') === PaymentRequestSource::Eml && ! in_array('title', $manual, true)) {
            $request->setAttribute('title', mb_substr($sender->subject, 0, 200, 'UTF-8'));
        }

        if (! in_array('amount', $manual, true) && $fields->amountMinor !== null && $request->getAttribute('amount_minor') === null) {
            $currency = $fields->currency?->string();
            $request->forceFill([
                'amount_minor' => (int) $fields->amountMinor->value,
                'currency' => $currency !== null ? strtoupper($currency) : $request->getAttribute('base_currency'),
            ]);
        }

        if (! in_array('invoice_number', $manual, true) && $fields->invoiceNumber !== null && $request->getAttribute('invoice_number') === null) {
            $request->forceFill([
                'invoice_number' => mb_substr($fields->invoiceNumber->string(), 0, 64, 'UTF-8'),
                'invoice_number_normalized' => InvoiceNumber::normalize($fields->invoiceNumber->string()),
            ]);
        }

        if (! in_array('invoice_date', $manual, true) && $fields->invoiceDate !== null && $request->getAttribute('invoice_date') === null) {
            $request->setAttribute('invoice_date', $fields->invoiceDate->string());
        }

        if (! in_array('due_date', $manual, true) && $fields->dueDate !== null && $request->getAttribute('due_date') === null) {
            $request->setAttribute('due_date', $fields->dueDate->string());
        }

        $payee = $fields->payee ?? $fields->qrPayee;

        if (! in_array('payee', $manual, true) && $payee !== null && $request->getAttribute('payee_account_number') === null) {
            PayeeWriter::apply(
                $request,
                $payee->scheme,
                $payee->accountNumber->string(),
                $payee->routingCode?->string(),
                $payee->bic?->string(),
                $payee->accountHolder?->string(),
                $payee->bankName?->string(),
                $payee->bankCountry?->string(),
                $payee->correspondentAccount?->string(),
            );
        }

        if (! in_array('kind', $manual, true) && $request->getAttribute('kind') === PaymentRequestKind::Invoice) {
            $request->setAttribute('kind', $fields->kindSuggestion === PaymentRequestKind::Other && $fields->amountMinor !== null ? PaymentRequestKind::Invoice : $fields->kindSuggestion);
        }

        if (in_array('vendor', $manual, true) || $request->getAttribute('vendor_id') !== null) {
            return null;
        }

        $fingerprint = $request->getAttribute('payee_fingerprint');
        $match = $this->matcher->match(
            $workspaceId,
            $fields->vendorTaxId?->string(),
            is_string($domain = $request->getAttribute('sender_domain')) ? $domain : null,
            $fields->vendorName?->string() ?? $sender?->fromName,
            is_string($fingerprint) ? $fingerprint : null,
            array_values(array_filter((array) config('scampause.free_mail_domains', []), 'is_string')),
        );

        if ($match->vendorId !== null && Vendor::query()->forWorkspace($workspaceId)->whereKey($match->vendorId)->exists()) {
            $request->forceFill([
                'vendor_id' => $match->vendorId,
                'vendor_match_method' => $match->method,
                'vendor_match_confidence' => $match->confidence,
            ]);
        }

        return $match;
    }

    private function storeEmail(PaymentRequest $request, ExtractionResult $result): void
    {
        $sender = $result->fields->sender;

        if ($sender === null) {
            return;
        }

        $workspaceId = (int) $request->getAttribute('workspace_id');
        $fileId = PaymentRequestFile::query()->forWorkspace($workspaceId)
            ->where('payment_request_id', $request->getKey())
            ->where('role', FileRole::Original->value)
            ->where('mime', 'message/rfc822')
            ->orderBy('id')
            ->value('id');
        $from = EmailNormalizer::normalize($sender->fromEmail);
        $reply = EmailNormalizer::normalize($sender->replyTo);
        $returnPath = $sender->returnPath !== null
            ? (EmailNormalizer::domain($sender->returnPath) ?? DomainNormalizer::registrable($sender->returnPath))
            : null;
        $sentAt = null;

        if ($sender->sentAt !== null) {
            try {
                $sentAt = CarbonImmutable::parse($sender->sentAt)->utc();
            } catch (\Throwable) {
                $sentAt = null;
            }
        }

        $message = new EmailMessage;
        $message->forceFill([
            'workspace_id' => $workspaceId,
            'payment_request_id' => $request->getKey(),
            'file_id' => is_numeric($fileId) ? (int) $fileId : null,
            'message_id' => $sender->messageId,
            'from_name' => $sender->fromName,
            'from_email' => $from,
            'from_domain' => EmailNormalizer::domain($from),
            'reply_to_email' => $reply,
            'reply_to_domain' => EmailNormalizer::domain($reply),
            'return_path_domain' => $returnPath,
            'to_emails' => $sender->to,
            'subject' => $sender->subject,
            'sent_at' => $sentAt,
            'auth_spf' => $sender->spf,
            'auth_dkim' => $sender->dkim,
            'auth_dmarc' => $sender->dmarc,
            'received_hops' => $sender->receivedHops,
            'attachments_count' => count(array_filter($result->files, static fn (array $file): bool => $file['file_id'] !== $fileId)),
        ])->save();
    }
}
