<?php

declare(strict_types=1);

namespace App\Domain\Risk;

use App\Domain\Evidence\Enums\EvidenceChannel;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Intake\DomainIntelService;
use App\Domain\Intake\Enums\BankComparisonOutcome;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Enums\PaymentRequestSource;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Models\BankComparison;
use App\Domain\Intake\Models\EmailMessage;
use App\Domain\Intake\Models\Extraction;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\Pipeline;
use App\Domain\Money\FxConverter;
use App\Domain\Money\Money;
use App\Domain\Policy\PaymentSettings;
use App\Domain\Risk\Content\ContentSignals;
use App\Domain\Risk\Contracts\PolicyVerificationResolver;
use App\Domain\Risk\Enums\AssessmentTrigger;
use App\Domain\Risk\Models\RiskAssessment;
use App\Domain\Risk\Sender\SenderAnalysis;
use App\Domain\Risk\Sender\SenderAnalyzer;
use App\Domain\Risk\Sender\SenderInput;
use App\Domain\Support\BusinessDays;
use App\Domain\Support\Fingerprinter;
use App\Domain\Support\Normalize\TaxId;
use App\Domain\Vendors\Bank\BankComparisonService;
use App\Domain\Vendors\Enums\BankScheme;
use App\Domain\Vendors\Enums\ContactStatus;
use App\Domain\Vendors\Enums\VendorKind;
use App\Domain\Vendors\Models\Vendor;
use App\Domain\Vendors\Models\VendorContact;
use App\Domain\Vendors\Models\VendorDomain;
use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

final readonly class RiskAssessor
{
    public const array DECIDED_FOR_HISTORY = ['approved', 'paid'];

    public function __construct(
        private BankComparisonService $bank,
        private DomainIntelService $intel,
        private FxConverter $fx,
        private EvidenceLedger $ledger,
        private PolicyVerificationResolver $policy,
        private ClockInterface $clock,
    ) {}

    public function assess(
        PaymentRequest $request,
        AssessmentTrigger $trigger,
        ?int $actorId = null,
        EvidenceChannel $channel = EvidenceChannel::Web,
        bool $refreshComparison = false,
    ): RiskAssessment {
        $workspace = Workspace::query()->findOrFail((int) $request->getAttribute('workspace_id'));
        $this->refreshFx($request, $workspace);
        $comparison = $this->comparison($request, $refreshComparison);
        $facts = $this->facts($request, $workspace, $comparison);
        $preliminary = RiskEngine::evaluate($facts['facts']);
        $policy = $this->policy->resolve($request, $preliminary);
        $result = $policy['required']
            ? RiskEngine::evaluate($facts['facts']->withPolicy(true, $policy['reasons']))
            : $preliminary;

        return $this->persist($request, $result, $trigger, $actorId, $channel);
    }

    public function refreshFx(PaymentRequest $request, Workspace $workspace): void
    {
        $amount = $request->getAttribute('amount_minor');
        $currency = $request->getAttribute('currency');
        $date = $request->getAttribute('invoice_date');
        $onDate = $date instanceof \DateTimeInterface
            ? CarbonImmutable::instance($date)
            : CarbonImmutable::instance($this->clock->now())->setTimezone($workspace->timezone ?: 'UTC');
        $conversion = $this->fx->convert(
            $workspace->id,
            is_int($amount) ? $amount : null,
            is_string($currency) ? $currency : null,
            (string) $request->getAttribute('base_currency'),
            $onDate,
        );

        $request->forceFill([
            'amount_base_minor' => $conversion->amountBaseMinor,
            'fx_rate' => $conversion->rate,
            'fx_rate_date' => $conversion->rateDate?->toDateString(),
        ]);
    }

    /**
     * @return array{facts: RiskFacts, sender: SenderAnalysis|null}
     */
    public function facts(PaymentRequest $request, Workspace $workspace, ?BankComparison $comparison): array
    {
        $settings = PaymentSettings::for($workspace);
        $now = CarbonImmutable::instance($this->clock->now());
        $timezone = $workspace->timezone ?: 'UTC';
        $workspaceId = $workspace->id;
        $requestId = (int) $request->getKey();
        /** @var PaymentRequestKind $kind */
        $kind = $request->getAttribute('kind');
        $vendorId = $request->getAttribute('vendor_id');
        $vendor = is_int($vendorId) ? Vendor::query()->forWorkspace($workspaceId)->withTrashed()->find($vendorId) : null;
        $extraction = Extraction::query()->forWorkspace($workspaceId)
            ->where('payment_request_id', $requestId)
            ->where('revision', '<=', (int) $request->getAttribute('revision'))
            ->orderByDesc('revision')
            ->first();
        $fields = $extraction?->getAttribute('fields');
        $fields = is_array($fields) ? $fields : [];
        $text = $extraction?->getAttribute('text');
        $text = is_string($text) ? $text : '';
        $email = EmailMessage::query()->forWorkspace($workspaceId)->where('payment_request_id', $requestId)->orderByDesc('id')->first();
        $pipeline = Pipeline::of($request);
        $manualFields = $pipeline->manualFields();

        $sender = $this->sender($request, $workspace, $vendor, $email, $fields, $settings->getAttribute('own_domains'), $kind, $now);
        $subject = $email?->getAttribute('subject');
        $subject = is_string($subject) ? $subject : null;
        $source = $request->getAttribute('source');
        $senderFields = is_array($fields['sender'] ?? null) ? $fields['sender'] : [];
        $body = is_string($senderFields['body_text'] ?? null) ? $senderFields['body_text'] : null;
        $messageText = $source === PaymentRequestSource::Eml ? trim(($subject ?? '')."\n".($body ?? $text)) : null;
        $documentText = trim(($subject ?? '')."\n".$text);
        $contentHits = ContentSignals::detect($messageText, $documentText === '' ? null : $documentText);

        [$phoneLast4, $phoneInMaster] = $this->phones($workspaceId, $vendor, $fields);
        [$callbackChanged, $callbackShared] = $this->callbackFacts($workspaceId, $vendor, (int) $settings->getAttribute('callback_cooldown_days'), $now);

        $amountMinor = $request->getAttribute('amount_minor');
        $currency = $request->getAttribute('currency');
        $amountBase = $request->getAttribute('amount_base_minor');
        $history = $vendor !== null ? $this->history($workspaceId, (int) $vendor->getKey(), $requestId, $now) : [];

        $due = $request->getAttribute('due_date');
        $received = CarbonImmutable::instance($request->getAttribute('created_at') instanceof \DateTimeInterface ? $request->getAttribute('created_at') : $now)->setTimezone($timezone);
        $dueBusinessDays = null;

        if ($due instanceof \DateTimeInterface) {
            $dueDate = CarbonImmutable::parse(CarbonImmutable::instance($due)->toDateString(), $timezone);
            $today = $now->setTimezone($timezone)->startOfDay();
            $businessDays = array_values(array_map('intval', (array) $settings->getAttribute('business_days')));
            $dueBusinessDays = $dueDate->lessThan($today) ? -1 : BusinessDays::between($received->startOfDay(), $dueDate, $businessDays);
        }

        [$qrMismatch, $qrEvidence, $qrOnly] = $this->qrFacts($workspaceId, $fields, $vendor, $manualFields);

        $holder = $request->getAttribute('payee_account_holder');
        $vendorCreated = $vendor?->getAttribute('created_at');
        $warnings = $extraction?->getAttribute('warnings');
        $warnings = is_array($warnings) ? $warnings : [];

        $facts = new RiskFacts(
            kind: $kind,
            comparisonOutcome: $comparison?->getAttribute('outcome') instanceof BankComparisonOutcome ? $comparison->getAttribute('outcome') : null,
            comparisonDetails: is_array($comparison?->getAttribute('details')) ? $comparison->getAttribute('details') : [],
            payeeHolderPresent: is_string($holder) && trim($holder) !== '',
            payeeChecksumValid: is_bool($valid = $request->getAttribute('payee_checksum_valid')) ? $valid : null,
            employeeResponse: $request->getAttribute('employee_response'),
            senderHits: $sender->hits ?? [],
            contentHits: $contentHits,
            messagePhoneLast4: $phoneLast4,
            messagePhoneInMaster: $phoneInMaster,
            vendorMatched: $vendor !== null,
            vendorAgeDays: $vendorCreated instanceof \DateTimeInterface ? (int) floor(CarbonImmutable::instance($vendorCreated)->diffInDays($now, true)) : null,
            vendorHasPaid: $vendor !== null && ($vendor->getAttribute('first_paid_at') !== null || PaymentRequest::query()->forWorkspace($workspaceId)
                ->where('vendor_id', $vendor->getKey())->where('status', PaymentRequestStatus::Paid->value)->exists()),
            vendorDefaultCurrency: is_string($vc = $vendor?->getAttribute('default_currency')) ? $vc : null,
            duplicateInvoiceOf: $this->duplicateInvoice($request, $workspaceId, $now),
            duplicateSuspectOf: $this->duplicateSuspect($request, $workspaceId, $now),
            historyCount: count($history),
            historyBaseAmounts: $history,
            amountMinor: is_int($amountMinor) ? $amountMinor : null,
            currency: is_string($currency) ? $currency : null,
            amountBaseMinor: is_int($amountBase) ? $amountBase : null,
            amountMultipleOfThousand: is_int($amountMinor) && is_string($currency) && Money::of($amountMinor, $currency)->isMultipleOfMajor(1000),
            dueBusinessDays: $dueBusinessDays,
            qrMismatch: $qrMismatch,
            qrMismatchEvidence: $qrEvidence,
            qrOnly: $qrOnly,
            fxMissing: is_int($amountMinor) && is_string($currency) && ! is_int($amountBase),
            lowConfidenceFields: $this->lowConfidence($fields, $manualFields),
            pipelineIncomplete: $pipeline->incomplete(),
            ocrLanguageMissing: in_array('OCR_LANGUAGE_MISSING', $warnings, true),
            callbackRecentlyChanged: $callbackChanged,
            callbackPhoneShared: $callbackShared,
            verificationThresholdMinor: (int) $settings->getAttribute('verification_amount_threshold_minor'),
            requestDigest: [
                'revision' => (int) $request->getAttribute('revision'),
                'kind' => $kind->value,
                'vendor_id' => is_int($vendorId) ? $vendorId : null,
                'amount_minor' => is_int($amountMinor) ? $amountMinor : null,
                'currency' => is_string($currency) ? $currency : null,
                'amount_base_minor' => is_int($amountBase) ? $amountBase : null,
                'payee_last4' => is_string($l4 = $request->getAttribute('payee_last4')) ? $l4 : null,
                'invoice_number' => is_string($inv = $request->getAttribute('invoice_number_normalized')) ? $inv : null,
                'due_date' => $due instanceof \DateTimeInterface ? CarbonImmutable::instance($due)->toDateString() : null,
                'sender_domain' => is_string($sd = $request->getAttribute('sender_domain')) ? $sd : null,
                'reply_to_domain' => is_string($rd = $request->getAttribute('reply_to_domain')) ? $rd : null,
            ],
        );

        return ['facts' => $facts, 'sender' => $sender];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function sender(
        PaymentRequest $request,
        Workspace $workspace,
        ?Vendor $vendor,
        ?EmailMessage $email,
        array $fields,
        mixed $ownDomains,
        PaymentRequestKind $kind,
        CarbonImmutable $now,
    ): ?SenderAnalysis {
        $fromEmail = $request->getAttribute('sender_email');

        if (! is_string($fromEmail) || $fromEmail === '') {
            return null;
        }

        $vendorDomains = $vendor !== null
            ? VendorDomain::query()->forWorkspace($workspace->id)->where('vendor_id', $vendor->getKey())->pluck('domain')->map(fn (mixed $d): string => (string) $d)->all()
            : [];
        $workspaceDomains = VendorDomain::query()->forWorkspace($workspace->id)->whereHas('vendor')->limit(2000)->pluck('domain')->map(fn (mixed $d): string => (string) $d)->unique()->values()->all();
        $memberNames = $workspace->members()
            ->wherePivotIn('role', [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value])
            ->pluck('users.name')
            ->map(fn (mixed $name): string => (string) $name)
            ->all();
        $returnPath = $email?->getAttribute('return_path_domain');
        $fromName = $email?->getAttribute('from_name');
        $replyTo = $request->getAttribute('reply_to_email');
        $emails = self::stringList($fields['emails'] ?? []);
        $urls = self::stringList($fields['urls'] ?? []);
        $textDomains = self::stringList($fields['domains'] ?? []);
        $candidates = array_values(array_filter([
            $request->getAttribute('sender_domain'),
            $request->getAttribute('reply_to_domain'),
            is_string($returnPath) ? $returnPath : null,
        ], 'is_string'));
        $mode = $this->intel->mode($workspace);

        return SenderAnalyzer::analyze(new SenderInput(
            fromName: is_string($fromName) ? $fromName : null,
            fromEmail: $fromEmail,
            replyTo: is_string($replyTo) ? $replyTo : null,
            returnPath: is_string($returnPath) ? $returnPath : null,
            textEmails: $emails,
            urls: $urls,
            textDomains: $textDomains,
            vendorMatched: $vendor !== null,
            vendorDomains: array_values($vendorDomains),
            workspaceVendorDomains: array_values($workspaceDomains),
            ownDomains: self::stringList($ownDomains),
            privilegedMemberNames: array_values($memberNames),
            spf: $email?->getAttribute('auth_spf'),
            dkim: $email?->getAttribute('auth_dkim'),
            dmarc: $email?->getAttribute('auth_dmarc'),
            facts: $this->intel->factsFor($workspace, $candidates),
            freeMailDomains: self::stringList(config('scampause.free_mail_domains', [])),
            vendorIsEmployee: $vendor?->getAttribute('kind') === VendorKind::Employee,
            payrollChange: $kind === PaymentRequestKind::PayrollChange,
            today: $now->setTimezone($workspace->timezone ?: 'UTC')->startOfDay(),
            intelSkippedByPlan: $mode === DomainIntelService::MODE_CACHE_ONLY,
        ));
    }

    private function comparison(PaymentRequest $request, bool $refresh): BankComparison
    {
        if (! $refresh) {
            $existing = BankComparison::query()->forWorkspace((int) $request->getAttribute('workspace_id'))
                ->where('payment_request_id', $request->getKey())
                ->where('revision', (int) $request->getAttribute('revision'))
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return $this->bank->compareAndStore($request);
    }

    private function persist(PaymentRequest $request, RiskResult $result, AssessmentTrigger $trigger, ?int $actorId, EvidenceChannel $channel): RiskAssessment
    {
        return DB::transaction(function () use ($request, $result, $trigger, $actorId, $channel): RiskAssessment {
            $workspaceId = (int) $request->getAttribute('workspace_id');
            $assessment = new RiskAssessment;
            $assessment->forceFill([
                'workspace_id' => $workspaceId,
                'payment_request_id' => $request->getKey(),
                'revision' => (int) $request->getAttribute('revision'),
                'rules_version' => $result->rulesVersion,
                'score' => $result->score,
                'level' => $result->level,
                'raw_points' => min(32767, $result->rawPoints),
                'floor_signal' => $result->floorSignal,
                'verification_required' => $result->verificationRequired,
                'inputs_digest' => $result->inputsDigest,
                'trigger' => $trigger,
            ])->save();

            $now = CarbonImmutable::instance($this->clock->now());
            $rows = array_map(static fn (ScoredSignal $signal): array => [
                'workspace_id' => $workspaceId,
                'risk_assessment_id' => $assessment->getKey(),
                'payment_request_id' => $request->getKey(),
                'code' => $signal->code,
                'category' => $signal->category->value,
                'severity' => $signal->severity->value,
                'points' => $signal->points,
                'evidence' => json_encode($signal->evidence === [] ? new \stdClass : $signal->evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ], $result->signals);

            if ($rows !== []) {
                DB::table('risk_signals')->insert($rows);
            }

            $request->forceFill([
                'risk_assessment_id' => $assessment->getKey(),
                'risk_score' => $result->score,
                'risk_level' => $result->level,
                'verification_required' => $result->verificationRequired,
                'verification_reasons' => $result->verificationReasons,
            ])->save();

            $this->ledger->append(
                workspaceId: $workspaceId,
                event: 'request.assessed',
                subjectType: 'payment_request',
                subjectId: (int) $request->getKey(),
                payload: [
                    'score' => $result->score,
                    'level' => $result->level->value,
                    'signals' => $result->codes(),
                    'rules_version' => $result->rulesVersion,
                    'inputs_digest' => $result->inputsDigest,
                    'trigger' => $trigger->value,
                ],
                actorUserId: $actorId,
                channel: $channel,
                paymentRequestId: (int) $request->getKey(),
            );

            return $assessment;
        });
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array{0: list<string>, 1: bool}
     */
    private function phones(int $workspaceId, ?Vendor $vendor, array $fields): array
    {
        $phones = self::stringList($fields['phones_e164'] ?? []);

        if ($phones === []) {
            return [[], false];
        }

        $fingerprinter = Fingerprinter::fromConfig();
        $hashes = array_map(static fn (string $phone): string => $fingerprinter->phone($workspaceId, $phone), $phones);
        $inMaster = VendorContact::query()->forWorkspace($workspaceId)
            ->when($vendor !== null, fn ($query) => $query->where('vendor_id', $vendor?->getKey()))
            ->whereIn('phone_hash', $hashes)
            ->exists();

        return [array_values(array_unique(array_map(static fn (string $phone): string => substr($phone, -4), $phones))), $inMaster];
    }

    /**
     * @return array{0: array{last4: string, changed_at: string}|null, 1: array{last4: string, other_vendors: int}|null}
     */
    private function callbackFacts(int $workspaceId, ?Vendor $vendor, int $cooldownDays, CarbonImmutable $now): array
    {
        if ($vendor === null) {
            return [null, null];
        }

        $contacts = VendorContact::query()->forWorkspace($workspaceId)
            ->where('vendor_id', $vendor->getKey())
            ->where('is_callback', true)
            ->where('status', '<>', ContactStatus::Retired->value)
            ->orderBy('id')
            ->get();
        $changed = null;
        $shared = null;
        $threshold = $now->subDays($cooldownDays);

        foreach ($contacts as $contact) {
            $moments = array_filter([
                $contact->getAttribute('created_at'),
                $contact->getAttribute('verified_at'),
                $contact->getAttribute('phone_changed_at'),
            ], static fn (mixed $value): bool => $value instanceof \DateTimeInterface);
            $latest = null;

            foreach ($moments as $moment) {
                $instant = CarbonImmutable::instance($moment);
                $latest = $latest === null || $instant->greaterThan($latest) ? $instant : $latest;
            }

            if ($changed === null && $latest !== null && $latest->greaterThanOrEqualTo($threshold)) {
                $changed = ['last4' => (string) $contact->getAttribute('phone_last4'), 'changed_at' => $latest->toIso8601ZuluString()];
            }

            $hash = $contact->getAttribute('phone_hash');

            if ($shared === null && is_string($hash)) {
                $others = VendorContact::query()->forWorkspace($workspaceId)
                    ->where('phone_hash', $hash)
                    ->where('is_callback', true)
                    ->where('status', '<>', ContactStatus::Retired->value)
                    ->where('vendor_id', '<>', $vendor->getKey())
                    ->whereHas('vendor')
                    ->distinct()
                    ->count('vendor_id');

                if ($others > 0) {
                    $shared = ['last4' => (string) $contact->getAttribute('phone_last4'), 'other_vendors' => $others];
                }
            }
        }

        return [$changed, $shared];
    }

    /** @return list<int> */
    private function history(int $workspaceId, int $vendorId, int $requestId, CarbonImmutable $now): array
    {
        return array_values(PaymentRequest::query()->forWorkspace($workspaceId)
            ->where('vendor_id', $vendorId)
            ->where('id', '<>', $requestId)
            ->whereIn('status', self::DECIDED_FOR_HISTORY)
            ->whereNotNull('amount_base_minor')
            ->where('decided_at', '>=', $now->subDays((int) config('scampause.risk.anomaly_history_days', 365)))
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->limit(50)
            ->pluck('amount_base_minor')
            ->map(fn (mixed $value): int => (int) $value)
            ->all());
    }

    private function duplicateInvoice(PaymentRequest $request, int $workspaceId, CarbonImmutable $now): ?string
    {
        $invoice = $request->getAttribute('invoice_number_normalized');
        $vendorId = $request->getAttribute('vendor_id');

        if (! is_string($invoice) || ! is_int($vendorId)) {
            return null;
        }

        $reference = PaymentRequest::query()->forWorkspace($workspaceId)
            ->where('vendor_id', $vendorId)
            ->where('invoice_number_normalized', $invoice)
            ->where('id', '<>', $request->getKey())
            ->where('status', '<>', PaymentRequestStatus::Cancelled->value)
            ->where('created_at', '>=', $now->subDays((int) config('scampause.risk.duplicate_invoice_days', 365)))
            ->orderBy('id')
            ->value('reference');

        return is_string($reference) ? $reference : null;
    }

    private function duplicateSuspect(PaymentRequest $request, int $workspaceId, CarbonImmutable $now): ?string
    {
        $amount = $request->getAttribute('amount_minor');
        $currency = $request->getAttribute('currency');
        $vendorId = $request->getAttribute('vendor_id');

        if (! is_int($amount) || ! is_string($currency) || ! is_int($vendorId) || $amount === 0) {
            return null;
        }

        $reference = PaymentRequest::query()->forWorkspace($workspaceId)
            ->where('vendor_id', $vendorId)
            ->where('amount_minor', $amount)
            ->where('currency', $currency)
            ->where('id', '<>', $request->getKey())
            ->where('status', '<>', PaymentRequestStatus::Cancelled->value)
            ->where('created_at', '>=', $now->subDays((int) config('scampause.risk.duplicate_suspect_days', 14)))
            ->orderBy('id')
            ->value('reference');

        return is_string($reference) ? $reference : null;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $manualFields
     * @return array{0: bool, 1: array<string, string|int|bool|null|list<string>|list<int>>, 2: bool}
     */
    private function qrFacts(int $workspaceId, array $fields, ?Vendor $vendor, array $manualFields): array
    {
        $payee = is_array($fields['payee'] ?? null) ? $fields['payee'] : null;
        $qr = is_array($fields['qr_payee'] ?? null) ? $fields['qr_payee'] : null;
        $payeeSource = is_array($payee['account_number'] ?? null) ? ($payee['account_number']['source'] ?? null) : null;
        $qrOnly = $payee !== null && $payeeSource === 'qr' && ! in_array('payee', $manualFields, true);

        if ($qr === null) {
            return [false, [], $qrOnly];
        }

        $fingerprinter = Fingerprinter::fromConfig();
        $qrFingerprint = self::fieldFingerprint($fingerprinter, $workspaceId, $qr);
        $textFingerprint = $payee !== null && $payeeSource !== 'qr' ? self::fieldFingerprint($fingerprinter, $workspaceId, $payee) : null;
        $evidence = [];

        if ($qrFingerprint !== null && $textFingerprint !== null && $qrFingerprint !== $textFingerprint) {
            $evidence['reason'] = 'account_differs';
        }

        $qrTax = is_array($qr['payee_tax_id'] ?? null) && is_string($qr['payee_tax_id']['value'] ?? null) ? TaxId::normalize($qr['payee_tax_id']['value']) : null;
        $vendorTax = $vendor?->getAttribute('tax_id_normalized');

        if ($qrTax !== null && is_string($vendorTax) && $vendorTax !== '' && $qrTax !== $vendorTax) {
            $evidence['tax_id'] = 'differs';
        }

        return [$evidence !== [], $evidence, $qrOnly];
    }

    /**
     * @param  array<string, mixed>  $payee
     */
    private static function fieldFingerprint(Fingerprinter $fingerprinter, int $workspaceId, array $payee): ?string
    {
        $scheme = is_array($payee['scheme'] ?? null) ? BankScheme::tryFrom((string) ($payee['scheme']['value'] ?? '')) : null;
        $account = is_array($payee['account_number'] ?? null) && is_string($payee['account_number']['value'] ?? null) ? $payee['account_number']['value'] : null;
        $routing = is_array($payee['routing_code'] ?? null) && is_string($payee['routing_code']['value'] ?? null) ? $payee['routing_code']['value'] : null;

        return $scheme !== null && $account !== null ? $fingerprinter->bank($workspaceId, $scheme, $account, $routing) : null;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $manualFields
     * @return list<string>
     */
    private function lowConfidence(array $fields, array $manualFields): array
    {
        $threshold = (int) config('scampause.risk.low_confidence', 60);
        $low = [];
        $checks = [
            'amount' => $fields['amount_minor'] ?? null,
            'payee' => is_array($fields['payee'] ?? null) ? ($fields['payee']['account_number'] ?? null) : null,
            'vendor' => $fields['vendor_name'] ?? null,
        ];

        foreach ($checks as $name => $field) {
            if (in_array($name, $manualFields, true) || ! is_array($field)) {
                continue;
            }

            if ((int) ($field['confidence'] ?? 100) < $threshold) {
                $low[] = $name;
            }
        }

        return $low;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
