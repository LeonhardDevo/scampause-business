<?php

declare(strict_types=1);

namespace App\Domain\Intake;

use App\Audit\Auditor;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Intake\Data\ManualEntry;
use App\Domain\Intake\Enums\FileRole;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Enums\PaymentRequestSource;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Enums\VendorMatchMethod;
use App\Domain\Intake\Events\IntakeAssessed;
use App\Domain\Intake\Files\FileVault;
use App\Domain\Intake\Files\UploadInspector;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\Models\PaymentRequestFile;
use App\Domain\Risk\Enums\AssessmentTrigger;
use App\Domain\Risk\RiskAssessor;
use App\Domain\Support\Normalize\EmailNormalizer;
use App\Domain\Support\Normalize\InvoiceNumber;
use App\Domain\Vendors\Matching\VendorMatcher;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Psr\Clock\ClockInterface;
use Throwable;

final readonly class IntakeService
{
    public function __construct(
        private FileVault $vault,
        private ReferenceSequence $sequence,
        private IntakePublisher $publisher,
        private EvidenceLedger $ledger,
        private Auditor $auditor,
        private RiskAssessor $assessor,
        private VendorMatcher $matcher,
        private ClockInterface $clock,
    ) {}

    /**
     * @param  list<array{bytes: string, name: string, mime: string}>  $files
     */
    public function createFromUpload(
        Workspace $workspace,
        User $user,
        array $files,
        ?PaymentRequestKind $kind = null,
        ?string $description = null,
        ?int $vendorId = null,
    ): PaymentRequest {
        $stored = [];

        try {
            return DB::transaction(function () use ($workspace, $user, $files, $kind, $description, $vendorId, &$stored): PaymentRequest {
                $isEml = array_filter($files, static fn (array $file): bool => $file['mime'] === UploadInspector::EML) !== [];
                $request = new PaymentRequest;
                $manual = array_values(array_filter([$kind !== null ? 'kind' : null, $vendorId !== null ? 'vendor' : null]));
                $request->forceFill([
                    'workspace_id' => $workspace->id,
                    'reference' => $this->sequence->nextReference($workspace->id),
                    'kind' => $kind ?? PaymentRequestKind::Invoice,
                    'source' => $isEml ? PaymentRequestSource::Eml : PaymentRequestSource::Upload,
                    'status' => PaymentRequestStatus::Received,
                    'title' => mb_substr(FileVault::sanitizeName($files[0]['name'] ?? 'document'), 0, 200, 'UTF-8'),
                    'description' => self::clean($description, 2000),
                    'vendor_id' => $vendorId,
                    'vendor_match_method' => $vendorId !== null ? VendorMatchMethod::Manual : null,
                    'vendor_match_confidence' => $vendorId !== null ? 100 : null,
                    'base_currency' => strtoupper($workspace->currency),
                    'requested_by' => $user->id,
                    'revision' => 1,
                    'pipeline' => Pipeline::initial()->addManualFields($manual)->toArray(),
                ])->save();

                $fileIds = [];
                $seen = [];

                foreach ($files as $file) {
                    $sha = hash('sha256', $file['bytes']);

                    if (isset($seen[$sha])) {
                        continue;
                    }

                    $seen[$sha] = true;
                    $meta = $this->vault->store($workspace->id, (int) $request->getKey(), $file['bytes'], UploadInspector::extensionFor($file['mime']));
                    $stored[] = $meta;
                    $model = new PaymentRequestFile;
                    $model->forceFill([
                        'workspace_id' => $workspace->id,
                        'payment_request_id' => $request->getKey(),
                        'role' => FileRole::Original,
                        'disk' => $meta['disk'],
                        'path' => $meta['path'],
                        'original_name' => FileVault::sanitizeName($file['name']),
                        'mime' => $file['mime'],
                        'size_bytes' => $meta['size_bytes'],
                        'sha256' => $meta['sha256'],
                        'uploaded_by' => $user->id,
                    ])->save();
                    $fileIds[] = (int) $model->getKey();
                }

                $this->created($request, $user, count($fileIds));
                $this->publisher->received($request, $workspace, $fileIds, false);

                return $request;
            });
        } catch (Throwable $exception) {
            foreach ($stored as $meta) {
                Storage::disk($meta['disk'])->delete($meta['path']);
            }

            throw $exception;
        }
    }

    public function createManual(Workspace $workspace, User $user, ManualEntry $entry): PaymentRequest
    {
        return DB::transaction(function () use ($workspace, $user, $entry): PaymentRequest {
            $now = CarbonImmutable::instance($this->clock->now());
            $senderEmail = EmailNormalizer::normalize($entry->senderEmail);
            $request = new PaymentRequest;
            $request->forceFill([
                'workspace_id' => $workspace->id,
                'reference' => $this->sequence->nextReference($workspace->id),
                'kind' => $entry->kind ?? PaymentRequestKind::Invoice,
                'source' => PaymentRequestSource::Manual,
                'status' => $senderEmail !== null ? PaymentRequestStatus::Processing : PaymentRequestStatus::Review,
                'title' => mb_substr(trim((string) $entry->title), 0, 200, 'UTF-8'),
                'description' => self::clean($entry->description, 2000),
                'amount_minor' => $entry->amountMinor,
                'currency' => $entry->currency !== null ? strtoupper($entry->currency) : null,
                'invoice_number' => self::clean($entry->invoiceNumber, 64),
                'invoice_number_normalized' => InvoiceNumber::normalize($entry->invoiceNumber),
                'invoice_date' => $entry->invoiceDate?->toDateString(),
                'due_date' => $entry->dueDate?->toDateString(),
                'sender_email' => $senderEmail,
                'sender_domain' => EmailNormalizer::domain($senderEmail),
                'base_currency' => strtoupper($workspace->currency),
                'requested_by' => $user->id,
                'revision' => 1,
            ]);

            if ($entry->payeeScheme !== null && $entry->payeeAccountNumber !== null && trim($entry->payeeAccountNumber) !== '') {
                $request->forceFill(PayeeWriter::attributes(
                    $workspace->id,
                    $entry->payeeScheme,
                    $entry->payeeAccountNumber,
                    $entry->payeeRoutingCode,
                    $entry->payeeBic,
                    $entry->payeeAccountHolder,
                    $entry->payeeBankName,
                    $entry->payeeBankCountry,
                    $entry->payeeCorrespondentAccount,
                ));
            }

            $this->assignVendor($request, $workspace, $entry->vendorId, $senderEmail);
            $pipeline = $senderEmail !== null ? Pipeline::initial(skipExtract: true) : Pipeline::completed($now);
            $pipeline->addManualFields(self::manualFieldNames($entry));
            $pipeline->writeTo($request);
            $request->save();

            $this->created($request, $user, 0);

            if ($senderEmail !== null) {
                $this->publisher->received($request, $workspace, [], true);
            } else {
                $this->assessor->assess($request, AssessmentTrigger::Pipeline, $user->id);
                IntakeAssessed::dispatch($request);
            }

            return $request;
        });
    }

    /**
     * @return list<string>
     */
    public static function manualFieldNames(ManualEntry $entry): array
    {
        $map = [
            'kind' => 'kind', 'vendor_id' => 'vendor', 'amount' => 'amount', 'currency' => 'amount',
            'invoice_number' => 'invoice_number', 'invoice_date' => 'invoice_date', 'due_date' => 'due_date',
            'payee' => 'payee', 'sender_email' => 'sender', 'title' => 'title', 'description' => 'description',
        ];
        $names = [];

        foreach ($entry->provided as $field) {
            $root = explode('.', $field)[0];

            if (isset($map[$root])) {
                $names[] = $map[$root];
            }
        }

        return array_values(array_unique($names));
    }

    private function assignVendor(PaymentRequest $request, Workspace $workspace, ?int $vendorId, ?string $senderEmail): void
    {
        if ($vendorId !== null) {
            $request->forceFill(['vendor_id' => $vendorId, 'vendor_match_method' => VendorMatchMethod::Manual, 'vendor_match_confidence' => 100]);

            return;
        }

        $fingerprint = $request->getAttribute('payee_fingerprint');
        $match = $this->matcher->match(
            $workspace->id,
            null,
            EmailNormalizer::domain($senderEmail),
            null,
            is_string($fingerprint) ? $fingerprint : null,
            array_values(array_filter((array) config('scampause.free_mail_domains', []), 'is_string')),
        );

        $request->forceFill([
            'vendor_id' => $match->vendorId,
            'vendor_match_method' => $match->method,
            'vendor_match_confidence' => $match->confidence,
        ]);
    }

    private function created(PaymentRequest $request, User $user, int $files): void
    {
        /** @var PaymentRequestSource $source */
        $source = $request->getAttribute('source');
        /** @var PaymentRequestKind $kind */
        $kind = $request->getAttribute('kind');

        $this->ledger->append(
            workspaceId: (int) $request->getAttribute('workspace_id'),
            event: 'request.created',
            subjectType: 'payment_request',
            subjectId: (int) $request->getKey(),
            payload: ['source' => $source->value, 'kind' => $kind->value, 'files' => $files],
            actorUserId: $user->id,
            paymentRequestId: (int) $request->getKey(),
        );

        $this->auditor->record(
            'payment_request.created',
            $request,
            metadata: ['reference' => (string) $request->getAttribute('reference'), 'source' => $source->value, 'files' => $files],
            actor: $user,
            workspace: (int) $request->getAttribute('workspace_id'),
            subjectLabel: (string) $request->getAttribute('reference'),
        );
    }

    private static function clean(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max, 'UTF-8');
    }
}
