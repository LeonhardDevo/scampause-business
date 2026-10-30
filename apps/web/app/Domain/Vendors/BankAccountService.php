<?php

declare(strict_types=1);

namespace App\Domain\Vendors;

use App\Audit\Auditor;
use App\Domain\Evidence\Enums\EvidenceChannel;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\PayeeWriter;
use App\Domain\Support\Fingerprinter;
use App\Domain\Support\Normalize\BankNormalizer;
use App\Domain\Vendors\Enums\BankAccountSource;
use App\Domain\Vendors\Enums\BankAccountStatus;
use App\Domain\Vendors\Enums\BankScheme;
use App\Domain\Vendors\Enums\RetiredReason;
use App\Domain\Vendors\Models\BlockedBankAccount;
use App\Domain\Vendors\Models\Vendor;
use App\Domain\Vendors\Models\VendorBankAccount;
use App\Domain\Verification\Models\Verification;
use App\Domain\Workflow\ReassessmentService;
use App\Domain\Workflow\WorkflowException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

final readonly class BankAccountService
{
    public function __construct(
        private EvidenceLedger $ledger,
        private Auditor $auditor,
        private ReassessmentService $reassessment,
        private ClockInterface $clock,
    ) {}

    /**
     * @param  array{scheme: BankScheme, account_number: string, routing_code?: string|null, bic?: string|null, account_holder?: string|null, bank_name?: string|null, bank_country?: string|null, currency?: string|null, correspondent_account?: string|null}  $data
     */
    public function add(Vendor $vendor, User $actor, array $data, BankAccountSource $source = BankAccountSource::Manual): VendorBankAccount
    {
        return DB::transaction(function () use ($vendor, $actor, $data, $source): VendorBankAccount {
            $workspaceId = (int) $vendor->getAttribute('workspace_id');
            $attributes = PayeeWriter::attributes(
                $workspaceId,
                $data['scheme'],
                $data['account_number'],
                $data['routing_code'] ?? null,
                $data['bic'] ?? null,
                $data['account_holder'] ?? null,
                $data['bank_name'] ?? null,
                $data['bank_country'] ?? null,
                $data['correspondent_account'] ?? null,
            );
            $fingerprint = $attributes['payee_fingerprint'];
            $last4 = $attributes['payee_last4'];

            if (! is_string($fingerprint) || ! is_string($last4)) {
                throw new WorkflowException('invalid_account', 'account_number');
            }

            if (BlockedBankAccount::query()->forWorkspace($workspaceId)->where('fingerprint', $fingerprint)->exists()) {
                throw new WorkflowException('account_blocklisted', 'account_number');
            }

            $existing = VendorBankAccount::query()->forWorkspace($workspaceId)->where('vendor_id', $vendor->getKey())->where('fingerprint', $fingerprint)->lockForUpdate()->first();

            if ($existing !== null) {
                $status = $existing->getAttribute('status');

                if (! in_array($status, [BankAccountStatus::Retired, BankAccountStatus::Rejected], true)) {
                    throw new WorkflowException('already_exists', 'account_number');
                }

                $existing->forceFill(['status' => BankAccountStatus::PendingVerification, 'valid_to' => null, 'retired_reason' => null, 'verified_at' => null, 'verified_by' => null, 'verification_id' => null, 'valid_from' => null, 'created_by' => $actor->id])->save();
                $this->auditor->record('vendor.bank_account_reactivated', $existing, ['status' => [$status->value, 'pending_verification']], actor: $actor, workspace: $workspaceId, subjectLabel: '•••• '.$existing->getAttribute('last4'));
                $this->reassessment->forVendorAfterCommit($workspaceId, (int) $vendor->getKey());

                return $existing;
            }

            $account = new VendorBankAccount;
            $account->forceFill([
                'workspace_id' => $workspaceId,
                'vendor_id' => $vendor->getKey(),
                'scheme' => $data['scheme'],
                'account_holder' => $attributes['payee_account_holder'],
                'account_number' => $attributes['payee_account_number'],
                'routing_code' => $attributes['payee_routing_code'],
                'correspondent_account' => $attributes['payee_correspondent_account'],
                'bic' => $attributes['payee_bic'],
                'bank_name' => $attributes['payee_bank_name'],
                'bank_country' => $attributes['payee_bank_country'],
                'currency' => isset($data['currency']) ? strtoupper($data['currency']) : null,
                'fingerprint' => $fingerprint,
                'last4' => $last4,
                'status' => BankAccountStatus::PendingVerification,
                'source' => $source,
                'created_by' => $actor->id,
            ])->save();

            $this->ledger->append(
                workspaceId: $workspaceId,
                event: 'bank_account.added',
                subjectType: 'vendor_bank_account',
                subjectId: (int) $account->getKey(),
                payload: ['vendor_id' => (int) $vendor->getKey(), 'last4' => $last4, 'scheme' => $data['scheme']->value],
                actorUserId: $actor->id,
            );
            $this->auditor->record('vendor.bank_account_added', $account, metadata: ['vendor_id' => $vendor->getKey(), 'last4' => $last4], actor: $actor, workspace: $workspaceId, subjectLabel: (string) $vendor->getAttribute('name'));
            $this->reassessment->forVendorAfterCommit($workspaceId, (int) $vendor->getKey());

            return $account;
        });
    }

    public function retire(VendorBankAccount $subject, User $actor, RetiredReason $reason): VendorBankAccount
    {
        return DB::transaction(function () use ($subject, $actor, $reason): VendorBankAccount {
            $workspaceId = (int) $subject->getAttribute('workspace_id');
            $account = VendorBankAccount::query()->forWorkspace($workspaceId)->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
            $status = $account->getAttribute('status');
            $allowed = $status === BankAccountStatus::Verified
                || ($status === BankAccountStatus::PendingVerification && $reason === RetiredReason::EnteredInError);

            if (! $allowed) {
                throw new WorkflowException('invalid_account_transition', 'reason');
            }

            $this->retireLocked($account, $reason, $actor, EvidenceChannel::Web);
            $this->reassessment->forVendorAfterCommit($workspaceId, (int) $account->getAttribute('vendor_id'));

            return $account;
        });
    }

    public function retireLocked(VendorBankAccount $account, RetiredReason $reason, ?User $actor, EvidenceChannel $channel, ?int $paymentRequestId = null): void
    {
        $workspaceId = (int) $account->getAttribute('workspace_id');
        $account->forceFill([
            'status' => BankAccountStatus::Retired,
            'retired_reason' => $reason,
            'valid_to' => CarbonImmutable::instance($this->clock->now()),
        ])->save();

        $this->ledger->append(
            workspaceId: $workspaceId,
            event: 'bank_account.retired',
            subjectType: 'vendor_bank_account',
            subjectId: (int) $account->getKey(),
            payload: ['reason' => $reason->value],
            actorUserId: $actor?->id,
            channel: $channel,
            paymentRequestId: $paymentRequestId,
        );
        $this->auditor->record('vendor.bank_account_retired', $account, metadata: ['reason' => $reason->value], actor: $actor, workspace: $workspaceId, subjectLabel: '•••• '.$account->getAttribute('last4'));
    }

    public function markVerified(VendorBankAccount $account, Verification $verification, User $actor, bool $sodWaived, EvidenceChannel $channel, ?int $paymentRequestId = null): void
    {
        $now = CarbonImmutable::instance($this->clock->now());
        $account->forceFill([
            'status' => BankAccountStatus::Verified,
            'verification_id' => $verification->getKey(),
            'verified_at' => $now,
            'verified_by' => $actor->id,
            'valid_from' => $now,
            'valid_to' => null,
            'retired_reason' => null,
            'sod_waived' => $sodWaived,
        ])->save();

        $this->ledger->append(
            workspaceId: (int) $account->getAttribute('workspace_id'),
            event: 'bank_account.verified',
            subjectType: 'vendor_bank_account',
            subjectId: (int) $account->getKey(),
            payload: ['vendor_id' => (int) $account->getAttribute('vendor_id'), 'last4' => (string) $account->getAttribute('last4'), 'verification_id' => (int) $verification->getKey()],
            actorUserId: $actor->id,
            channel: $channel,
            paymentRequestId: $paymentRequestId,
        );
        $this->auditor->record('vendor.bank_account_verified', $account, ['status' => ['pending_verification', 'verified']], actor: $actor, workspace: (int) $account->getAttribute('workspace_id'), subjectLabel: '•••• '.$account->getAttribute('last4'));
    }

    public function upsertFromApprovedRequest(PaymentRequest $request, User $actor, EvidenceChannel $channel): ?VendorBankAccount
    {
        $workspaceId = (int) $request->getAttribute('workspace_id');
        $vendorId = $request->getAttribute('vendor_id');
        $fingerprint = $request->getAttribute('payee_fingerprint');
        $verificationId = $request->getAttribute('satisfying_verification_id');
        $scheme = $request->getAttribute('payee_scheme');
        $accountNumber = $request->getAttribute('payee_account_number');

        if (! is_int($vendorId) || ! is_string($fingerprint) || ! is_int($verificationId) || ! $scheme instanceof BankScheme || ! is_string($accountNumber)) {
            return null;
        }

        $verification = Verification::query()->forWorkspace($workspaceId)->findOrFail($verificationId);
        $account = VendorBankAccount::query()->forWorkspace($workspaceId)->where('vendor_id', $vendorId)->where('fingerprint', $fingerprint)->lockForUpdate()->first();

        if ($account === null) {
            $account = new VendorBankAccount;
            $account->forceFill([
                'workspace_id' => $workspaceId,
                'vendor_id' => $vendorId,
                'scheme' => $scheme,
                'account_holder' => $request->getAttribute('payee_account_holder'),
                'account_number' => $accountNumber,
                'routing_code' => $request->getAttribute('payee_routing_code'),
                'correspondent_account' => $request->getAttribute('payee_correspondent_account'),
                'bic' => $request->getAttribute('payee_bic'),
                'bank_name' => $request->getAttribute('payee_bank_name'),
                'bank_country' => $request->getAttribute('payee_bank_country'),
                'currency' => $request->getAttribute('currency'),
                'fingerprint' => $fingerprint,
                'last4' => (string) $request->getAttribute('payee_last4'),
                'status' => BankAccountStatus::PendingVerification,
                'source' => BankAccountSource::Intake,
                'source_payment_request_id' => $request->getKey(),
                'created_by' => (int) $request->getAttribute('requested_by'),
            ])->save();

            $this->ledger->append(
                workspaceId: $workspaceId,
                event: 'bank_account.added',
                subjectType: 'vendor_bank_account',
                subjectId: (int) $account->getKey(),
                payload: ['vendor_id' => $vendorId, 'last4' => (string) $account->getAttribute('last4'), 'scheme' => $scheme->value],
                actorUserId: $actor->id,
                channel: $channel,
                paymentRequestId: (int) $request->getKey(),
            );
        }

        if ($account->getAttribute('status') !== BankAccountStatus::Verified) {
            $this->markVerified($account, $verification, $actor, (bool) $verification->getAttribute('sod_waived'), $channel, (int) $request->getKey());
        }

        $replaces = $verification->getAttribute('replaces_account_id');

        if (is_int($replaces) && $replaces !== (int) $account->getKey()) {
            $old = VendorBankAccount::query()->forWorkspace($workspaceId)->whereKey($replaces)->lockForUpdate()->first();

            if ($old !== null && $old->getAttribute('status') === BankAccountStatus::Verified) {
                $this->retireLocked($old, RetiredReason::Replaced, $actor, $channel, (int) $request->getKey());
            }
        }

        $request->forceFill(['matched_bank_account_id' => $account->getKey()])->save();

        return $account;
    }

    /**
     * @return array{holder: string|null, account: string, routing: string|null, bic: string|null}
     */
    public static function revealed(?string $holder, string $account, ?string $routing, ?string $bic, BankScheme $scheme): array
    {
        return [
            'holder' => $holder,
            'account' => BankNormalizer::account($scheme, $account),
            'routing' => $routing,
            'bic' => $bic,
        ];
    }

    /**
     * @return list<array{text: string, changed: bool}>
     */
    public static function charDiff(string $previous, string $incoming): array
    {
        $out = [];
        $length = strlen($incoming);
        $offset = max(0, strlen($previous) - $length);

        for ($i = 0; $i < $length; $i++) {
            $changed = ($previous[$i + $offset] ?? null) !== $incoming[$i];
            $last = count($out) - 1;

            if ($last >= 0 && $out[$last]['changed'] === $changed) {
                $out[$last]['text'] .= $incoming[$i];
            } else {
                $out[] = ['text' => $incoming[$i], 'changed' => $changed];
            }
        }

        return $out;
    }

    public static function fingerprint(int $workspaceId, BankScheme $scheme, string $account, ?string $routing): string
    {
        return Fingerprinter::fromConfig()->bank($workspaceId, $scheme, $account, $routing);
    }
}
