<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

use App\Audit\Auditor;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Intake\Models\EmailMessage;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Support\Fingerprinter;
use App\Domain\Support\Normalize\PhoneNormalizer;
use App\Domain\Vendors\Enums\ContactStatus;
use App\Domain\Vendors\Enums\VendorKind;
use App\Domain\Vendors\Models\Vendor;
use App\Domain\Vendors\Models\VendorContact;
use App\Domain\Verification\Models\Verification;
use App\Domain\Workflow\WorkflowException;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class DataSubjectService
{
    public function __construct(private EvidenceLedger $ledger, private Auditor $auditor) {}

    /**
     * @param  array{type: string, id?: int|null, email?: string|null, phone?: string|null}  $subject
     * @return array{contacts: Collection<int, VendorContact>, verifications: Collection<int, Verification>, messages: Collection<int, EmailMessage>, requests: Collection<int, PaymentRequest>}
     */
    public function find(Workspace $workspace, Vendor $vendor, array $subject): array
    {
        $contacts = collect();
        $messages = collect();
        $requests = collect();

        if ($subject['type'] === 'contact') {
            $id = $subject['id'] ?? null;
            $phone = $subject['phone'] ?? null;
            $email = $subject['email'] ?? null;
            $query = VendorContact::query()->forWorkspace($workspace)->withTrashed()->where('vendor_id', $vendor->getKey());

            if ($id !== null) {
                $query->whereKey($id);
            } elseif ($phone !== null) {
                $query->where('phone_hash', $this->phoneHash($workspace, $phone));
            } elseif ($email !== null) {
                $query->where('email', mb_strtolower($email));
            } else {
                throw new WorkflowException('subject_identifier_required', 'subject');
            }

            $contacts = $query->get();
        } elseif ($subject['type'] === 'employee') {
            if ($vendor->getAttribute('kind') !== VendorKind::Employee) {
                throw new WorkflowException('not_an_employee', 'subject');
            }

            $contacts = VendorContact::query()->forWorkspace($workspace)->withTrashed()->where('vendor_id', $vendor->getKey())->get();
        } else {
            $email = mb_strtolower(trim((string) ($subject['email'] ?? '')));

            if ($email === '') {
                throw new WorkflowException('email_required', 'subject.email');
            }

            $messages = EmailMessage::query()->forWorkspace($workspace)->where(fn ($query) => $query->where('from_email', $email)->orWhere('reply_to_email', $email))->get();
            $requests = PaymentRequest::query()->forWorkspace($workspace)->where(fn ($query) => $query->where('sender_email', $email)->orWhere('reply_to_email', $email))->get();
        }

        $verifications = $contacts->isEmpty() ? collect() : Verification::query()->forWorkspace($workspace)->whereIn('contact_id', $contacts->pluck('id')->all())->get();

        return ['contacts' => $contacts, 'verifications' => $verifications, 'messages' => $messages, 'requests' => $requests];
    }

    /**
     * @param  array{contacts: Collection<int, VendorContact>, verifications: Collection<int, Verification>, messages: Collection<int, EmailMessage>, requests: Collection<int, PaymentRequest>}  $found
     * @return array<string, mixed>
     */
    public function export(array $found): array
    {
        return [
            'contacts' => array_values($found['contacts']->map(static fn (VendorContact $contact): array => [
                'name' => $contact->getAttribute('name'),
                'role_title' => $contact->getAttribute('role_title'),
                'email' => $contact->getAttribute('email'),
                'phone' => $contact->getAttribute('phone_e164'),
                'source' => $contact->getAttribute('source')?->value,
                'source_ref' => $contact->getAttribute('source_ref'),
            ])->all()),
            'verifications' => array_values($found['verifications']->map(static fn (Verification $verification): array => [
                'occurred_at' => $verification->getAttribute('occurred_at')?->toIso8601ZuluString(),
                'method' => $verification->getAttribute('method')?->value,
                'outcome' => $verification->getAttribute('outcome')?->value,
                'spoken_to_name' => $verification->getAttribute('spoken_to_name'),
                'phone_dialed' => $verification->getAttribute('phone_dialed'),
                'notes' => $verification->getAttribute('notes'),
            ])->all()),
            'messages' => array_values($found['messages']->map(static fn (EmailMessage $message): array => [
                'from_name' => $message->getAttribute('from_name'),
                'from_email' => $message->getAttribute('from_email'),
                'reply_to_email' => $message->getAttribute('reply_to_email'),
                'subject' => $message->getAttribute('subject'),
                'sent_at' => $message->getAttribute('sent_at')?->toIso8601ZuluString(),
            ])->all()),
            'requests' => array_values($found['requests']->map(static fn (PaymentRequest $request): string => (string) $request->getAttribute('reference'))->all()),
        ];
    }

    /**
     * @param  array{type: string, id?: int|null, email?: string|null, phone?: string|null}  $subject
     * @return array<string, int>
     */
    public function redact(Workspace $workspace, Vendor $vendor, User $actor, array $subject): array
    {
        return DB::transaction(function () use ($workspace, $vendor, $actor, $subject): array {
            $found = $this->find($workspace, $vendor, $subject);
            DB::statement("SET LOCAL scampause.allow_pii_redaction = 'on'");
            $contactIds = $found['contacts']->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

            if ($contactIds !== []) {
                DB::table('vendor_contacts')->whereIn('id', $contactIds)->update([
                    'phone_e164' => null,
                    'email' => null,
                    'status' => ContactStatus::Retired->value,
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('verifications')->where('workspace_id', $workspace->id)->whereIn('contact_id', $contactIds)->update([
                    'spoken_to_name' => null,
                    'phone_dialed' => null,
                    'notes' => null,
                ]);
            }

            $messageIds = $found['messages']->pluck('id')->all();

            if ($messageIds !== []) {
                DB::table('email_messages')->whereIn('id', $messageIds)->update(['from_name' => null, 'from_email' => null, 'reply_to_email' => null, 'to_emails' => '[]']);
            }

            $requestIds = $found['requests']->pluck('id')->all();
            $email = mb_strtolower(trim((string) ($subject['email'] ?? '')));

            if ($requestIds !== [] && $email !== '') {
                DB::table('payment_requests')->whereIn('id', $requestIds)->where('sender_email', $email)->update(['sender_email' => null]);
                DB::table('payment_requests')->whereIn('id', $requestIds)->where('reply_to_email', $email)->update(['reply_to_email' => null]);
            }

            $counts = ['contacts' => count($contactIds), 'verifications' => $found['verifications']->count(), 'messages' => count($messageIds), 'requests' => count($requestIds)];
            $this->ledger->append(
                workspaceId: $workspace->id,
                event: 'privacy.subject_redacted',
                subjectType: $subject['type'] === 'sender' ? 'email_sender' : 'vendor_contact',
                subjectId: $subject['type'] === 'sender' ? (int) $vendor->getKey() : ($contactIds[0] ?? (int) $vendor->getKey()),
                payload: ['subject_type' => $subject['type'], 'subject_id' => $contactIds[0] ?? (int) $vendor->getKey(), 'columns' => $this->columns($subject['type'])],
                actorUserId: $actor->id,
            );
            $this->auditor->record('privacy.subject_redacted', $vendor, metadata: $counts, actor: $actor, workspace: $workspace, subjectLabel: (string) $vendor->getAttribute('name'));

            return $counts;
        });
    }

    /** @return list<string> */
    private function columns(string $type): array
    {
        return $type === 'sender'
            ? ['email_messages.from_name', 'email_messages.from_email', 'email_messages.reply_to_email', 'email_messages.to_emails', 'payment_requests.sender_email', 'payment_requests.reply_to_email']
            : ['vendor_contacts.phone_e164', 'vendor_contacts.email', 'verifications.spoken_to_name', 'verifications.phone_dialed', 'verifications.notes'];
    }

    private function phoneHash(Workspace $workspace, string $phone): string
    {
        $e164 = PhoneNormalizer::e164($phone, 'US') ?? $phone;

        return Fingerprinter::fromConfig()->phone($workspace->id, $e164);
    }
}
