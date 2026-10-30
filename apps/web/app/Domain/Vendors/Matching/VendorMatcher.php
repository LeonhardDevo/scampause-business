<?php

declare(strict_types=1);

namespace App\Domain\Vendors\Matching;

use App\Domain\Intake\Enums\VendorMatchMethod;
use App\Domain\Support\NameSimilarity;
use App\Domain\Support\Normalize\TaxId;
use App\Domain\Vendors\Enums\BankAccountStatus;
use App\Domain\Vendors\Models\BlockedBankAccount;
use App\Domain\Vendors\Models\Vendor;
use App\Domain\Vendors\Models\VendorBankAccount;
use App\Domain\Vendors\Models\VendorDomain;

final class VendorMatcher
{
    /**
     * @param  list<VendorCandidate>  $raw
     */
    public static function choose(array $raw): VendorMatch
    {
        $best = [];

        foreach ($raw as $candidate) {
            $current = $best[$candidate->vendorId] ?? null;

            if ($current === null || $candidate->beats($current)) {
                $best[$candidate->vendorId] = $candidate;
            }
        }

        $ranked = array_values($best);
        usort($ranked, static function (VendorCandidate $a, VendorCandidate $b): int {
            if ($a->beats($b)) {
                return -1;
            }

            if ($b->beats($a)) {
                return 1;
            }

            return $a->vendorId <=> $b->vendorId;
        });

        $top = $ranked[0] ?? null;
        $runnerUp = $ranked[1] ?? null;
        $config = self::config();

        if ($top === null) {
            return new VendorMatch(null, null, null, VendorMatch::WARNING_NOT_FOUND, []);
        }

        $clear = $runnerUp === null || $runnerUp->confidence < $top->confidence - $config['runner_up_margin'];

        if ($top->confidence >= $config['auto_match_min'] && $clear) {
            return new VendorMatch($top->vendorId, $top->method, $top->confidence, null, $ranked);
        }

        return new VendorMatch(
            null,
            null,
            null,
            $top->confidence >= $config['auto_match_min'] ? VendorMatch::WARNING_AMBIGUOUS : VendorMatch::WARNING_NOT_FOUND,
            $ranked,
        );
    }

    /**
     * @param  list<string>  $freeMailDomains
     */
    public function match(
        int $workspaceId,
        ?string $taxId,
        ?string $senderDomain,
        ?string $name,
        ?string $payeeFingerprint,
        array $freeMailDomains = [],
    ): VendorMatch {
        $config = self::config();
        $candidates = [];
        $normalizedTaxId = TaxId::normalize($taxId);

        if ($normalizedTaxId !== null) {
            $ids = Vendor::query()->forWorkspace($workspaceId)->where('tax_id_normalized', $normalizedTaxId)->pluck('id');

            foreach ($ids as $id) {
                $candidates[] = new VendorCandidate((int) $id, VendorMatchMethod::TaxId, $config['tax_id']);
            }
        }

        if ($senderDomain !== null && ! in_array($senderDomain, $freeMailDomains, true)) {
            $ids = VendorDomain::query()->forWorkspace($workspaceId)
                ->where('domain', $senderDomain)
                ->whereHas('vendor')
                ->pluck('vendor_id');

            foreach ($ids as $id) {
                $candidates[] = new VendorCandidate((int) $id, VendorMatchMethod::SenderDomain, $config['sender_domain']);
            }
        }

        if ($name !== null && trim($name) !== '') {
            $vendors = Vendor::query()->forWorkspace($workspaceId)->limit(5000)->get(['id', 'name', 'legal_name', 'aliases']);

            foreach ($vendors as $vendor) {
                $aliases = $vendor->getAttribute('aliases');
                $names = [(string) $vendor->getAttribute('name'), $vendor->getAttribute('legal_name'), ...(is_array($aliases) ? $aliases : [])];
                $score = NameSimilarity::best($name, array_map(static fn (mixed $value): ?string => is_string($value) ? $value : null, $names));

                if ($score >= $config['name_min']) {
                    $candidates[] = new VendorCandidate((int) $vendor->getKey(), VendorMatchMethod::Name, min($config['name_cap'], $score));
                }
            }
        }

        if ($payeeFingerprint !== null) {
            $blocked = BlockedBankAccount::query()->forWorkspace($workspaceId)->where('fingerprint', $payeeFingerprint)->exists();

            if (! $blocked) {
                $accounts = VendorBankAccount::query()->forWorkspace($workspaceId)
                    ->where('fingerprint', $payeeFingerprint)
                    ->whereIn('status', [BankAccountStatus::Verified->value, BankAccountStatus::PendingVerification->value])
                    ->whereHas('vendor')
                    ->get(['vendor_id', 'status']);

                foreach ($accounts as $account) {
                    $verified = $account->getAttribute('status') === BankAccountStatus::Verified;
                    $candidates[] = new VendorCandidate(
                        (int) $account->getAttribute('vendor_id'),
                        VendorMatchMethod::BankFingerprint,
                        $verified ? $config['bank_verified'] : $config['bank_pending'],
                    );
                }
            }
        }

        return self::choose($candidates);
    }

    /** @return array{tax_id: int, sender_domain: int, name_min: int, name_cap: int, bank_verified: int, bank_pending: int, auto_match_min: int, runner_up_margin: int} */
    private static function config(): array
    {
        /** @var array{tax_id: int, sender_domain: int, name_min: int, name_cap: int, bank_verified: int, bank_pending: int, auto_match_min: int, runner_up_margin: int} $config */
        $config = array_merge([
            'tax_id' => 95, 'sender_domain' => 90, 'name_min' => 60, 'name_cap' => 85,
            'bank_verified' => 80, 'bank_pending' => 60, 'auto_match_min' => 70, 'runner_up_margin' => 15,
        ], (array) config('scampause.vendor_matching', []));

        return $config;
    }
}
