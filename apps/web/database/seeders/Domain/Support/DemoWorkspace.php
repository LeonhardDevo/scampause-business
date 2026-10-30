<?php

declare(strict_types=1);

namespace Database\Seeders\Domain\Support;

use App\Domain\Approvals\ApprovalService;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Enums\DecisionChannel;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Approvals\Models\ApprovalRoute;
use App\Domain\Approvals\RouteBuilder;
use App\Domain\Evidence\Enums\EvidenceChannel;
use App\Domain\Intake\Data\ExtractionResult;
use App\Domain\Intake\Data\ManualEntry;
use App\Domain\Intake\DomainIntelService;
use App\Domain\Intake\Enums\CancelReason;
use App\Domain\Intake\Enums\DecisionReason;
use App\Domain\Intake\Enums\EmployeeResponse;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Enums\PipelineStage;
use App\Domain\Intake\Enums\PipelineState;
use App\Domain\Intake\IntakeService;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\PipelineService;
use App\Domain\Intake\ReviewService;
use App\Domain\Vendors\BankAccountService;
use App\Domain\Vendors\ContactService;
use App\Domain\Vendors\Enums\BankScheme;
use App\Domain\Vendors\Enums\ContactSource;
use App\Domain\Vendors\Enums\DomainSource;
use App\Domain\Vendors\Enums\RetiredReason;
use App\Domain\Vendors\Models\Vendor;
use App\Domain\Vendors\Models\VendorBankAccount;
use App\Domain\Vendors\Models\VendorContact;
use App\Domain\Vendors\VendorService;
use App\Domain\Verification\Data\VerificationInput;
use App\Domain\Verification\EmployeeAlertService;
use App\Domain\Verification\Enums\VerificationMethod;
use App\Domain\Verification\Enums\VerificationOutcome;
use App\Domain\Verification\Models\EmployeeAlert;
use App\Domain\Verification\VerificationService;
use App\Domain\Workflow\DecisionService;
use App\Domain\Workflow\SubmissionService;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Closure;

final class DemoWorkspace
{
    public const string FIXTURES = __DIR__.'/../fixtures';

    public Timeline $timeline;

    /** @var array<string, Vendor> */
    public array $vendors = [];

    /** @var array<string, VendorContact> */
    public array $contacts = [];

    /** @var array<string, VendorBankAccount> */
    public array $accounts = [];

    /** @var array<int, int> */
    public array $requests = [];

    /**
     * @param  array<string, User>  $users
     */
    public function __construct(public Workspace $workspace, public array $users, public CarbonImmutable $now)
    {
        $this->timeline = new Timeline;
    }

    public function user(string $key): User
    {
        return $this->users[$key] ?? throw new \LogicException('Unknown demo user '.$key);
    }

    public function timezone(): string
    {
        return $this->workspace->timezone ?: 'UTC';
    }

    public function day(int $daysAgo, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $this->now->setTimezone($this->timezone())->subDays($daysAgo)->setTime($hour, $minute)->utc();
    }

    public function minutesAgo(int $minutes): CarbonImmutable
    {
        return $this->now->subMinutes($minutes)->startOfMinute();
    }

    /**
     * @param  Closure(): mixed  $step
     */
    public function at(CarbonImmutable $at, string $label, Closure $step): void
    {
        $this->timeline->at($at, $label, fn (): mixed => app(CurrentWorkspace::class)->runAs($this->workspace, $step));
    }

    public function run(): void
    {
        $this->timeline->run();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $domains
     */
    public function vendor(string $key, string $creator, array $attributes, array $domains = []): Vendor
    {
        $vendor = app(VendorService::class)->create($this->workspace, $this->user($creator), $attributes);

        foreach ($domains as $domain) {
            app(VendorService::class)->addDomain($vendor, $this->user($creator), $domain, DomainSource::Contract);
        }

        return $this->vendors[$key] = $vendor;
    }

    /**
     * @param  array{name: string, role_title?: string|null, email?: string|null, phone?: string|null, source: ContactSource, source_ref?: string|null}  $data
     */
    public function contact(string $key, string $vendorKey, string $creator, array $data, bool $callback = true): VendorContact
    {
        return $this->contacts[$key] = app(ContactService::class)->create($this->vendors[$vendorKey], $this->user($creator), [...$data, 'is_callback' => $callback]);
    }

    public function verifyContact(string $key, string $verifier): VendorContact
    {
        return $this->contacts[$key] = app(ContactService::class)->verify($this->contacts[$key], $this->user($verifier));
    }

    /**
     * @param  array{scheme: BankScheme, account_number: string, routing_code?: string|null, bic?: string|null, account_holder?: string|null, bank_name?: string|null, bank_country?: string|null, currency?: string|null, correspondent_account?: string|null}  $data
     */
    public function account(string $key, string $vendorKey, string $creator, array $data): VendorBankAccount
    {
        return $this->accounts[$key] = app(BankAccountService::class)->add($this->vendors[$vendorKey], $this->user($creator), $data);
    }

    public function verifyAccount(string $key, string $verifier, string $contactKey, ?string $replaces = null, VerificationMethod $method = VerificationMethod::CallbackKnownNumber): void
    {
        $contact = $this->contacts[$contactKey];
        app(VerificationService::class)->verifyAccount($this->accounts[$key], $this->user($verifier), new VerificationInput(
            method: $method,
            contactId: $method === VerificationMethod::InPerson ? null : (int) $contact->getKey(),
            spokenToName: (string) $contact->getAttribute('name'),
            outcome: VerificationOutcome::Confirmed,
            occurredAt: CarbonImmutable::now()->subMinutes(5),
            checklist: ['account_last4' => true, 'account_holder' => true],
            replacesAccountId: $replaces !== null ? (int) $this->accounts[$replaces]->getKey() : null,
        ));
        $this->accounts[$key] = $this->accounts[$key]->refresh();
    }

    public function retireAccount(string $key, string $actor, RetiredReason $reason): void
    {
        $this->accounts[$key] = app(BankAccountService::class)->retire($this->accounts[$key], $this->user($actor), $reason);
    }

    /**
     * @param  list<array{bytes: string, name: string, mime: string}>  $files
     * @param  array<string, mixed>  $extraction
     * @param  list<array<string, mixed>>  $intel
     */
    public function upload(int $ref, string $userKey, array $files, array $extraction, ?PaymentRequestKind $kind = null, ?string $title = null, array $intel = []): PaymentRequest
    {
        ReferenceAllocator::reserve($this->workspace->id, $ref);
        $request = app(IntakeService::class)->createFromUpload($this->workspace, $this->user($userKey), $files, $kind);
        $this->requests[$ref] = (int) $request->getKey();

        if ($title !== null) {
            $request->forceFill(['title' => $title])->save();
        }

        if ($intel !== []) {
            app(DomainIntelService::class)->store($this->workspace, $intel);
        }

        $pipeline = app(PipelineService::class);
        $workspaceId = $this->workspace->id;
        $id = (int) $request->getKey();
        $revision = (int) $request->getAttribute('revision');
        $pipeline->markStage($workspaceId, $id, $revision, PipelineStage::Extract, PipelineState::Running);
        $pipeline->ingest($request->refresh(), ExtractionResult::fromArray([
            'extractor_version' => 'worker-extract@1.0.0',
            'ocr_used' => false,
            'pages' => 1,
            'warnings' => [],
            'files' => [],
            'duration_ms' => 380,
            'llm_assisted' => false,
            'error_code' => null,
            ...$extraction,
            'workspace_id' => $workspaceId,
            'payment_request_id' => $id,
            'revision' => $revision,
        ]));

        if ($this->request($ref)->getAttribute('status') === PaymentRequestStatus::Processing) {
            $pipeline->compareBank($workspaceId, $id, $revision);
            $pipeline->assess($workspaceId, $id, $revision);
        }

        return $this->request($ref);
    }

    /**
     * @param  list<array<string, mixed>>  $intel
     */
    public function manual(int $ref, string $userKey, ManualEntry $entry, array $intel = []): PaymentRequest
    {
        ReferenceAllocator::reserve($this->workspace->id, $ref);

        if ($intel !== []) {
            app(DomainIntelService::class)->store($this->workspace, $intel);
        }

        $request = app(IntakeService::class)->createManual($this->workspace, $this->user($userKey), $entry);
        $this->requests[$ref] = (int) $request->getKey();

        if ($request->getAttribute('status') === PaymentRequestStatus::Processing) {
            $pipeline = app(PipelineService::class);
            $revision = (int) $request->getAttribute('revision');
            $pipeline->compareBank($this->workspace->id, (int) $request->getKey(), $revision);
            $pipeline->assess($this->workspace->id, (int) $request->getKey(), $revision);
        }

        return $this->request($ref);
    }

    public function request(int $ref): PaymentRequest
    {
        $id = $this->requests[$ref] ?? throw new \LogicException('Demo request '.$ref.' was not created yet.');

        return PaymentRequest::query()->forWorkspace($this->workspace)->findOrFail($id);
    }

    public function review(int $ref, string $userKey, ManualEntry $entry): PaymentRequest
    {
        return app(ReviewService::class)->update($this->request($ref), $this->user($userKey), $entry);
    }

    public function submit(int $ref, string $userKey, bool $unknownPayee = false): PaymentRequest
    {
        return app(SubmissionService::class)->submit($this->request($ref), $this->user($userKey), $unknownPayee);
    }

    /**
     * @param  array<string, bool>  $checklist
     */
    public function verify(
        int $ref,
        string $userKey,
        ?string $contactKey,
        VerificationOutcome $outcome,
        array $checklist = ['account_last4' => true, 'amount' => true, 'change_initiated_by_vendor' => true],
        ?DecisionReason $reason = null,
        ?string $replaces = null,
        VerificationMethod $method = VerificationMethod::CallbackKnownNumber,
        int $minutesBefore = 4,
    ): void {
        $contact = $contactKey !== null ? $this->contacts[$contactKey] : null;
        app(VerificationService::class)->record($this->request($ref), $this->user($userKey), new VerificationInput(
            method: $method,
            contactId: $contact !== null ? (int) $contact->getKey() : null,
            spokenToName: $contact !== null ? (string) $contact->getAttribute('name') : (string) $this->user($userKey)->name,
            outcome: $outcome,
            occurredAt: CarbonImmutable::now()->subMinutes($minutesBefore),
            checklist: $outcome === VerificationOutcome::Confirmed ? $checklist : [],
            decisionReason: $reason,
            replacesAccountId: $replaces !== null ? (int) $this->accounts[$replaces]->getKey() : null,
        ));
    }

    /**
     * @return list<Approval>
     */
    public function pending(int $ref): array
    {
        $request = $this->request($ref);
        $route = $request->getAttribute('current_route_id');

        if (! is_int($route)) {
            return [];
        }

        return array_values(Approval::query()->forWorkspace($this->workspace)
            ->where('approval_route_id', $route)
            ->where('status', ApprovalStatus::Pending->value)
            ->orderBy('slot')
            ->get()
            ->all());
    }

    public function assignTo(int $ref, int $slot, string $userKey, string $actorKey): void
    {
        $wanted = $this->user($userKey);

        foreach ($this->pending($ref) as $approval) {
            if ((int) $approval->getAttribute('approver_id') === $wanted->id) {
                return;
            }
        }

        foreach ($this->pending($ref) as $approval) {
            if ((int) $approval->getAttribute('slot') === $slot) {
                app(RouteBuilder::class)->reassign($approval, $wanted, $this->user($actorKey));

                return;
            }
        }
    }

    public function approve(int $ref, string $userKey, DecisionChannel $channel = DecisionChannel::Web): void
    {
        $user = $this->user($userKey);

        foreach ($this->pending($ref) as $approval) {
            if ((int) $approval->getAttribute('approver_id') === $user->id) {
                app(ApprovalService::class)->approve($approval, $user, $channel);

                return;
            }
        }

        throw new \LogicException('Demo user '.$userKey.' has no pending approval on SP-'.$ref.'.');
    }

    /**
     * @param  Closure(User): DecisionChannel|null  $channel
     */
    public function approveAll(int $ref, ?Closure $channel = null): void
    {
        foreach ($this->pending($ref) as $approval) {
            $approver = User::query()->findOrFail((int) $approval->getAttribute('approver_id'));
            app(ApprovalService::class)->approve($approval, $approver, $channel !== null ? $channel($approver) : DecisionChannel::Web);
        }
    }

    public function rejectFromReview(int $ref, string $userKey, DecisionReason $reason, ?string $comment = null): void
    {
        app(DecisionService::class)->rejectFromReview($this->request($ref), $this->user($userKey), $reason, $comment);
    }

    public function cancel(int $ref, string $userKey, CancelReason $reason, ?string $comment = null): void
    {
        app(DecisionService::class)->cancel($this->request($ref), $this->user($userKey), $reason, $comment, true);
    }

    public function pay(int $ref, string $userKey, string $paymentReference): void
    {
        app(DecisionService::class)->markPaid($this->request($ref), $this->user($userKey), CarbonImmutable::now(), $paymentReference);
    }

    public function remind(int $ref, int $sequence, int $total): void
    {
        foreach ($this->pending($ref) as $approval) {
            app(ApprovalService::class)->reminder($approval, $sequence, $total);
        }
    }

    public function escalate(int $ref): void
    {
        app(ApprovalService::class)->escalate($this->route($ref));
    }

    public function expire(int $ref): void
    {
        app(DecisionService::class)->expireRoute($this->route($ref), true);
    }

    public function route(int $ref): ApprovalRoute
    {
        $id = $this->request($ref)->getAttribute('current_route_id');

        return ApprovalRoute::query()->forWorkspace($this->workspace)->findOrFail(is_int($id) ? $id : 0);
    }

    public function employeeAnswer(int $ref, EmployeeResponse $response, string $userKey): void
    {
        $alert = EmployeeAlert::query()->forWorkspace($this->workspace)->where('payment_request_id', $this->requests[$ref])->firstOrFail();
        app(EmployeeAlertService::class)->answer($alert, $response, EvidenceChannel::Telegram, null, null, $this->user($userKey));
    }

    /**
     * @param  array<string, string>  $replacements
     * @return array<string, mixed>
     */
    public function fixture(string $name, CarbonImmutable $localDay, array $replacements = []): array
    {
        $path = self::FIXTURES.'/'.$name.'.extraction.json';
        $raw = (string) file_get_contents($path);
        $decoded = json_decode(strtr($raw, $replacements), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new \RuntimeException('Invalid fixture '.$name);
        }

        /** @var array<string, mixed> $resolved */
        $resolved = self::resolveDates($decoded, $localDay->setTimezone($this->timezone())->startOfDay());

        return $resolved;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function resolveDates(array $data, CarbonImmutable $day): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::resolveDates($value, $day);
            } elseif (is_string($value) && preg_match('/^@date([+-]\d+)$/', $value, $match) === 1) {
                $data[$key] = $day->addDays((int) $match[1])->toDateString();
            } elseif (is_string($value) && preg_match('/^@time([+-]\d+)m$/', $value, $match) === 1) {
                $data[$key] = CarbonImmutable::now()->addMinutes((int) $match[1])->toIso8601String();
            }
        }

        return $data;
    }
}
