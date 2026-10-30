<?php

declare(strict_types=1);

namespace App\Http\Controllers\Domain;

use App\Audit\Auditor;
use App\Domain\Vendors\BankAccountService;
use App\Domain\Vendors\ContactService;
use App\Domain\Vendors\Enums\BankScheme;
use App\Domain\Vendors\Enums\DomainSource;
use App\Domain\Vendors\Enums\RetiredReason;
use App\Domain\Vendors\Models\Vendor;
use App\Domain\Vendors\Models\VendorBankAccount;
use App\Domain\Vendors\Models\VendorContact;
use App\Domain\Vendors\Models\VendorDomain;
use App\Domain\Vendors\VendorService;
use App\Domain\Verification\VerificationService;
use App\Domain\Workflow\GovernanceRecorder;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Domain\Concerns\RunsWorkflow;
use App\Http\Requests\Domain\BankAccountRequest;
use App\Http\Requests\Domain\ContactRequest;
use App\Http\Requests\Domain\RecordVerificationRequest;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class VendorMasterDataController extends Controller
{
    use RunsWorkflow;

    public function storeContact(ContactRequest $request, Vendor $vendor, CurrentWorkspace $current, ContactService $contacts): RedirectResponse
    {
        $this->allow($current, 'vendors.manage');
        $user = $this->user($request);

        return $this->run(fn () => $contacts->create($vendor, $user, $request->payload()), 'domain.flash.contact_saved');
    }

    public function updateContact(ContactRequest $request, Vendor $vendor, int $contact, CurrentWorkspace $current, ContactService $contacts): RedirectResponse
    {
        $this->allow($current, 'vendors.manage');
        $model = $this->contact($vendor, $contact);
        $user = $this->user($request);

        return $this->run(fn () => $contacts->update($model, $user, $request->payload()), 'domain.flash.contact_saved');
    }

    public function destroyContact(Request $request, Vendor $vendor, int $contact, CurrentWorkspace $current, ContactService $contacts): RedirectResponse
    {
        $this->allow($current, 'vendors.manage');
        $model = $this->contact($vendor, $contact);
        $user = $this->user($request);

        return $this->run(fn () => $contacts->retire($model, $user), 'domain.flash.contact_retired');
    }

    public function verifyContact(Request $request, Vendor $vendor, int $contact, CurrentWorkspace $current, ContactService $contacts): RedirectResponse
    {
        $this->allow($current, 'vendors.verify_bank');
        $model = $this->contact($vendor, $contact);
        $user = $this->user($request);

        return $this->run(fn () => $contacts->verify($model, $user), 'domain.flash.contact_verified');
    }

    public function storeDomain(Request $request, Vendor $vendor, CurrentWorkspace $current, VendorService $vendors): RedirectResponse
    {
        $this->allow($current, 'vendors.manage');
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'source' => ['required', Rule::enum(DomainSource::class)],
        ]);
        $user = $this->user($request);

        return $this->run(fn () => $vendors->addDomain($vendor, $user, (string) $validated['domain'], DomainSource::from((string) $validated['source'])), 'domain.flash.domain_added');
    }

    public function destroyDomain(Request $request, Vendor $vendor, int $vendorDomain, CurrentWorkspace $current, VendorService $vendors): RedirectResponse
    {
        $this->allow($current, 'vendors.manage');
        $model = VendorDomain::query()->where('vendor_id', $vendor->getKey())->whereKey($vendorDomain)->first();
        abort_if($model === null, 404);
        $user = $this->user($request);

        return $this->run(fn () => $vendors->removeDomain($model, $user), 'domain.flash.domain_removed');
    }

    public function storeAccount(BankAccountRequest $request, Vendor $vendor, CurrentWorkspace $current, BankAccountService $accounts): RedirectResponse
    {
        $this->allow($current, 'vendors.manage');
        $user = $this->user($request);

        return $this->run(fn () => $accounts->add($vendor, $user, $request->payload()), 'domain.flash.account_added');
    }

    public function verifyAccount(RecordVerificationRequest $request, Vendor $vendor, int $account, CurrentWorkspace $current, VerificationService $verifications): RedirectResponse
    {
        $this->allow($current, 'vendors.verify_bank');
        $model = $this->account($vendor, $account);
        $user = $this->user($request);

        return $this->run(fn () => $verifications->verifyAccount($model, $user, $request->verification()), 'domain.flash.verification_recorded');
    }

    public function retireAccount(Request $request, Vendor $vendor, int $account, CurrentWorkspace $current, BankAccountService $accounts): RedirectResponse
    {
        $this->allow($current, 'vendors.verify_bank');
        $model = $this->account($vendor, $account);
        $validated = $request->validate(['reason' => ['required', Rule::enum(RetiredReason::class)]]);
        $user = $this->user($request);

        return $this->run(fn () => $accounts->retire($model, $user, RetiredReason::from((string) $validated['reason'])), 'domain.flash.account_retired');
    }

    public function revealAccount(Request $request, Vendor $vendor, int $account, CurrentWorkspace $current, Auditor $auditor, GovernanceRecorder $governance): JsonResponse
    {
        $this->allow($current, 'bank_details.reveal');
        $model = $this->account($vendor, $account);
        $workspace = $this->workspace($current);
        $user = $this->user($request);
        $scheme = $model->getAttribute('scheme');
        abort_unless($scheme instanceof BankScheme, 404);
        $revealed = BankAccountService::revealed(
            is_string($h = $model->getAttribute('account_holder')) ? $h : null,
            (string) $model->getAttribute('account_number'),
            is_string($r = $model->getAttribute('routing_code')) ? $r : null,
            is_string($b = $model->getAttribute('bic')) ? $b : null,
            $scheme,
        );
        $auditor->record('vendor.bank_account_revealed', $model, metadata: ['last4' => $model->getAttribute('last4')], actor: $user, workspace: $workspace, subjectLabel: (string) $vendor->getAttribute('name'));
        $governance->record($workspace, 'bank_account.revealed', 'vendor_bank_account', (int) $model->getKey(), ['last4' => (string) $model->getAttribute('last4')], $user, '/vendors/'.$vendor->getKey());

        return response()->json(['incoming' => $revealed, 'previous' => null, 'char_diff' => null])->header('Cache-Control', 'no-store, private');
    }

    private function contact(Vendor $vendor, int $contact): VendorContact
    {
        $model = VendorContact::query()->where('vendor_id', $vendor->getKey())->whereKey($contact)->first();
        abort_if($model === null, 404);

        return $model;
    }

    private function account(Vendor $vendor, int $account): VendorBankAccount
    {
        $model = VendorBankAccount::query()->where('vendor_id', $vendor->getKey())->whereKey($account)->first();
        abort_if($model === null, 404);

        return $model;
    }
}
