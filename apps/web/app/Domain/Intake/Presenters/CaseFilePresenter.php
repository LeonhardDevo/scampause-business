<?php

declare(strict_types=1);

namespace App\Domain\Intake\Presenters;

use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Enums\RouteStatus;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Approvals\Models\ApprovalRoute;
use App\Domain\Evidence\Models\EvidenceEntry;
use App\Domain\Intake\Contracts\PolicyPreview;
use App\Domain\Intake\DomainIntelService;
use App\Domain\Intake\Enums\CancelReason;
use App\Domain\Intake\Enums\DecisionReason;
use App\Domain\Intake\Enums\FileRole;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Models\BankComparison;
use App\Domain\Intake\Models\EmailMessage;
use App\Domain\Intake\Models\Extraction;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\Models\PaymentRequestFile;
use App\Domain\Intake\Pipeline;
use App\Domain\Notifications\Models\SpNotification;
use App\Domain\Policy\PaymentSettings;
use App\Domain\Reports\Enums\ExportKind;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Risk\Enums\RiskLevel;
use App\Domain\Risk\Models\RiskAssessment;
use App\Domain\Risk\Models\RiskSignal;
use App\Domain\Risk\RiskAssessor;
use App\Domain\Risk\SignalPresenter;
use App\Domain\Support\Fingerprinter;
use App\Domain\Support\Masking;
use App\Domain\Vendors\Enums\BankAccountStatus;
use App\Domain\Vendors\Enums\ContactStatus;
use App\Domain\Vendors\Matching\VendorMatcher;
use App\Domain\Vendors\Models\BlockedBankAccount;
use App\Domain\Vendors\Models\Vendor;
use App\Domain\Vendors\Models\VendorBankAccount;
use App\Domain\Vendors\Models\VendorContact;
use App\Domain\Vendors\VendorPresenter;
use App\Domain\Verification\Enums\VerificationMethod;
use App\Domain\Verification\Enums\VerificationOutcome;
use App\Domain\Verification\Models\Verification;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Psr\Clock\ClockInterface;

final readonly class CaseFilePresenter
{
    public function __construct(
        private CurrentWorkspace $current,
        private RiskAssessor $assessor,
        private VendorMatcher $matcher,
        private PolicyPreview $policy,
        private DomainIntelService $intel,
        private ClockInterface $clock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function show(PaymentRequest $request, User $viewer, Workspace $workspace): array
    {
        $workspaceId = $workspace->id;
        $request->loadMissing(['submitter:id,name', 'decider:id,name']);
        $vendor = is_int($request->getAttribute('vendor_id'))
            ? Vendor::query()->forWorkspace($workspaceId)->withTrashed()->find($request->getAttribute('vendor_id'))
            : null;
        $assessment = is_int($request->getAttribute('risk_assessment_id'))
            ? RiskAssessment::query()->forWorkspace($workspaceId)->find($request->getAttribute('risk_assessment_id'))
            : null;
        $signals = $assessment !== null
            ? RiskSignal::query()->forWorkspace($workspaceId)->where('risk_assessment_id', $assessment->getKey())->orderByDesc('points')->orderBy('code')->get()
            : collect();
        $extraction = Extraction::query()->forWorkspace($workspaceId)->where('payment_request_id', $request->getKey())->orderByDesc('revision')->first();
        $fields = $extraction?->getAttribute('fields');
        $fields = is_array($fields) ? $fields : [];
        $signalCodes = $signals->pluck('code')->map(fn (mixed $code): string => (string) $code)->all();
        $blocklisted = is_string($fp = $request->getAttribute('payee_fingerprint'))
            && BlockedBankAccount::query()->forWorkspace($workspaceId)->where('fingerprint', $fp)->exists();
        $row = PaymentRequestRows::one($request, $viewer);
        $preventedCategory = Refs::enum($request->getAttribute('prevented_category'));
        $fxRate = $request->getAttribute('fx_rate');

        return [
            'request' => [
                ...$row,
                'description' => self::nullableString($request->getAttribute('description')),
                'invoice_number' => self::nullableString($request->getAttribute('invoice_number')),
                'invoice_date' => Refs::date($request->getAttribute('invoice_date')),
                'amount_base' => Refs::money($request->getAttribute('amount_base_minor'), $request->getAttribute('base_currency')),
                'fx' => is_string($fxRate) && $request->getAttribute('fx_rate_date') !== null
                    ? ['rate' => $fxRate, 'date' => (string) Refs::date($request->getAttribute('fx_rate_date'))]
                    : null,
                'vendor_match' => [
                    'method' => Refs::enum($request->getAttribute('vendor_match_method')),
                    'confidence' => is_int($c = $request->getAttribute('vendor_match_confidence')) ? $c : null,
                ],
                'payee' => $this->payee($request),
                'payee_checksum_valid' => is_bool($v = $request->getAttribute('payee_checksum_valid')) ? $v : null,
                'submitted_by' => Refs::user($request->submitter),
                'submitted_at' => Refs::iso($request->getAttribute('submitted_at')),
                'decided_by' => Refs::user($request->decider),
                'decided_at' => Refs::iso($request->getAttribute('decided_at')),
                'decision_reason' => self::nullableString($request->getAttribute('decision_reason')),
                'decision_note' => self::nullableString($request->getAttribute('decision_note')),
                'paid_at' => Refs::iso($request->getAttribute('paid_at')),
                'payment_reference' => self::nullableString($request->getAttribute('payment_reference')),
                'prevented' => $preventedCategory !== null ? [
                    'category' => $preventedCategory,
                    'amount' => Refs::money($request->getAttribute('prevented_amount_base_minor'), $request->getAttribute('base_currency')),
                ] : null,
                'extraction_failed' => (bool) $request->getAttribute('extraction_failed'),
                'employee_response' => Refs::enum($request->getAttribute('employee_response')),
                'blocklisted' => $blocklisted,
            ],
            'pipeline' => self::pipelineStatus($request),
            'extraction' => $this->extraction($extraction, $fields),
            'risk' => $assessment !== null ? [
                'score' => (int) $assessment->getAttribute('score'),
                'level' => (string) Refs::enum($assessment->getAttribute('level')),
                'rules_version' => (string) $assessment->getAttribute('rules_version'),
                'floor_signal' => self::nullableString($assessment->getAttribute('floor_signal')),
                'assessed_at' => (string) Refs::iso($assessment->getAttribute('created_at')),
                'signals' => array_values($signals->map(fn (RiskSignal $signal): array => SignalPresenter::present($signal))->all()),
            ] : null,
            'bank_diff' => $this->bankDiff($request, $workspaceId),
            'sender' => $this->sender($request, $workspace, $vendor, $fields),
            'verification' => $this->verification($request, $vendor, $workspaceId),
            'route' => $this->route($request, $viewer, $workspaceId, current: true),
            'past_routes' => $this->route($request, $viewer, $workspaceId, current: false),
            'policy' => $request->getAttribute('status') === PaymentRequestStatus::Review ? $this->policy->preview($request) : null,
            'timeline' => $this->timeline($request, $workspaceId),
            'deliveries' => $this->deliveries($request, $workspaceId),
            'files' => $this->files($request, $workspaceId),
            'preview_pages' => $this->previews($request, $workspaceId),
            'source_excerpt' => self::excerpt($extraction?->getAttribute('text'), $request),
            'vendor_candidates' => $this->candidates($request, $workspaceId, $fields),
            'evidence_packet' => $this->packet($request, $workspaceId),
            'options' => [
                'kinds' => PaymentRequestKind::options(),
                'decision_reasons' => DecisionReason::options(),
                'cancel_reasons' => CancelReason::options(),
                'verification_methods' => array_map(static fn (VerificationMethod $method): array => [
                    'value' => $method->value,
                    'label' => $method->label(),
                    'allowed' => ! ($method === VerificationMethod::VendorPortal && array_intersect($signalCodes, (array) config('scampause.web_only_signals', [])) !== []),
                ], VerificationMethod::cases()),
                'reassign_candidates' => $this->reassignCandidates($workspace),
            ],
            'abilities' => $this->abilities($request, $viewer, $blocklisted),
        ];
    }

    /**
     * @return array{status: string, revision: int, stages: array<string, array{state: string, at: string|null, error: string|null}>, risk: array{score: int, level: string}|null}
     */
    public static function pipelineStatus(PaymentRequest $request): array
    {
        $score = $request->getAttribute('risk_score');
        $level = Refs::enum($request->getAttribute('risk_level'));

        return [
            'status' => (string) Refs::enum($request->getAttribute('status')),
            'revision' => (int) $request->getAttribute('revision'),
            'stages' => Pipeline::of($request)->stages(),
            'risk' => is_int($score) && $level !== null ? ['score' => $score, 'level' => $level] : null,
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function abilities(PaymentRequest $request, User $viewer, bool $blocklisted): array
    {
        /** @var PaymentRequestStatus $status */
        $status = $request->getAttribute('status');
        $can = fn (string $ability): bool => $this->current->allows($ability);
        $isCreator = (int) $request->getAttribute('requested_by') === $viewer->id;
        $hasDecisions = Approval::query()->forWorkspace((int) $request->getAttribute('workspace_id'))
            ->where('payment_request_id', $request->getKey())
            ->whereIn('status', [ApprovalStatus::Approved->value, ApprovalStatus::Rejected->value])
            ->exists();
        $cancellable = in_array($status, [PaymentRequestStatus::Review, PaymentRequestStatus::AwaitingVerification, PaymentRequestStatus::AwaitingApproval, PaymentRequestStatus::Expired], true);

        return [
            'review' => $can('payment_requests.review') && $status === PaymentRequestStatus::Review,
            'submit' => $can('payment_requests.review') && $status === PaymentRequestStatus::Review && ! $blocklisted,
            'verify' => $can('payment_requests.verify') && $status === PaymentRequestStatus::AwaitingVerification,
            'cancel' => $cancellable && ($can('payment_requests.cancel') || ($isCreator && ! $hasDecisions)),
            'reopen' => $can('payment_requests.review') && $status === PaymentRequestStatus::Expired,
            'revoke' => $can('payment_requests.revoke') && $status === PaymentRequestStatus::Approved,
            'mark_paid' => $can('payment_requests.mark_paid') && $status === PaymentRequestStatus::Approved,
            'reveal' => $can('bank_details.reveal') && $request->getAttribute('payee_last4') !== null,
            'export' => $can('reports.export'),
            'reassign' => $can('payment_requests.reassign') && $status === PaymentRequestStatus::AwaitingApproval,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payee(PaymentRequest $request): ?array
    {
        $account = $request->getAttribute('payee_account_number');

        return Refs::maskedAccount(
            $request->getAttribute('payee_scheme'),
            $request->getAttribute('payee_last4'),
            is_string($account) ? $account : null,
            $request->getAttribute('payee_bank_name'),
            $request->getAttribute('payee_bank_country'),
            $request->getAttribute('payee_bic'),
            $request->getAttribute('payee_account_holder'),
        );
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function extraction(?Extraction $extraction, array $fields): array
    {
        $out = [];
        $locale = app()->getLocale();

        foreach (['vendor_name', 'invoice_number', 'invoice_date', 'due_date'] as $name) {
            $field = $fields[$name] ?? null;

            if (is_array($field) && (is_string($field['value'] ?? null) || is_int($field['value'] ?? null))) {
                $out[$name] = ['value' => (string) $field['value'], 'confidence' => (int) ($field['confidence'] ?? 0), 'source' => (string) ($field['source'] ?? 'text')];
            }
        }

        $amount = $fields['amount_minor'] ?? null;
        $currency = is_array($fields['currency'] ?? null) && is_string($fields['currency']['value'] ?? null) ? strtoupper($fields['currency']['value']) : null;

        if (is_array($amount) && is_int($amount['value'] ?? null) && $currency !== null) {
            $out['amount'] = [
                'value' => Refs::money($amount['value'], $currency),
                'confidence' => (int) ($amount['confidence'] ?? 0),
                'source' => (string) ($amount['source'] ?? 'text'),
            ];
        }

        $payee = is_array($fields['payee'] ?? null) ? $fields['payee'] : null;

        if ($payee !== null && is_array($payee['account_number'] ?? null) && is_string($payee['account_number']['value'] ?? null)) {
            $digits = (string) preg_replace('/[^A-Za-z0-9]/', '', $payee['account_number']['value']);
            $out['payee_last4'] = [
                'value' => substr($digits, -4),
                'confidence' => (int) ($payee['account_number']['confidence'] ?? 0),
                'source' => (string) ($payee['account_number']['source'] ?? 'text'),
            ];
        }

        $warnings = $extraction?->getAttribute('warnings');

        return [
            'status' => Refs::enum($extraction?->getAttribute('status')),
            'ocr_used' => (bool) $extraction?->getAttribute('ocr_used'),
            'pages' => (int) $extraction?->getAttribute('pages'),
            'llm_assisted' => (bool) $extraction?->getAttribute('llm_assisted'),
            'fields' => $out === [] ? new \stdClass : $out,
            'warnings' => array_values(array_map(static function (mixed $code) use ($locale): string {
                $key = 'domain.warnings.'.(string) $code;
                $label = __($key, [], $locale);

                return is_string($label) && $label !== $key ? $label : (string) $code;
            }, is_array($warnings) ? $warnings : [])),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function bankDiff(PaymentRequest $request, int $workspaceId): ?array
    {
        $comparison = BankComparison::query()->forWorkspace($workspaceId)
            ->where('payment_request_id', $request->getKey())
            ->orderByDesc('revision')
            ->first();

        if ($comparison === null) {
            return null;
        }

        $details = $comparison->getAttribute('details');
        $details = is_array($details) ? $details : [];
        $previous = is_int($comparison->getAttribute('previous_account_id'))
            ? VendorBankAccount::query()->forWorkspace($workspaceId)->with('verifier:id,name')->find($comparison->getAttribute('previous_account_id'))
            : null;
        $incoming = $this->payee($request);
        $previousMasked = null;

        if ($previous !== null) {
            $account = (string) $previous->getAttribute('account_number');
            $previousMasked = [
                ...(Refs::maskedAccount($previous->getAttribute('scheme'), $previous->getAttribute('last4'), $account, $previous->getAttribute('bank_name'), $previous->getAttribute('bank_country'), $previous->getAttribute('bic'), $previous->getAttribute('account_holder')) ?? []),
                'verified_at' => Refs::iso($previous->getAttribute('verified_at')),
                'verified_by' => Refs::user($previous->verifier),
                'valid_from' => Refs::iso($previous->getAttribute('valid_from')),
            ];
        }

        $routingIncoming = $request->getAttribute('payee_routing_code');
        $routingPrevious = $previous?->getAttribute('routing_code');
        $pairs = [
            'scheme' => [$previousMasked['scheme'] ?? null, $incoming['scheme'] ?? null],
            'account' => [$previousMasked['masked'] ?? null, $incoming['masked'] ?? null],
            'routing' => [Masking::routing(is_string($routingPrevious) ? $routingPrevious : null), Masking::routing(is_string($routingIncoming) ? $routingIncoming : null)],
            'bic' => [$previousMasked['bic'] ?? null, $incoming['bic'] ?? null],
            'bank_name' => [$previousMasked['bank_name'] ?? null, $incoming['bank_name'] ?? null],
            'bank_country' => [$previousMasked['bank_country'] ?? null, $incoming['bank_country'] ?? null],
            'holder' => [$previousMasked['holder_masked'] ?? null, $incoming['holder_masked'] ?? null],
            'currency' => [self::nullableString($previous?->getAttribute('currency')), self::nullableString($request->getAttribute('currency'))],
        ];
        $rows = [];

        foreach ($pairs as $field => [$old, $new]) {
            $changed = $field === 'account'
                ? ($previous !== null && $request->getAttribute('payee_fingerprint') !== null && $previous->getAttribute('fingerprint') !== $request->getAttribute('payee_fingerprint'))
                : ($old !== null && $new !== null && $old !== $new);
            $rows[] = ['field' => $field, 'previous' => $old, 'incoming' => $new, 'changed' => $changed];
        }

        return [
            'outcome' => (string) Refs::enum($comparison->getAttribute('outcome')),
            'previous' => $previousMasked,
            'incoming' => $incoming,
            'rows' => $rows,
            'holder_similarity' => is_int($details['holder_similarity'] ?? null) ? $details['holder_similarity'] : null,
            'account_edit_distance' => is_int($details['account_edit_distance'] ?? null) ? $details['account_edit_distance'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>|null
     */
    private function sender(PaymentRequest $request, Workspace $workspace, ?Vendor $vendor, array $fields): ?array
    {
        if (! is_string($request->getAttribute('sender_email'))) {
            return null;
        }

        $email = EmailMessage::query()->forWorkspace($workspace->id)->where('payment_request_id', $request->getKey())->orderByDesc('id')->first();
        /** @var PaymentRequestKind $kind */
        $kind = $request->getAttribute('kind');
        $settings = PaymentSettings::for($workspace);
        $analysis = $this->assessor->sender($request, $workspace, $vendor, $email, $fields, $settings->getAttribute('own_domains'), $kind, CarbonImmutable::instance($this->clock->now()));
        $phones = is_array($fields['phones_e164'] ?? null) ? array_values(array_filter($fields['phones_e164'], 'is_string')) : [];
        $fingerprinter = Fingerprinter::fromConfig();
        $master = $phones === [] ? [] : VendorContact::query()->forWorkspace($workspace->id)
            ->when($vendor !== null, fn ($query) => $query->where('vendor_id', $vendor?->getKey()))
            ->whereNotNull('phone_hash')
            ->pluck('phone_hash')->map(fn (mixed $hash): string => (string) $hash)->all();
        $domains = $analysis->domains ?? [];

        foreach ($domains as $index => $row) {
            $firstSeen = $this->intel->firstSeen($workspace->id, $row['domain']);
            $domains[$index]['checked_at'] = $firstSeen?->toIso8601ZuluString();
        }

        return [
            'from_name' => self::nullableString($email?->getAttribute('from_name')),
            'from_email' => self::nullableString($request->getAttribute('sender_email')),
            'reply_to' => self::nullableString($request->getAttribute('reply_to_email')),
            'return_path_domain' => self::nullableString($email?->getAttribute('return_path_domain')),
            'auth' => [
                'spf' => Refs::enum($email?->getAttribute('auth_spf')),
                'dkim' => Refs::enum($email?->getAttribute('auth_dkim')),
                'dmarc' => Refs::enum($email?->getAttribute('auth_dmarc')),
            ],
            'domains' => $domains,
            'phones_in_message' => array_map(fn (string $phone): array => [
                'last4' => substr($phone, -4),
                'matches_master' => in_array($fingerprinter->phone($workspace->id, $phone), $master, true),
            ], $phones),
            'notes' => array_map(static function (string $note): string {
                $key = 'domain.sender_notes.'.$note;
                $label = __($key);

                return is_string($label) && $label !== $key ? $label : $note;
            }, $analysis->notes ?? []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function verification(PaymentRequest $request, ?Vendor $vendor, int $workspaceId): array
    {
        $reasons = $request->getAttribute('verification_reasons');
        $attempts = Verification::query()->forWorkspace($workspaceId)
            ->where('payment_request_id', $request->getKey())
            ->with(['performer:id,name', 'contact'])
            ->orderByDesc('occurred_at')
            ->get();
        $satisfyingId = $request->getAttribute('satisfying_verification_id');
        $contacts = $vendor === null ? collect() : VendorContact::query()->forWorkspace($workspaceId)
            ->where('vendor_id', $vendor->getKey())
            ->where('is_callback', true)
            ->where('status', ContactStatus::Verified->value)
            ->orderBy('id')
            ->get();
        $fingerprint = $request->getAttribute('payee_fingerprint');
        $replaceable = $vendor === null ? collect() : VendorBankAccount::query()->forWorkspace($workspaceId)
            ->where('vendor_id', $vendor->getKey())
            ->where('status', BankAccountStatus::Verified->value)
            ->when(is_string($fingerprint), fn (Builder $query) => $query->where('fingerprint', '!=', $fingerprint))
            ->orderBy('id')
            ->get();

        return [
            'required' => (bool) $request->getAttribute('verification_required'),
            'reasons' => array_values(array_unique(array_map(self::reasonLabel(...), is_array($reasons) ? array_values(array_filter($reasons, 'is_string')) : []))),
            'satisfied' => $request->getAttribute('verification_satisfied_at') !== null,
            'attempts' => array_values($attempts->map(fn (Verification $verification): array => self::verificationDto($verification, $satisfyingId))->all()),
            'callback_contacts' => array_values($contacts->map(fn (VendorContact $contact): array => [
                'id' => (int) $contact->getKey(),
                'name' => (string) $contact->getAttribute('name'),
                'role_title' => self::nullableString($contact->getAttribute('role_title')),
                'phone_display' => self::nullableString($contact->getAttribute('phone_e164')) ?? (string) Masking::phone(self::nullableString($contact->getAttribute('phone_last4'))),
                'source' => (string) Refs::enum($contact->getAttribute('source')),
                'source_ref' => self::nullableString($contact->getAttribute('source_ref')),
            ])->all()),
            'vendor_has_callback_contact' => $contacts->isNotEmpty(),
            'replaceable_accounts' => array_values($replaceable->map(fn (VendorBankAccount $account): array => [
                'id' => (int) $account->getKey(),
                'label' => (string) (VendorPresenter::masked($account)['masked'] ?? $account->getAttribute('last4')),
            ])->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function verificationDto(Verification $verification, mixed $satisfyingId): array
    {
        $contact = $verification->contact;
        $checklist = $verification->getAttribute('checklist');
        $checklist = is_array($checklist) ? $checklist : [];
        $keys = ['account_last4', 'amount', 'account_holder', 'change_initiated_by_vendor', 'invoice_number'];

        return [
            'id' => (int) $verification->getKey(),
            'method' => (string) Refs::enum($verification->getAttribute('method')),
            'outcome' => (string) Refs::enum($verification->getAttribute('outcome')),
            'occurred_at' => (string) Refs::iso($verification->getAttribute('occurred_at')),
            'recorded_at' => (string) Refs::iso($verification->getAttribute('created_at')),
            'performed_by' => Refs::user($verification->performer) ?? ['id' => (int) $verification->getAttribute('performed_by'), 'name' => '', 'avatar' => null],
            'spoken_to_name' => (string) ($verification->getAttribute('spoken_to_name') ?? ''),
            'contact' => $contact !== null ? [
                'id' => (int) $contact->getKey(),
                'name' => (string) $contact->getAttribute('name'),
                'phone_last4' => (string) $contact->getAttribute('phone_last4'),
                'source' => (string) Refs::enum($contact->getAttribute('source')),
                'source_ref' => self::nullableString($contact->getAttribute('source_ref')),
            ] : null,
            'checklist' => array_combine($keys, array_map(static fn (string $key): bool => (bool) ($checklist[$key] ?? false), $keys)),
            'notes' => self::nullableString($verification->getAttribute('notes')),
            'sod_waived' => (bool) $verification->getAttribute('sod_waived'),
            'satisfies' => is_int($satisfyingId) && $satisfyingId === (int) $verification->getKey()
                && $verification->getAttribute('outcome') === VerificationOutcome::Confirmed,
        ];
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>|null
     */
    private function route(PaymentRequest $request, User $viewer, int $workspaceId, bool $current): ?array
    {
        $currentId = $request->getAttribute('current_route_id');
        $query = ApprovalRoute::query()->forWorkspace($workspaceId)->where('payment_request_id', $request->getKey())->orderByDesc('attempt');

        if ($current) {
            $route = is_int($currentId) ? (clone $query)->whereKey($currentId)->first() : null;

            return $route !== null ? $this->routeDto($route, $request, $viewer) : null;
        }

        return array_values($query->when(is_int($currentId), fn ($q) => $q->whereKeyNot($currentId))->get()
            ->map(fn (ApprovalRoute $route): array => $this->routeDto($route, $request, $viewer))->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function routeDto(ApprovalRoute $route, PaymentRequest $request, User $viewer): array
    {
        $approvals = Approval::query()->forWorkspace((int) $route->getAttribute('workspace_id'))
            ->where('approval_route_id', $route->getKey())
            ->with('approver:id,name')
            ->orderBy('slot')
            ->orderBy('id')
            ->get();
        $workspace = $this->current->get();
        $roles = [];

        if ($workspace !== null) {
            foreach ($workspace->members()->whereIn('users.id', $approvals->pluck('approver_id')->all())->get() as $member) {
                $roles[$member->id] = Refs::enum($member->membership?->role) ?? 'member';
            }
        }

        $active = $route->getAttribute('status') === RouteStatus::Active;
        $awaiting = $request->getAttribute('status') === PaymentRequestStatus::AwaitingApproval;
        $live = $approvals->filter(fn (Approval $approval): bool => $approval->getAttribute('status') !== ApprovalStatus::Reassigned);
        $snapshot = $route->getAttribute('rule_snapshot');

        return [
            'id' => (int) $route->getKey(),
            'attempt' => (int) $route->getAttribute('attempt'),
            'status' => (string) Refs::enum($route->getAttribute('status')),
            'approvals_required' => (int) $route->getAttribute('approvals_required'),
            'required_roles' => array_values(array_filter((array) $route->getAttribute('required_roles'), 'is_string')),
            'rules' => array_values(array_map(static fn (mixed $rule): array => [
                'id' => is_array($rule) ? (int) ($rule['id'] ?? 0) : 0,
                'name' => is_array($rule) ? (string) ($rule['name'] ?? '') : '',
                'version' => is_array($rule) ? (int) ($rule['version'] ?? 1) : 1,
            ], is_array($snapshot) ? $snapshot : [])),
            'started_at' => (string) Refs::iso($route->getAttribute('started_at')),
            'expires_at' => (string) Refs::iso($route->getAttribute('expires_at')),
            'escalated_at' => Refs::iso($route->getAttribute('escalated_at')),
            'solo_mode' => (bool) $route->getAttribute('solo_mode'),
            'incomplete' => $live->count() < (int) $route->getAttribute('approvals_required'),
            'approvals' => array_values($approvals->map(fn (Approval $approval): array => [
                'id' => (int) $approval->getKey(),
                'slot' => (int) $approval->getAttribute('slot'),
                'approver' => [
                    ...(Refs::user($approval->approver) ?? ['id' => (int) $approval->getAttribute('approver_id'), 'name' => '', 'avatar' => null]),
                    'role' => $roles[(int) $approval->getAttribute('approver_id')] ?? 'member',
                ],
                'status' => (string) Refs::enum($approval->getAttribute('status')),
                'channel' => Refs::enum($approval->getAttribute('channel')),
                'decided_at' => Refs::iso($approval->getAttribute('decided_at')),
                'reject_reason' => Refs::enum($approval->getAttribute('reject_reason')),
                'comment' => self::nullableString($approval->getAttribute('comment')),
                'reminders_sent' => (int) $approval->getAttribute('reminders_sent'),
                'can_decide' => $active && $awaiting && $approval->getAttribute('status') === ApprovalStatus::Pending
                    && (int) $approval->getAttribute('approver_id') === $viewer->id,
                'can_reassign' => $active && $approval->getAttribute('status') === ApprovalStatus::Pending
                    && $this->current->allows('payment_requests.reassign'),
            ])->all()),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function timeline(PaymentRequest $request, int $workspaceId): array
    {
        $entries = EvidenceEntry::query()->forWorkspace($workspaceId)
            ->where('payment_request_id', $request->getKey())
            ->with('actor:id,name')
            ->orderBy('seq')
            ->limit(500)
            ->get();

        return array_values($entries->map(function (EvidenceEntry $entry): array {
            $event = (string) $entry->getAttribute('event');
            $key = 'domain.events.'.str_replace('.', '_', $event);
            $label = __($key);
            $payload = $entry->getAttribute('payload');
            $details = [];

            foreach (is_array($payload) ? $payload : [] as $name => $value) {
                $details[(string) $name] = is_array($value)
                    ? implode(', ', array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : (string) json_encode($item), $value))
                    : (is_scalar($value) || $value === null ? $value : (string) json_encode($value));
            }

            return [
                'id' => (int) $entry->getKey(),
                'seq' => (int) $entry->getAttribute('seq'),
                'event' => $event,
                'label' => is_string($label) && $label !== $key ? $label : $event,
                'actor' => Refs::user($entry->actor),
                'channel' => (string) Refs::enum($entry->getAttribute('channel')),
                'occurred_at' => (string) Refs::iso($entry->getAttribute('occurred_at')),
                'details' => $details === [] ? new \stdClass : $details,
                'hash' => (string) $entry->getAttribute('hash'),
            ];
        })->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function deliveries(PaymentRequest $request, int $workspaceId): array
    {
        return array_values(SpNotification::query()->forWorkspace($workspaceId)
            ->where('payment_request_id', $request->getKey())
            ->whereIn('channel', ['email', 'telegram'])
            ->with('recipient:id,name')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (SpNotification $notification): array => [
                'id' => (int) $notification->getKey(),
                'channel' => (string) $notification->getAttribute('channel'),
                'template' => (string) $notification->getAttribute('template'),
                'recipient' => Refs::user($notification->recipient),
                'status' => (string) $notification->getAttribute('status'),
                'at' => (string) Refs::iso($notification->getAttribute('updated_at')),
            ])->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function files(PaymentRequest $request, int $workspaceId): array
    {
        $ttl = (int) config('scampause.intake.download_ttl_minutes', 5);

        return array_values(PaymentRequestFile::query()->forWorkspace($workspaceId)
            ->where('payment_request_id', $request->getKey())
            ->whereIn('role', [FileRole::Original->value, FileRole::Attachment->value, FileRole::Verification->value])
            ->orderBy('id')
            ->get()
            ->map(fn (PaymentRequestFile $file): array => [
                'id' => (int) $file->getKey(),
                'role' => (string) Refs::enum($file->getAttribute('role')),
                'original_name' => (string) $file->getAttribute('original_name'),
                'mime' => (string) $file->getAttribute('mime'),
                'size_bytes' => (int) $file->getAttribute('size_bytes'),
                'pages' => is_int($p = $file->getAttribute('pages')) ? $p : null,
                'sha256' => (string) $file->getAttribute('sha256'),
                'download_url' => $file->getAttribute('purged_at') === null
                    ? URL::temporarySignedRoute('payment-requests.files.download', now()->addMinutes($ttl), ['paymentRequest' => $request->getKey(), 'file' => $file->getKey()])
                    : null,
                'purged' => $file->getAttribute('purged_at') !== null,
            ])->all());
    }

    /**
     * @return list<array{page: int, url: string}>
     */
    private function previews(PaymentRequest $request, int $workspaceId): array
    {
        $ttl = (int) config('scampause.intake.download_ttl_minutes', 5);

        return array_values(PaymentRequestFile::query()->forWorkspace($workspaceId)
            ->where('payment_request_id', $request->getKey())
            ->where('role', FileRole::Preview->value)
            ->whereNull('purged_at')
            ->orderBy('parent_file_id')
            ->orderBy('page')
            ->limit((int) config('scampause.uploads.preview_max_pages', 10))
            ->get()
            ->values()
            ->map(fn (PaymentRequestFile $file, int $index): array => [
                'page' => is_int($p = $file->getAttribute('page')) ? $p : $index + 1,
                'url' => URL::temporarySignedRoute('payment-requests.files.preview', now()->addMinutes($ttl), [
                    'paymentRequest' => $request->getKey(),
                    'file' => $file->getKey(),
                    'page' => is_int($p) ? $p : $index + 1,
                ]),
            ])->all());
    }

    public static function excerpt(mixed $text, PaymentRequest $request): string
    {
        if (! is_string($text) || $text === '') {
            return '';
        }

        $limit = (int) config('scampause.intake.source_excerpt_chars', 4000);
        $excerpt = htmlspecialchars(mb_substr($text, 0, $limit, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $needles = array_values(array_filter([
            self::nullableString($request->getAttribute('invoice_number')),
            self::nullableString($request->getAttribute('payee_last4')),
        ], static fn (?string $value): bool => $value !== null && mb_strlen($value, 'UTF-8') >= 4));

        foreach ($needles as $needle) {
            $escaped = htmlspecialchars($needle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $excerpt = (string) preg_replace('/'.preg_quote($escaped, '/').'/u', '<mark>'.$escaped.'</mark>', $excerpt);
        }

        return $excerpt;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return list<array<string, mixed>>
     */
    private function candidates(PaymentRequest $request, int $workspaceId, array $fields): array
    {
        if ($request->getAttribute('status') !== PaymentRequestStatus::Review) {
            return [];
        }

        $name = is_array($fields['vendor_name'] ?? null) && is_string($fields['vendor_name']['value'] ?? null) ? $fields['vendor_name']['value'] : null;
        $tax = is_array($fields['vendor_tax_id'] ?? null) && is_string($fields['vendor_tax_id']['value'] ?? null) ? $fields['vendor_tax_id']['value'] : null;
        $match = $this->matcher->match(
            $workspaceId,
            $tax,
            self::nullableString($request->getAttribute('sender_domain')),
            $name,
            self::nullableString($request->getAttribute('payee_fingerprint')),
            array_values(array_filter((array) config('scampause.free_mail_domains', []), 'is_string')),
        );
        $ids = array_map(static fn ($candidate): int => $candidate->vendorId, $match->candidates);
        $vendors = Vendor::query()->forWorkspace($workspaceId)->whereIn('id', $ids)->get()->keyBy('id');
        $out = [];

        foreach (array_slice($match->candidates, 0, 5) as $candidate) {
            $vendor = $vendors->get($candidate->vendorId);

            if ($vendor instanceof Vendor) {
                $out[] = [...(Refs::vendor($vendor) ?? []), 'confidence' => $candidate->confidence, 'method' => $candidate->method->value];
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function packet(PaymentRequest $request, int $workspaceId): ?array
    {
        $export = ReportExport::query()->forWorkspace($workspaceId)
            ->where('payment_request_id', $request->getKey())
            ->where('kind', ExportKind::EvidencePacket->value)
            ->orderByDesc('id')
            ->first();

        if ($export === null) {
            return null;
        }

        $status = (string) Refs::enum($export->getAttribute('status'));

        return [
            'id' => (int) $export->getKey(),
            'status' => $status,
            'download_url' => $status === 'ready' && Route::has('reports.exports.download')
                ? URL::temporarySignedRoute('reports.exports.download', now()->addMinutes(5), ['export' => $export->getKey()])
                : null,
            'chain_verified' => is_bool($v = $export->getAttribute('chain_verified')) ? $v : null,
        ];
    }

    /**
     * @return list<array{id: int, name: string, avatar: null}>
     */
    private function reassignCandidates(Workspace $workspace): array
    {
        if (! $this->current->allows('payment_requests.reassign')) {
            return [];
        }

        return array_values($workspace->members()
            ->wherePivotIn('role', [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value, WorkspaceRole::Approver->value])
            ->orderBy('users.name')
            ->get(['users.id', 'users.name'])
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'avatar' => null])
            ->all());
    }

    public static function reasonLabel(string $reason): string
    {
        [$type, $value] = str_contains($reason, ':') ? explode(':', $reason, 2) : ['signal', $reason];

        return match ($type) {
            'level' => (string) __('domain.verification_reasons.level', ['level' => RiskLevel::tryFrom($value)?->label() ?? $value]),
            'kind' => (string) __('domain.verification_reasons.kind', ['kind' => PaymentRequestKind::tryFrom($value)?->label() ?? $value]),
            'rule' => (string) __('domain.verification_reasons.rule'),
            default => SignalPresenter::title($value),
        };
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
