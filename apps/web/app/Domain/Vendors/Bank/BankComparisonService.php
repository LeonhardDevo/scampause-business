<?php

declare(strict_types=1);

namespace App\Domain\Vendors\Bank;

use App\Domain\Intake\Models\BankComparison;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Support\Normalize\BankNormalizer;
use App\Domain\Vendors\Enums\BankAccountStatus;
use App\Domain\Vendors\Enums\BankScheme;
use App\Domain\Vendors\Models\BlockedBankAccount;
use App\Domain\Vendors\Models\Vendor;
use App\Domain\Vendors\Models\VendorBankAccount;
use Carbon\CarbonImmutable;
use Psr\Clock\ClockInterface;

final readonly class BankComparisonService
{
    public function __construct(private ClockInterface $clock) {}

    public function compare(PaymentRequest $request): ComparisonResult
    {
        $workspaceId = (int) $request->getAttribute('workspace_id');
        $payee = self::payeeOf($request);
        $vendorId = $request->getAttribute('vendor_id');
        $vendorId = is_int($vendorId) ? $vendorId : null;
        $blocklisted = $payee !== null && BlockedBankAccount::query()->forWorkspace($workspaceId)
            ->where('fingerprint', $payee->fingerprint)
            ->exists();

        $vendorAccounts = [];
        $vendorNames = [];

        if ($vendorId !== null) {
            $vendor = Vendor::query()->forWorkspace($workspaceId)->withTrashed()->find($vendorId);

            if ($vendor !== null) {
                $aliases = $vendor->getAttribute('aliases');
                $vendorNames = [
                    (string) $vendor->getAttribute('name'),
                    is_string($legal = $vendor->getAttribute('legal_name')) ? $legal : null,
                    ...array_values(array_filter(is_array($aliases) ? $aliases : [], 'is_string')),
                ];
            }

            $vendorAccounts = VendorBankAccount::query()->forWorkspace($workspaceId)
                ->where('vendor_id', $vendorId)
                ->orderBy('id')
                ->get()
                ->map(static fn (VendorBankAccount $account): AccountSnapshot => self::snapshot($account))
                ->all();
        }

        $otherVerified = [];

        if ($payee !== null) {
            $others = VendorBankAccount::query()->forWorkspace($workspaceId)
                ->where('fingerprint', $payee->fingerprint)
                ->where('status', BankAccountStatus::Verified->value)
                ->when($vendorId !== null, fn ($query) => $query->where('vendor_id', '<>', $vendorId))
                ->whereHas('vendor')
                ->orderBy('id')
                ->get(['vendor_id', 'fingerprint']);

            foreach ($others as $other) {
                $otherVerified[(string) $other->getAttribute('fingerprint')] ??= (int) $other->getAttribute('vendor_id');
            }
        }

        return BankDetailsComparator::compare(
            $payee,
            $blocklisted,
            $vendorId,
            array_values($vendorAccounts),
            $otherVerified,
            $vendorNames,
            CarbonImmutable::instance($this->clock->now()),
        );
    }

    public function compareAndStore(PaymentRequest $request): BankComparison
    {
        $result = $this->compare($request);

        $comparison = BankComparison::query()->forWorkspace((int) $request->getAttribute('workspace_id'))->firstOrNew([
            'payment_request_id' => $request->getKey(),
            'revision' => (int) $request->getAttribute('revision'),
        ]);
        $comparison->forceFill([
            'workspace_id' => $request->getAttribute('workspace_id'),
            'outcome' => $result->outcome,
            'previous_account_id' => $result->previousAccountId,
            'details' => $result->details,
        ])->save();

        $request->forceFill(['matched_bank_account_id' => $result->matchedAccountId]);

        return $comparison;
    }

    public static function payeeOf(PaymentRequest $request): ?PayeeSnapshot
    {
        $account = $request->getAttribute('payee_account_number');
        $fingerprint = $request->getAttribute('payee_fingerprint');
        $scheme = $request->getAttribute('payee_scheme');

        if (! is_string($account) || $account === '' || ! is_string($fingerprint) || ! $scheme instanceof BankScheme) {
            return null;
        }

        $routing = $request->getAttribute('payee_routing_code');
        $holder = $request->getAttribute('payee_account_holder');
        $bic = $request->getAttribute('payee_bic');
        $country = $request->getAttribute('payee_bank_country');
        $normalized = BankNormalizer::account($scheme, $account);

        return new PayeeSnapshot(
            $fingerprint,
            $scheme,
            $normalized,
            is_string($routing) ? BankNormalizer::routing($scheme, $routing) : null,
            is_string($bic) ? $bic : null,
            is_string($country) ? $country : null,
            is_string($holder) ? $holder : null,
            BankNormalizer::last4($normalized),
        );
    }

    public static function snapshot(VendorBankAccount $account): AccountSnapshot
    {
        /** @var BankScheme $scheme */
        $scheme = $account->getAttribute('scheme');
        /** @var BankAccountStatus $status */
        $status = $account->getAttribute('status');
        $routing = $account->getAttribute('routing_code');
        $bic = $account->getAttribute('bic');
        $country = $account->getAttribute('bank_country');
        $validFrom = $account->getAttribute('valid_from');

        return new AccountSnapshot(
            (int) $account->getKey(),
            (string) $account->getAttribute('fingerprint'),
            $status,
            $scheme,
            BankNormalizer::account($scheme, (string) $account->getAttribute('account_number')),
            is_string($routing) ? BankNormalizer::routing($scheme, $routing) : null,
            is_string($bic) ? $bic : null,
            is_string($country) ? $country : null,
            (string) $account->getAttribute('last4'),
            $validFrom instanceof \DateTimeInterface ? CarbonImmutable::instance($validFrom) : null,
        );
    }
}
