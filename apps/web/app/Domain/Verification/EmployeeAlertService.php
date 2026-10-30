<?php

declare(strict_types=1);

namespace App\Domain\Verification;

use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Approvals\StepTokens;
use App\Domain\Evidence\Enums\EvidenceChannel;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Intake\Enums\EmployeeResponse;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Notifications\DomainNotifier;
use App\Domain\Risk\Enums\AssessmentTrigger;
use App\Domain\Vendors\Enums\VendorKind;
use App\Domain\Vendors\Models\Vendor;
use App\Domain\Vendors\Models\VendorContact;
use App\Domain\Verification\Models\EmployeeAlert;
use App\Domain\Workflow\ReassessmentService;
use App\Domain\Workflow\RequestLock;
use App\Domain\Workflow\WorkflowException;
use App\Locale\WithLocale;
use App\Models\User;
use App\Models\Workspace;
use App\Outbox\Outbox;
use App\Outbox\Topics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Psr\Clock\ClockInterface;

final readonly class EmployeeAlertService
{
    public const int TTL_HOURS = 72;

    public function __construct(
        private ReassessmentService $reassessment,
        private DomainNotifier $notifier,
        private EvidenceLedger $ledger,
        private Outbox $outbox,
        private ClockInterface $clock,
    ) {}

    public function sendIfNeeded(PaymentRequest $request): ?EmployeeAlert
    {
        if ($request->getAttribute('kind') !== PaymentRequestKind::PayrollChange || ! is_int($vendorId = $request->getAttribute('vendor_id'))) {
            return null;
        }

        $workspaceId = (int) $request->getAttribute('workspace_id');

        if (EmployeeAlert::query()->forWorkspace($workspaceId)->where('payment_request_id', $request->getKey())->exists()) {
            return null;
        }

        $vendor = Vendor::query()->forWorkspace($workspaceId)->find($vendorId);

        if ($vendor === null || $vendor->getAttribute('kind') !== VendorKind::Employee) {
            return null;
        }

        $workspace = Workspace::query()->findOrFail($workspaceId);
        $linked = is_int($l = $vendor->getAttribute('linked_user_id')) ? User::query()->whereNull('deleted_at')->find($l) : null;
        $now = CarbonImmutable::instance($this->clock->now());
        $alert = new EmployeeAlert;
        $alert->forceFill([
            'workspace_id' => $workspaceId,
            'payment_request_id' => $request->getKey(),
            'vendor_id' => $vendorId,
            'channel' => $linked?->telegramAccount !== null ? 'telegram' : 'email',
            'expires_at' => $now->addHours(self::TTL_HOURS),
        ]);

        if ($linked !== null && $linked->telegramAccount !== null) {
            $token = StepTokens::issue();
            $alert->forceFill(['nonce_hash' => $token['hash']])->save();
            $row = $this->notifier->row('payroll_employee_alert', $linked, $workspaceId, $request, DomainNotifier::TELEGRAM, 'queued');
            $locale = DomainNotifier::locale($linked);
            $this->notifier->publishTelegram($linked, $workspaceId, [
                'template' => 'sp.payroll_employee_alert',
                'params' => $this->params($request, $workspace, $vendor, $locale),
                'actions' => [
                    ['type' => 'sp.pay_me', 'subject_id' => (int) $alert->getKey(), 'step_token' => $token['token'], 'text' => (string) __('domain.telegram.buttons.it_was_me', [], $locale)],
                    ['type' => 'sp.pay_not_me', 'subject_id' => (int) $alert->getKey(), 'step_token' => $token['token'], 'text' => (string) __('domain.telegram.buttons.not_me', [], $locale)],
                ],
                'buttons' => [],
                'reference' => ['type' => 'sp_notification', 'id' => (string) $row->getKey()],
            ]);

            return $alert;
        }

        $email = $linked !== null ? $linked->email : $this->contactEmail($workspaceId, $vendorId);

        if ($email === null) {
            return null;
        }

        $token = Str::random(48);
        $alert->forceFill(['token_hash' => hash('sha256', $token)])->save();
        $contactId = VendorContact::query()->forWorkspace($workspaceId)->where('vendor_id', $vendorId)->where('email', $email)->value('id');
        $row = $this->notifier->row('payroll_employee_alert', $linked, $workspaceId, $request, DomainNotifier::EMAIL, 'queued', null, is_numeric($contactId) ? (int) $contactId : null);
        $locale = $linked !== null ? DomainNotifier::locale($linked) : ($workspace->locale === 'ru' ? 'ru' : 'en');
        $link = URL::temporarySignedRoute('employee-response.show', $now->addHours(self::TTL_HOURS), ['token' => $token]);
        $params = $this->params($request, $workspace, $vendor, $locale);
        $rendered = DomainNotifier::renderEmail('payroll_employee_alert', $params, $locale, $link, [
            'sender' => (string) __('domain.notifications.payroll_employee_alert.sender', ['company' => $workspace->name], $locale),
            'action_label' => (string) __('domain.notifications.payroll_employee_alert.action', [], $locale),
            'footer' => [
                (string) __('domain.notifications.payroll_employee_alert.object', ['finance_contact' => $this->financeContact($workspace)], $locale),
                (string) __('domain.notifications.footer', [], $locale),
            ],
            'notice_url' => url('/legal/data-subject-notice'),
        ]);

        $this->outbox->publish(Topics::EMAIL_SEND, [
            'to' => $email,
            'to_name' => (string) $vendor->getAttribute('name'),
            'subject' => $rendered['subject'],
            'html' => $rendered['html'],
            'text' => $rendered['text'],
            'locale' => $locale,
            'from' => ['address' => (string) config('mail.from.address'), 'name' => (string) __('domain.notifications.payroll_employee_alert.sender', ['company' => $workspace->name], $locale)],
            'reference' => ['type' => 'sp_notification', 'id' => (string) $row->getKey()],
            'workspace_id' => $workspaceId,
        ], $workspaceId);

        return $alert;
    }

    public function findByToken(string $token): ?EmployeeAlert
    {
        return EmployeeAlert::query()->withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(EmployeeAlert $alert, string $locale): array
    {
        $workspace = Workspace::query()->findOrFail((int) $alert->getAttribute('workspace_id'));
        $request = PaymentRequest::query()->forWorkspace($workspace)->findOrFail((int) $alert->getAttribute('payment_request_id'));
        $vendor = Vendor::query()->forWorkspace($workspace)->withTrashed()->findOrFail((int) $alert->getAttribute('vendor_id'));
        $expires = CarbonImmutable::instance($alert->getAttribute('expires_at'));

        return [
            'company' => $workspace->name,
            'employee_first_name' => self::firstName((string) $vendor->getAttribute('name')),
            'payee_masked' => DomainNotifier::payeeMasked($request),
            'bank' => is_string($b = $request->getAttribute('payee_bank_name')) ? $b : null,
            'reference' => (string) $request->getAttribute('reference'),
            'answered' => $alert->getAttribute('answered_at') !== null,
            'response' => $alert->getAttribute('response')?->value,
            'expired' => $expires->lessThan(CarbonImmutable::instance($this->clock->now())),
            'expires_at' => $expires->utc()->toIso8601ZuluString(),
            'locale' => $locale,
        ];
    }

    public function answer(EmployeeAlert $subject, EmployeeResponse $response, EvidenceChannel $channel, ?string $ip = null, ?string $userAgent = null, ?User $actor = null): EmployeeAlert
    {
        return DB::transaction(function () use ($subject, $response, $channel, $ip, $userAgent, $actor): EmployeeAlert {
            $workspaceId = (int) $subject->getAttribute('workspace_id');
            $alert = EmployeeAlert::query()->forWorkspace($workspaceId)->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::instance($this->clock->now());

            if ($alert->getAttribute('answered_at') !== null) {
                throw new WorkflowException('alert_already_answered');
            }

            if (CarbonImmutable::instance($alert->getAttribute('expires_at'))->lessThan($now)) {
                throw new WorkflowException('alert_expired');
            }

            $alert->forceFill([
                'answered_at' => $now,
                'response' => $response,
                'ip' => $ip,
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255, 'UTF-8') : null,
                'nonce_hash' => null,
            ])->save();

            $request = RequestLock::lock($workspaceId, (int) $alert->getAttribute('payment_request_id'));
            $request->forceFill(['employee_response' => $response, 'employee_responded_at' => $now])->save();
            $this->ledger->append(
                workspaceId: $workspaceId,
                event: 'employee.responded',
                subjectType: 'payment_request',
                subjectId: (int) $request->getKey(),
                payload: ['response' => $response->value],
                actorUserId: $actor?->id,
                channel: $channel,
                paymentRequestId: (int) $request->getKey(),
            );

            if (in_array($request->getAttribute('status')?->value, ReassessmentService::ACTIVE_STATUSES, true)) {
                $this->reassessment->reassess($request, AssessmentTrigger::EmployeeResponse, $actor?->id, $channel);
            }

            $workspace = Workspace::query()->findOrFail($workspaceId);
            $approverIds = Approval::query()->forWorkspace($workspaceId)->where('payment_request_id', $request->getKey())
                ->where('status', ApprovalStatus::Pending->value)->pluck('approver_id')->map(static fn (mixed $id): int => (int) $id)->all();
            $this->notifier->notify(
                'employee_responded',
                [...DomainNotifier::users($approverIds), ...DomainNotifier::membersWithAbility($workspace, 'payment_requests.verify')],
                $workspaceId,
                $request->refresh(),
                [DomainNotifier::DATABASE, DomainNotifier::TELEGRAM],
                ['response_label' => static fn (string $locale): string => $response->label($locale)],
                $response === EmployeeResponse::NotMe ? 'danger' : 'info',
            );

            return $alert;
        });
    }

    /**
     * @return array<string, string>
     */
    private function params(PaymentRequest $request, Workspace $workspace, Vendor $vendor, string $locale): array
    {
        return WithLocale::run($locale, static fn (): array => [
            'employee_first_name' => self::firstName((string) $vendor->getAttribute('name')),
            'company' => $workspace->name,
            'workspace' => $workspace->name,
            'payee_masked' => DomainNotifier::payeeMasked($request),
            'bank' => is_string($b = $request->getAttribute('payee_bank_name')) ? $b : '—',
            'reference' => (string) $request->getAttribute('reference'),
        ]);
    }

    private function contactEmail(int $workspaceId, int $vendorId): ?string
    {
        $email = VendorContact::query()->forWorkspace($workspaceId)->where('vendor_id', $vendorId)->whereNotNull('email')->orderBy('id')->value('email');

        return is_string($email) && $email !== '' ? $email : null;
    }

    private function financeContact(Workspace $workspace): string
    {
        $owner = $workspace->members()->wherePivot('role', 'owner')->orderBy('users.id')->first();

        return $owner !== null ? $owner->name.' <'.$owner->email.'>' : $workspace->name;
    }

    public static function firstName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];

        return $parts[0] ?? $name;
    }
}
