<?php

declare(strict_types=1);

namespace App\Domain\Vendors;

use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\Presenters\PaymentRequestRows;
use App\Domain\Intake\Presenters\Refs;
use App\Domain\Support\Masking;
use App\Domain\Support\Normalize\DomainNormalizer;
use App\Domain\Vendors\Enums\BankAccountStatus;
use App\Domain\Vendors\Enums\ContactStatus;
use App\Domain\Vendors\Models\BlockedBankAccount;
use App\Domain\Vendors\Models\Vendor;
use App\Domain\Vendors\Models\VendorBankAccount;
use App\Domain\Vendors\Models\VendorContact;
use App\Domain\Vendors\Models\VendorDomain;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Psr\Clock\ClockInterface;

final readonly class VendorPresenter
{
    public function __construct(private ClockInterface $clock) {}

    /**
     * @param  iterable<mixed>  $items
     * @return list<array<string, mixed>>
     */
    public function rows(Workspace $workspace, iterable $items): array
    {
        /** @var Collection<int, Vendor> $vendors */
        $vendors = (new Collection($items))->filter(static fn (mixed $item): bool => $item instanceof Vendor)->values();
        $ids = $vendors->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $domains = VendorDomain::query()->forWorkspace($workspace)->whereIn('vendor_id', $ids)->orderBy('domain')->get()->groupBy('vendor_id');
        $contacts = VendorContact::query()->forWorkspace($workspace)->whereIn('vendor_id', $ids)->where('is_callback', true)
            ->where('status', ContactStatus::Verified->value)->orderBy('id')->get()->groupBy('vendor_id');
        $accounts = VendorBankAccount::query()->forWorkspace($workspace)->whereIn('vendor_id', $ids)->orderByDesc('valid_from')->orderByDesc('id')->get()->groupBy('vendor_id');
        $open = PaymentRequest::query()->forWorkspace($workspace)->whereIn('vendor_id', $ids)
            ->whereIn('status', array_map(static fn (PaymentRequestStatus $s): string => $s->value, PaymentRequestStatus::open()))
            ->selectRaw('vendor_id, count(*) as total')->groupBy('vendor_id')->pluck('total', 'vendor_id')->all();
        $recentThreshold = CarbonImmutable::instance($this->clock->now())->subDays(30);

        return array_values($vendors->map(function (Vendor $vendor) use ($domains, $contacts, $accounts, $open, $recentThreshold): array {
            $id = (int) $vendor->getKey();
            $callback = $contacts->get($id)?->first();
            $vendorAccounts = $accounts->get($id) ?? collect();
            $verified = $vendorAccounts->first(static fn (VendorBankAccount $account): bool => $account->getAttribute('status') === BankAccountStatus::Verified);
            $recent = $vendorAccounts->contains(static function (VendorBankAccount $account) use ($recentThreshold, $vendorAccounts): bool {
                $from = $account->getAttribute('valid_from');

                return $account->getAttribute('status') === BankAccountStatus::Verified
                    && $from instanceof \DateTimeInterface
                    && CarbonImmutable::instance($from)->greaterThanOrEqualTo($recentThreshold)
                    && $vendorAccounts->contains(static fn (VendorBankAccount $other): bool => $other->getAttribute('status') === BankAccountStatus::Retired);
            });

            return [
                ...(Refs::vendor($vendor) ?? []),
                'legal_name' => self::str($vendor->getAttribute('legal_name')),
                'country' => self::str($vendor->getAttribute('country')),
                'domains' => array_values(($domains->get($id) ?? collect())->pluck('domain')->map(static fn (mixed $d): string => (string) $d)->all()),
                'callback_contact' => $callback instanceof VendorContact ? [
                    'name' => (string) $callback->getAttribute('name'),
                    'phone_last4' => (string) $callback->getAttribute('phone_last4'),
                    'source' => (string) Refs::enum($callback->getAttribute('source')),
                ] : null,
                'verified_account' => $verified instanceof VendorBankAccount ? self::masked($verified) : null,
                'accounts_count' => $vendorAccounts->count(),
                'open_requests' => (int) ($open[$id] ?? 0),
                'last_paid_at' => Refs::iso($vendor->getAttribute('last_paid_at')),
                'recent_change' => $recent,
            ];
        })->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Workspace $workspace, Vendor $vendor, User $viewer, bool $canReveal, bool $manage, bool $verifyBank): array
    {
        $row = $this->rows($workspace, collect([$vendor]))[0];
        $timezone = $workspace->timezone ?: 'UTC';
        $vendor->loadMissing('linkedUser:id,name');
        $contacts = VendorContact::query()->forWorkspace($workspace)->where('vendor_id', $vendor->getKey())->orderByDesc('is_callback')->orderBy('id')->get();
        $accounts = VendorBankAccount::query()->forWorkspace($workspace)->where('vendor_id', $vendor->getKey())
            ->with(['verifier:id,name,deleted_at', 'creator:id,name,deleted_at', 'verification'])->orderByDesc('created_at')->orderByDesc('id')->get();
        $requests = PaymentRequest::query()->forWorkspace($workspace)->where('vendor_id', $vendor->getKey())->orderByDesc('created_at')->limit(20)->get();
        $blocked = BlockedBankAccount::query()->forWorkspace($workspace)->withTrashed()
            ->whereIn('source_payment_request_id', PaymentRequest::query()->forWorkspace($workspace)->where('vendor_id', $vendor->getKey())->select('id'))
            ->with('sourcePaymentRequest:id,reference')->orderByDesc('created_at')->get();
        $start = CarbonImmutable::instance($this->clock->now())->setTimezone($timezone)->startOfMonth()->subMonthsNoOverflow(11);
        $history = [];

        for ($i = 0; $i < 12; $i++) {
            $history[$start->addMonthsNoOverflow($i)->format('Y-m')] = 0;
        }

        foreach (PaymentRequest::query()->forWorkspace($workspace)->where('vendor_id', $vendor->getKey())->where('status', PaymentRequestStatus::Paid->value)
            ->where('paid_at', '>=', $start->utc())->get(['paid_at', 'amount_base_minor']) as $paid) {
            $month = CarbonImmutable::instance($paid->getAttribute('paid_at'))->setTimezone($timezone)->format('Y-m');

            if (isset($history[$month])) {
                $history[$month] += (int) $paid->getAttribute('amount_base_minor');
            }
        }

        return [
            'vendor' => [
                ...$row,
                'aliases' => array_values(array_filter((array) $vendor->getAttribute('aliases'), 'is_string')),
                'tax_id' => self::str($vendor->getAttribute('tax_id')),
                'default_currency' => self::str($vendor->getAttribute('default_currency')),
                'payment_terms_days' => is_int($t = $vendor->getAttribute('payment_terms_days')) ? $t : null,
                'notes' => $manage ? self::str($vendor->getAttribute('notes')) : null,
                'linked_user' => Refs::user($vendor->linkedUser),
                'created_at' => (string) Refs::iso($vendor->getAttribute('created_at')),
            ],
            'contacts' => array_values($contacts->map(fn (VendorContact $contact): array => [
                'id' => (int) $contact->getKey(),
                'name' => (string) $contact->getAttribute('name'),
                'role_title' => self::str($contact->getAttribute('role_title')),
                'email' => self::str($contact->getAttribute('email')),
                'phone_display' => self::str($contact->getAttribute('phone_e164')) ?? Masking::phone(self::str($contact->getAttribute('phone_last4'))),
                'is_callback' => (bool) $contact->getAttribute('is_callback'),
                'status' => (string) Refs::enum($contact->getAttribute('status')),
                'source' => (string) Refs::enum($contact->getAttribute('source')),
                'source_ref' => self::str($contact->getAttribute('source_ref')),
                'verified_at' => Refs::iso($contact->getAttribute('verified_at')),
                'can_verify' => $verifyBank && $contact->getAttribute('status') === ContactStatus::PendingVerification && (int) $contact->getAttribute('created_by') !== $viewer->id,
            ])->all()),
            'domains' => array_values(VendorDomain::query()->forWorkspace($workspace)->where('vendor_id', $vendor->getKey())->orderBy('domain')->get()
                ->map(static fn (VendorDomain $domain): array => [
                    'id' => (int) $domain->getKey(),
                    'domain' => (string) $domain->getAttribute('domain'),
                    'unicode' => ($unicode = DomainNormalizer::unicode((string) $domain->getAttribute('domain'))) !== $domain->getAttribute('domain') ? $unicode : null,
                    'source' => (string) Refs::enum($domain->getAttribute('source')),
                ])->all()),
            'accounts' => array_values($accounts->map(static fn (VendorBankAccount $account): array => [
                ...self::masked($account),
                'id' => (int) $account->getKey(),
                'status' => (string) Refs::enum($account->getAttribute('status')),
                'currency' => self::str($account->getAttribute('currency')),
                'source' => (string) Refs::enum($account->getAttribute('source')),
                'valid_from' => Refs::iso($account->getAttribute('valid_from')),
                'valid_to' => Refs::iso($account->getAttribute('valid_to')),
                'verified_at' => Refs::iso($account->getAttribute('verified_at')),
                'verified_by' => Refs::user($account->verifier),
                'verification_method' => Refs::enum($account->verification?->getAttribute('method')),
                'retired_reason' => Refs::enum($account->getAttribute('retired_reason')),
                'created_by' => Refs::user($account->creator) ?? ['id' => (int) $account->getAttribute('created_by'), 'name' => '', 'avatar' => null],
                'created_at' => (string) Refs::iso($account->getAttribute('created_at')),
            ])->all()),
            'blocked_attempts' => array_values($blocked->map(static fn (BlockedBankAccount $block): array => [
                'last4' => (string) $block->getAttribute('last4'),
                'bank_country' => self::str($block->getAttribute('bank_country')),
                'created_at' => (string) Refs::iso($block->getAttribute('created_at')),
                'reason' => (string) Refs::enum($block->getAttribute('reason')),
                'request_reference' => self::str($block->sourcePaymentRequest?->getAttribute('reference')),
            ])->all()),
            'requests' => PaymentRequestRows::many($requests, $viewer),
            'amount_history' => array_map(static fn (string $month, int $amount): array => ['month' => $month, 'amount_minor' => $amount], array_keys($history), $history),
            'abilities' => ['manage' => $manage, 'verify_bank' => $verifyBank, 'reveal' => $canReveal],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function masked(VendorBankAccount $account): array
    {
        $number = $account->getAttribute('account_number');

        return Refs::maskedAccount(
            $account->getAttribute('scheme'),
            $account->getAttribute('last4'),
            is_string($number) ? $number : null,
            $account->getAttribute('bank_name'),
            $account->getAttribute('bank_country'),
            $account->getAttribute('bic'),
            $account->getAttribute('account_holder'),
        ) ?? [];
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
