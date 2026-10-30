<?php

declare(strict_types=1);

namespace App\Domain\Vendors;

use App\Audit\Auditor;
use App\Domain\Evidence\Enums\EvidenceChannel;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Intake\Enums\DecisionReason;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Notifications\DomainNotifier;
use App\Domain\Support\Fingerprinter;
use App\Domain\Support\Normalize\BankNormalizer;
use App\Domain\Vendors\Enums\BankAccountStatus;
use App\Domain\Vendors\Enums\BankScheme;
use App\Domain\Vendors\Enums\BlockReason;
use App\Domain\Vendors\Models\BlockedBankAccount;
use App\Domain\Vendors\Models\VendorBankAccount;
use App\Domain\Workflow\GovernanceRecorder;
use App\Domain\Workflow\WorkflowException;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

final readonly class BlocklistService
{
    public function __construct(
        private EvidenceLedger $ledger,
        private Auditor $auditor,
        private DomainNotifier $notifier,
        private GovernanceRecorder $governance,
    ) {}

    public function blockFromDecision(PaymentRequest $request, DecisionReason $reason, ?User $actor, EvidenceChannel $channel = EvidenceChannel::Web): ?BlockedBankAccount
    {
        if (! in_array($reason, [DecisionReason::FraudConfirmed, DecisionReason::DetailsMismatch], true)) {
            return null;
        }

        $fingerprint = $request->getAttribute('payee_fingerprint');
        $workspaceId = (int) $request->getAttribute('workspace_id');

        if (! is_string($fingerprint) || $fingerprint === '') {
            return null;
        }

        $vendorId = $request->getAttribute('vendor_id');
        $verifiedForVendor = is_int($vendorId) && VendorBankAccount::query()->forWorkspace($workspaceId)
            ->where('vendor_id', $vendorId)
            ->where('fingerprint', $fingerprint)
            ->where('status', BankAccountStatus::Verified->value)
            ->exists();

        if ($verifiedForVendor) {
            $this->ledger->append(
                workspaceId: $workspaceId,
                event: 'block.skipped_verified_account',
                subjectType: 'payment_request',
                subjectId: (int) $request->getKey(),
                payload: ['last4' => (string) $request->getAttribute('payee_last4'), 'reason' => $reason->value],
                actorUserId: $actor?->id,
                channel: $channel,
                paymentRequestId: (int) $request->getKey(),
            );
            $this->notifier->notify('block_skipped', DomainNotifier::membersWithRoles($workspaceId, [WorkspaceRole::Owner, WorkspaceRole::Admin]), $workspaceId, $request, [DomainNotifier::DATABASE], [], 'warning');

            return null;
        }

        VendorBankAccount::query()->forWorkspace($workspaceId)
            ->where('fingerprint', $fingerprint)
            ->where('status', BankAccountStatus::PendingVerification->value)
            ->update(['status' => BankAccountStatus::Rejected->value, 'updated_at' => now()]);

        $existing = BlockedBankAccount::query()->forWorkspace($workspaceId)->where('fingerprint', $fingerprint)->first();

        if ($existing !== null) {
            return $existing;
        }

        $scheme = $request->getAttribute('payee_scheme');

        return $this->create(
            $workspaceId,
            $fingerprint,
            $scheme instanceof BankScheme ? $scheme : BankScheme::Other,
            (string) $request->getAttribute('payee_last4'),
            is_string($n = $request->getAttribute('payee_bank_name')) ? $n : null,
            is_string($c = $request->getAttribute('payee_bank_country')) ? $c : null,
            $reason === DecisionReason::FraudConfirmed ? BlockReason::FraudConfirmed : BlockReason::DetailsMismatch,
            (int) $request->getKey(),
            null,
            $actor,
            $channel,
        );
    }

    public function addManual(Workspace $workspace, User $actor, BankScheme $scheme, string $account, ?string $routing, ?string $note): BlockedBankAccount
    {
        $normalized = BankNormalizer::account($scheme, $account);
        $fingerprint = Fingerprinter::fromConfig()->bank($workspace->id, $scheme, $normalized, $routing);

        if (BlockedBankAccount::query()->forWorkspace($workspace)->where('fingerprint', $fingerprint)->exists()) {
            throw new WorkflowException('already_blocked', 'account_number');
        }

        return $this->create($workspace->id, $fingerprint, $scheme, BankNormalizer::last4($normalized), null, null, BlockReason::Manual, null, $note, $actor, EvidenceChannel::Web);
    }

    public function unblock(BlockedBankAccount $blocked, User $actor, string $reason): void
    {
        $workspace = Workspace::query()->findOrFail((int) $blocked->getAttribute('workspace_id'));
        $blocked->forceFill(['unblocked_by' => $actor->id, 'unblock_reason' => mb_substr($reason, 0, 255, 'UTF-8')])->save();
        $blocked->delete();

        $this->governance->record($workspace, 'block.removed', 'blocked_bank_account', (int) $blocked->getKey(), [
            'last4' => (string) $blocked->getAttribute('last4'),
            'reason' => 'manual',
        ], $actor, '/blocked-accounts');
        $this->auditor->record('blocked_account.removed', $blocked, metadata: ['last4' => (string) $blocked->getAttribute('last4')], actor: $actor, workspace: $workspace, subjectLabel: '•••• '.$blocked->getAttribute('last4'));
    }

    private function create(
        int $workspaceId,
        string $fingerprint,
        BankScheme $scheme,
        string $last4,
        ?string $bankName,
        ?string $bankCountry,
        BlockReason $reason,
        ?int $requestId,
        ?string $note,
        ?User $actor,
        EvidenceChannel $channel,
    ): BlockedBankAccount {
        $blocked = new BlockedBankAccount;
        $blocked->forceFill([
            'workspace_id' => $workspaceId,
            'fingerprint' => $fingerprint,
            'scheme' => $scheme,
            'last4' => $last4,
            'bank_name' => $bankName,
            'bank_country' => $bankCountry,
            'reason' => $reason,
            'source_payment_request_id' => $requestId,
            'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 1000, 'UTF-8') : null,
            'created_by' => $actor !== null ? $actor->id : $this->fallbackActor($workspaceId, $requestId),
        ])->save();

        $this->ledger->append(
            workspaceId: $workspaceId,
            event: 'block.added',
            subjectType: 'blocked_bank_account',
            subjectId: (int) $blocked->getKey(),
            payload: ['last4' => $last4, 'reason' => $reason->value],
            actorUserId: $actor?->id,
            channel: $channel,
            paymentRequestId: $requestId,
        );
        $this->auditor->record('blocked_account.added', $blocked, metadata: ['last4' => $last4, 'reason' => $reason->value], actor: $actor, workspace: $workspaceId, subjectLabel: '•••• '.$last4);

        return $blocked;
    }

    private function fallbackActor(int $workspaceId, ?int $requestId): int
    {
        if ($requestId !== null) {
            $requester = PaymentRequest::query()->forWorkspace($workspaceId)->whereKey($requestId)->value('requested_by');

            if (is_numeric($requester)) {
                return (int) $requester;
            }
        }

        return (int) Workspace::query()->whereKey($workspaceId)->value('created_by');
    }
}
