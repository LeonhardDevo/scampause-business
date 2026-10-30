<?php

declare(strict_types=1);

namespace Tests\Support\Domain;

use App\Domain\Intake\Data\ManualEntry;
use App\Domain\Intake\Enums\DecisionReason;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\IntakeService;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Vendors\BankAccountService;
use App\Domain\Vendors\ContactService;
use App\Domain\Vendors\Enums\BankScheme;
use App\Domain\Vendors\Enums\ContactSource;
use App\Domain\Vendors\Enums\DomainSource;
use App\Domain\Vendors\Enums\VendorKind;
use App\Domain\Vendors\Models\Vendor;
use App\Domain\Vendors\Models\VendorBankAccount;
use App\Domain\Vendors\Models\VendorContact;
use App\Domain\Vendors\VendorService;
use App\Domain\Verification\Data\VerificationInput;
use App\Domain\Verification\Enums\VerificationMethod;
use App\Domain\Verification\Enums\VerificationOutcome;
use App\Domain\Verification\VerificationService;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;

final class Scenario
{
    /** @var array<string, User> */
    public array $users = [];

    public Workspace $workspace;

    /**
     * @param  list<WorkspaceRole>  $roles
     */
    public static function make(array $roles = [WorkspaceRole::Owner, WorkspaceRole::Admin, WorkspaceRole::Approver, WorkspaceRole::Bookkeeper, WorkspaceRole::Member], array $workspace = []): self
    {
        config(['scampause.fingerprint_key' => base64_encode('test-fingerprint-key')]);
        $scenario = new self;
        $scenario->workspace = Workspace::factory()->create(['timezone' => 'UTC', 'currency' => 'USD', ...$workspace]);
        $counts = [];

        foreach ($roles as $role) {
            $counts[$role->value] = ($counts[$role->value] ?? 0) + 1;
            $key = $counts[$role->value] === 1 ? $role->value : $role->value.$counts[$role->value];
            $user = User::factory()->create(['name' => ucfirst($key).' User', 'locale' => 'en']);
            $scenario->workspace->addMember($user, $role);
            $user->forceFill(['current_workspace_id' => $scenario->workspace->id])->saveQuietly();
            $scenario->users[$key] = $user->refresh();
        }

        return $scenario;
    }

    public function user(string $key): User
    {
        return $this->users[$key];
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public function as(string $key, \Closure $callback): mixed
    {
        return app(CurrentWorkspace::class)->runAs($this->workspace, $callback, $this->user($key)->roleIn($this->workspace));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function vendor(string $name = 'Blue Ridge Packaging Co.', array $attributes = [], string $domain = 'blueridgepack.com'): Vendor
    {
        $vendor = app(VendorService::class)->create($this->workspace, $this->user('admin'), [
            'kind' => VendorKind::Supplier->value,
            'name' => $name,
            'legal_name' => $name.' Inc.',
            'country' => 'US',
            'default_currency' => 'USD',
            ...$attributes,
        ]);

        if ($domain !== '') {
            app(VendorService::class)->addDomain($vendor, $this->user('admin'), $domain, DomainSource::Contract);
        }

        return $vendor;
    }

    public function callbackContact(Vendor $vendor, string $phone = '+18285550142', ?CarbonImmutable $createdAt = null): VendorContact
    {
        $contact = app(ContactService::class)->create($vendor, $this->user('bookkeeper'), [
            'name' => 'Karen Whitfield',
            'role_title' => 'AR Manager',
            'email' => null,
            'phone' => $phone,
            'is_callback' => true,
            'source' => ContactSource::Contract,
            'source_ref' => 'Supply Agreement BRP-2025-014, §9.2',
        ]);
        $verified = app(ContactService::class)->verify($contact, $this->user('owner'));

        if ($createdAt !== null) {
            $verified->forceFill(['created_at' => $createdAt, 'verified_at' => $createdAt, 'phone_changed_at' => $createdAt])->save();
        }

        return $verified;
    }

    public function verifiedAccount(Vendor $vendor, string $account = '000483924417', string $routing = '021000021'): VendorBankAccount
    {
        $model = app(BankAccountService::class)->add($vendor, $this->user('bookkeeper'), [
            'scheme' => BankScheme::UsAch,
            'account_number' => $account,
            'routing_code' => $routing,
            'bank_name' => 'JPMorgan Chase Bank, N.A.',
            'account_holder' => (string) $vendor->getAttribute('legal_name'),
        ]);
        $contact = VendorContact::query()->forWorkspace($this->workspace)->where('vendor_id', $vendor->getKey())->where('is_callback', true)->first()
            ?? $this->callbackContact($vendor, '+18285550142', CarbonImmutable::now()->subDays(60));

        app(VerificationService::class)->verifyAccount($model, $this->user('admin'), new VerificationInput(
            method: VerificationMethod::CallbackKnownNumber,
            contactId: (int) $contact->getKey(),
            spokenToName: 'Karen Whitfield',
            outcome: VerificationOutcome::Confirmed,
            occurredAt: CarbonImmutable::now(),
            checklist: ['account_last4' => true],
        ));

        return $model->refresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function request(string $userKey = 'bookkeeper', array $overrides = []): PaymentRequest
    {
        $entry = new ManualEntry(...array_merge([
            'provided' => ['title', 'kind', 'amount', 'currency', 'payee', 'vendor_id'],
            'title' => 'Invoice INV-'.random_int(1000, 9999),
            'kind' => PaymentRequestKind::Invoice,
            'amountMinor' => 125000,
            'currency' => 'USD',
            'invoiceNumber' => 'INV-'.random_int(10000, 99999),
            'payeeScheme' => BankScheme::UsAch,
            'payeeAccountNumber' => '000483924417',
            'payeeRoutingCode' => '021000021',
            'payeeAccountHolder' => 'Blue Ridge Packaging Co. Inc.',
        ], $overrides));
        $user = $this->user($userKey);

        return $this->as($userKey, fn (): PaymentRequest => app(IntakeService::class)->createManual($this->workspace, $user, $entry)->refresh());
    }

    public function verification(VendorContact $contact, VerificationOutcome $outcome = VerificationOutcome::Confirmed, ?DecisionReason $reason = null): VerificationInput
    {
        return new VerificationInput(
            method: VerificationMethod::CallbackKnownNumber,
            contactId: (int) $contact->getKey(),
            spokenToName: 'Karen Whitfield',
            outcome: $outcome,
            occurredAt: CarbonImmutable::now(),
            checklist: ['account_last4' => true, 'amount' => true],
            decisionReason: $reason,
        );
    }
}
