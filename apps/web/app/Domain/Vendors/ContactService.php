<?php

declare(strict_types=1);

namespace App\Domain\Vendors;

use App\Audit\Auditor;
use App\Domain\Approvals\ApproverPool;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Models\Extraction;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Notifications\DomainNotifier;
use App\Domain\Policy\PaymentSettings;
use App\Domain\Support\Fingerprinter;
use App\Domain\Support\Normalize\PhoneNormalizer;
use App\Domain\Vendors\Enums\ContactSource;
use App\Domain\Vendors\Enums\ContactStatus;
use App\Domain\Vendors\Models\Vendor;
use App\Domain\Vendors\Models\VendorContact;
use App\Domain\Workflow\ReassessmentService;
use App\Domain\Workflow\WorkflowException;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

final readonly class ContactService
{
    public function __construct(
        private EvidenceLedger $ledger,
        private Auditor $auditor,
        private DomainNotifier $notifier,
        private ReassessmentService $reassessment,
        private ApproverPool $pool,
        private ClockInterface $clock,
    ) {}

    /**
     * @param  array{name: string, role_title?: string|null, email?: string|null, phone?: string|null, is_callback: bool, source: ContactSource, source_ref?: string|null}  $data
     */
    public function create(Vendor $vendor, User $actor, array $data): VendorContact
    {
        return DB::transaction(function () use ($vendor, $actor, $data): VendorContact {
            $workspace = Workspace::query()->findOrFail((int) $vendor->getAttribute('workspace_id'));
            $phone = $this->phone($workspace, $data['phone'] ?? null);
            $this->guardCallback($workspace, $vendor, $data, $phone);
            $contact = $this->insert($workspace, $vendor, $actor, $data, $phone);
            $this->auditor->record('vendor.contact_created', $contact, metadata: ['is_callback' => $data['is_callback'], 'phone_last4' => $contact->getAttribute('phone_last4')], actor: $actor, workspace: $workspace, subjectLabel: (string) $vendor->getAttribute('name'));

            if ($data['is_callback']) {
                $this->callbackSet($workspace, $vendor, $contact, $actor);
            }

            return $contact;
        });
    }

    /**
     * @param  array{name: string, role_title?: string|null, email?: string|null, phone?: string|null, is_callback: bool, source: ContactSource, source_ref?: string|null}  $data
     */
    public function update(VendorContact $subject, User $actor, array $data): VendorContact
    {
        return DB::transaction(function () use ($subject, $actor, $data): VendorContact {
            $workspace = Workspace::query()->findOrFail((int) $subject->getAttribute('workspace_id'));
            $contact = VendorContact::query()->forWorkspace($workspace)->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
            $vendor = Vendor::query()->forWorkspace($workspace)->findOrFail((int) $contact->getAttribute('vendor_id'));
            $phone = $this->phone($workspace, $data['phone'] ?? null);
            $this->guardCallback($workspace, $vendor, $data, $phone);
            $oldPhone = $contact->getAttribute('phone_e164');
            $phoneChanged = $phone !== (is_string($oldPhone) ? $oldPhone : null);
            $wasVerified = $contact->getAttribute('status') === ContactStatus::Verified;

            if ($phoneChanged && $wasVerified && (bool) $contact->getAttribute('is_callback')) {
                $fresh = $this->insert($workspace, $vendor, $actor, $data, $phone);
                $this->ledger->append(
                    workspaceId: $workspace->id,
                    event: 'contact.phone_changed',
                    subjectType: 'vendor_contact',
                    subjectId: (int) $fresh->getKey(),
                    payload: [
                        'vendor_id' => (int) $vendor->getKey(),
                        'contact_id' => (int) $fresh->getKey(),
                        'last4_before' => is_string($l = $contact->getAttribute('phone_last4')) ? $l : null,
                        'last4_after' => is_string($l2 = $fresh->getAttribute('phone_last4')) ? $l2 : null,
                    ],
                    actorUserId: $actor->id,
                );
                $this->auditor->record('vendor.contact_phone_changed', $fresh, ['phone_last4' => [$contact->getAttribute('phone_last4'), $fresh->getAttribute('phone_last4')]], actor: $actor, workspace: $workspace, subjectLabel: (string) $vendor->getAttribute('name'));
                $this->notifyOwners($workspace, $vendor, $actor);
                $this->reassessment->forVendorAfterCommit($workspace->id, (int) $vendor->getKey());

                return $fresh;
            }

            $becomesCallback = $data['is_callback'] && ! (bool) $contact->getAttribute('is_callback');
            $now = CarbonImmutable::instance($this->clock->now());
            $contact->forceFill([
                'name' => mb_substr(trim($data['name']), 0, 160, 'UTF-8'),
                'role_title' => self::clean($data['role_title'] ?? null, 120),
                'email' => self::email($data['email'] ?? null),
                'is_callback' => $data['is_callback'],
                'source' => $data['source'],
                'source_ref' => self::clean($data['source_ref'] ?? null, 255),
            ]);

            if ($phoneChanged) {
                $contact->forceFill([
                    'phone_e164' => $phone,
                    'phone_hash' => $phone !== null ? Fingerprinter::fromConfig()->phone($workspace->id, $phone) : null,
                    'phone_last4' => $phone !== null ? PhoneNormalizer::last4($phone) : null,
                    'phone_changed_at' => $now,
                ]);
            }

            if (($phoneChanged || $becomesCallback) && $wasVerified) {
                $contact->forceFill(['status' => ContactStatus::PendingVerification, 'verified_at' => null, 'verified_by' => null]);
            }

            $contact->save();
            $this->auditor->record('vendor.contact_updated', $contact, metadata: ['is_callback' => $data['is_callback']], actor: $actor, workspace: $workspace, subjectLabel: (string) $vendor->getAttribute('name'));

            if ($becomesCallback || ($phoneChanged && $data['is_callback'])) {
                $this->callbackSet($workspace, $vendor, $contact, $actor);
            }

            return $contact;
        });
    }

    public function retire(VendorContact $contact, User $actor): void
    {
        DB::transaction(function () use ($contact, $actor): void {
            $workspaceId = (int) $contact->getAttribute('workspace_id');
            $contact->forceFill(['status' => ContactStatus::Retired])->save();
            $contact->delete();
            $this->auditor->record('vendor.contact_retired', $contact, actor: $actor, workspace: $workspaceId, subjectLabel: (string) $contact->getAttribute('name'));
            $this->reassessment->forVendorAfterCommit($workspaceId, (int) $contact->getAttribute('vendor_id'));
        });
    }

    public function verify(VendorContact $subject, User $actor): VendorContact
    {
        return DB::transaction(function () use ($subject, $actor): VendorContact {
            $workspace = Workspace::query()->findOrFail((int) $subject->getAttribute('workspace_id'));
            $contact = VendorContact::query()->forWorkspace($workspace)->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();

            if ($contact->getAttribute('status') !== ContactStatus::PendingVerification) {
                throw new WorkflowException('contact_not_pending', 'contact');
            }

            $solo = $this->pool->soloMode($workspace);

            if ((int) $contact->getAttribute('created_by') === $actor->id && ! $solo) {
                throw new WorkflowException('four_eyes_required', 'contact');
            }

            $now = CarbonImmutable::instance($this->clock->now());
            $audit = $this->auditor->record('vendor.contact_verified', $contact, ['status' => ['pending_verification', 'verified']], actor: $actor, workspace: $workspace, subjectLabel: (string) $contact->getAttribute('name'));
            $contact->forceFill([
                'status' => ContactStatus::Verified,
                'verified_at' => $now,
                'verified_by' => (int) $contact->getAttribute('created_by') === $actor->id ? null : $actor->id,
                'verification_note_id' => $audit->getKey(),
            ])->save();

            $this->ledger->append(
                workspaceId: $workspace->id,
                event: 'contact.verified',
                subjectType: 'vendor_contact',
                subjectId: (int) $contact->getKey(),
                payload: [
                    'vendor_id' => (int) $contact->getAttribute('vendor_id'),
                    'contact_id' => (int) $contact->getKey(),
                    'phone_last4' => is_string($l = $contact->getAttribute('phone_last4')) ? $l : null,
                    'sod_waived' => (int) $contact->getAttribute('created_by') === $actor->id,
                ],
                actorUserId: $actor->id,
            );
            $this->reassessment->forVendorAfterCommit($workspace->id, (int) $contact->getAttribute('vendor_id'));

            return $contact;
        });
    }

    /**
     * @return list<string>
     */
    public static function suspiciousPhones(int $workspaceId, int $vendorId): array
    {
        $requestIds = PaymentRequest::query()->forWorkspace($workspaceId)
            ->where('vendor_id', $vendorId)
            ->whereIn('status', array_map(static fn (PaymentRequestStatus $status): string => $status->value, PaymentRequestStatus::open()))
            ->pluck('id')
            ->all();
        $phones = [];

        if ($requestIds === []) {
            return [];
        }

        foreach (Extraction::query()->forWorkspace($workspaceId)->whereIn('payment_request_id', $requestIds)->get() as $extraction) {
            $fields = $extraction->getAttribute('fields');

            foreach (is_array($fields) && is_array($fields['phones_e164'] ?? null) ? $fields['phones_e164'] : [] as $phone) {
                if (is_string($phone)) {
                    $phones[] = $phone;
                }
            }
        }

        return array_values(array_unique($phones));
    }

    /**
     * @param  array{is_callback: bool, source: ContactSource, source_ref?: string|null}  $data
     */
    private function guardCallback(Workspace $workspace, Vendor $vendor, array $data, ?string $phone): void
    {
        if (! $data['is_callback']) {
            return;
        }

        if ($data['source'] === ContactSource::InvoiceEmail) {
            throw new WorkflowException('callback_source_invoice_email', 'source');
        }

        if ($phone === null) {
            throw new WorkflowException('callback_phone_required', 'phone');
        }

        if (self::clean($data['source_ref'] ?? null, 255) === null) {
            throw new WorkflowException('callback_source_ref_required', 'source_ref');
        }

        if (in_array($phone, self::suspiciousPhones($workspace->id, (int) $vendor->getKey()), true)) {
            throw new WorkflowException('callback_from_suspicious_message', 'phone');
        }
    }

    /**
     * @param  array{name: string, role_title?: string|null, email?: string|null, phone?: string|null, is_callback: bool, source: ContactSource, source_ref?: string|null}  $data
     */
    private function insert(Workspace $workspace, Vendor $vendor, User $actor, array $data, ?string $phone): VendorContact
    {
        $contact = new VendorContact;
        $contact->forceFill([
            'workspace_id' => $workspace->id,
            'vendor_id' => $vendor->getKey(),
            'name' => mb_substr(trim($data['name']), 0, 160, 'UTF-8'),
            'role_title' => self::clean($data['role_title'] ?? null, 120),
            'email' => self::email($data['email'] ?? null),
            'phone_e164' => $phone,
            'phone_hash' => $phone !== null ? Fingerprinter::fromConfig()->phone($workspace->id, $phone) : null,
            'phone_last4' => $phone !== null ? PhoneNormalizer::last4($phone) : null,
            'is_callback' => $data['is_callback'],
            'status' => ContactStatus::PendingVerification,
            'created_by' => $actor->id,
            'phone_changed_at' => CarbonImmutable::instance($this->clock->now()),
            'source' => $data['source'],
            'source_ref' => self::clean($data['source_ref'] ?? null, 255),
        ])->save();

        return $contact;
    }

    private function callbackSet(Workspace $workspace, Vendor $vendor, VendorContact $contact, User $actor): void
    {
        $this->ledger->append(
            workspaceId: $workspace->id,
            event: 'contact.callback_set',
            subjectType: 'vendor_contact',
            subjectId: (int) $contact->getKey(),
            payload: [
                'vendor_id' => (int) $vendor->getKey(),
                'phone_last4' => is_string($l = $contact->getAttribute('phone_last4')) ? $l : null,
                'source' => $contact->getAttribute('source') instanceof ContactSource ? $contact->getAttribute('source')->value : null,
            ],
            actorUserId: $actor->id,
        );
        $this->notifyOwners($workspace, $vendor, $actor);
        $this->reassessment->forVendorAfterCommit($workspace->id, (int) $vendor->getKey());
    }

    private function notifyOwners(Workspace $workspace, Vendor $vendor, User $actor): void
    {
        $owners = array_values(array_filter(DomainNotifier::membersWithRoles($workspace, [WorkspaceRole::Owner]), static fn (User $owner): bool => $owner->id !== $actor->id));
        $this->notifier->notify('callback_number_changed', $owners, $workspace->id, null, [DomainNotifier::DATABASE, DomainNotifier::TELEGRAM], [
            'vendor' => (string) $vendor->getAttribute('name'),
            'actor' => $actor->name,
        ], 'warning', '/vendors/'.$vendor->getKey());
    }

    private function phone(Workspace $workspace, ?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $e164 = PhoneNormalizer::e164($raw, PaymentSettings::country($workspace));

        if ($e164 === null) {
            throw new WorkflowException('invalid_phone', 'phone');
        }

        return $e164;
    }

    private static function clean(?string $value, int $max): ?string
    {
        return $value !== null && trim($value) !== '' ? mb_substr(trim($value), 0, $max, 'UTF-8') : null;
    }

    private static function email(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? mb_strtolower(trim($value)) : null;
    }
}
