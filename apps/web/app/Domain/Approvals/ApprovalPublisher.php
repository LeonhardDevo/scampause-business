<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Domain\Approvals\Data\ScheduleSettings;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Approvals\Models\ApprovalRoute;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Notifications\DomainNotifier;
use App\Domain\Policy\PaymentSettings;
use App\Domain\Verification\Models\Verification;
use App\Locale\WithLocale;
use App\Models\User;
use App\Models\Workspace;
use App\Outbox\Outbox;
use Carbon\CarbonImmutable;
use Psr\Clock\ClockInterface;

final readonly class ApprovalPublisher
{
    public const string REQUESTED = 'approval.requested';

    public const string REMINDER = 'approval.reminder.due';

    public const string ESCALATION = 'approval.escalation.due';

    public const string EXPIRY = 'approval.expiry.due';

    public const string PACKET = 'evidence.packet.requested';

    public const string JOURNAL = 'audit.journal.requested';

    public function __construct(
        private Outbox $outbox,
        private DomainNotifier $notifier,
        private ApprovalGate $gate,
        private ClockInterface $clock,
    ) {}

    public function requested(Approval $approval, PaymentRequest $request, Workspace $workspace, ApprovalRoute $route): void
    {
        $user = User::query()->find((int) $approval->getAttribute('approver_id'));

        if ($user === null) {
            return;
        }

        $locale = DomainNotifier::locale($user);
        $token = StepTokens::issue();
        $approval->forceFill(['telegram_nonce_hash' => $token['hash']])->save();
        $params = $this->params($approval, $request, $workspace, $route, $locale);
        $telegramIds = [];
        $telegram = null;

        if ($user->telegramAccount !== null) {
            $row = $this->notifier->row('approval_requested', $user, $workspace->id, $request, DomainNotifier::TELEGRAM, 'queued', (int) $approval->getKey());
            $telegramIds['telegram'] = (int) $row->getKey();
            $telegram = [
                'template' => 'sp.approval_requested',
                'params' => $params,
                'actions' => $this->actions($approval, $request, $workspace, $token['token'], $locale),
            ];
        }

        $emailRow = $this->notifier->row('approval_requested', $user, $workspace->id, $request, DomainNotifier::EMAIL, 'queued', (int) $approval->getKey());
        $telegramIds['email'] = (int) $emailRow->getKey();
        $email = DomainNotifier::renderEmail('approval_requested', $params, $locale, url('/payment-requests/'.$request->getKey().'?tab=approvals'));

        $this->outbox->publish(self::REQUESTED, [
            'approval_id' => (int) $approval->getKey(),
            'payment_request_id' => (int) $request->getKey(),
            'workspace_id' => $workspace->id,
            'sp_notification_ids' => $telegramIds,
            'recipient' => [
                'user_id' => $user->id,
                'locale' => $locale,
                'email' => $user->email,
                'telegram_user_id' => $user->telegramAccount?->telegram_user_id,
            ],
            'telegram' => $telegram,
            'email' => $email,
        ], $workspace->id);
    }

    public function schedule(ApprovalRoute $route, PaymentRequest $request, Workspace $workspace): void
    {
        $settings = ScheduleSettings::from($workspace, PaymentSettings::for($workspace));
        $start = CarbonImmutable::instance($route->getAttribute('started_at'));
        $expires = CarbonImmutable::instance($route->getAttribute('expires_at'));
        $reminders = ApprovalSchedule::reminders($start, $expires, $settings);
        $approvals = Approval::query()->forWorkspace($workspace)->where('approval_route_id', $route->getKey())->where('status', ApprovalStatus::Pending->value)->get();

        foreach ($approvals as $approval) {
            $this->scheduleReminders($approval, $request, $workspace, $reminders);
        }

        $escalation = ApprovalSchedule::escalation($start, $expires, $settings);

        if ($escalation !== null) {
            $this->outbox->publish(self::ESCALATION, [
                'approval_route_id' => (int) $route->getKey(),
                'payment_request_id' => (int) $request->getKey(),
                'workspace_id' => $workspace->id,
            ], $workspace->id, $escalation);
        }

        $this->outbox->publish(self::EXPIRY, [
            'approval_route_id' => (int) $route->getKey(),
            'payment_request_id' => (int) $request->getKey(),
            'workspace_id' => $workspace->id,
            'expires_at' => $expires->utc()->toIso8601ZuluString(),
        ], $workspace->id, $expires);
    }

    /**
     * @param  list<CarbonImmutable>|null  $times
     */
    public function scheduleReminders(Approval $approval, PaymentRequest $request, Workspace $workspace, ?array $times = null): void
    {
        if ($times === null) {
            $route = ApprovalRoute::query()->forWorkspace($workspace)->findOrFail((int) $approval->getAttribute('approval_route_id'));
            $settings = ScheduleSettings::from($workspace, PaymentSettings::for($workspace));
            $start = CarbonImmutable::instance($this->clock->now());
            $times = ApprovalSchedule::reminders($start, CarbonImmutable::instance($route->getAttribute('expires_at')), $settings);
        }

        foreach ($times as $index => $at) {
            $this->outbox->publish(self::REMINDER, [
                'approval_id' => (int) $approval->getKey(),
                'payment_request_id' => (int) $request->getKey(),
                'workspace_id' => $workspace->id,
                'sequence' => $index + 1,
                'total' => count($times),
            ], $workspace->id, $at);
        }
    }

    public function rescheduleReminder(Approval $approval, int $sequence, int $total, CarbonImmutable $at): void
    {
        $this->outbox->publish(self::REMINDER, [
            'approval_id' => (int) $approval->getKey(),
            'payment_request_id' => (int) $approval->getAttribute('payment_request_id'),
            'workspace_id' => (int) $approval->getAttribute('workspace_id'),
            'sequence' => $sequence,
            'total' => $total,
        ], (int) $approval->getAttribute('workspace_id'), $at);
    }

    /**
     * @return array<string, mixed>
     */
    public function params(Approval $approval, PaymentRequest $request, Workspace $workspace, ApprovalRoute $route, string $locale): array
    {
        return WithLocale::run($locale, function () use ($request, $workspace, $route, $locale): array {
            $expires = CarbonImmutable::instance($route->getAttribute('expires_at'));
            $now = CarbonImmutable::instance($this->clock->now());
            $hours = max(0, (int) ceil(($expires->getTimestamp() - $now->getTimestamp()) / 3600));

            return [
                ...$this->notifier->requestParams($request, $workspace, $locale),
                'expires_local' => $expires->setTimezone($workspace->timezone ?: 'UTC')->format('Y-m-d H:i'),
                'expires_relative' => trans_choice('domain.time.hours_left', $hours, ['count' => $hours]),
                'verification_status' => $this->verificationStatus($request, $workspace, $locale),
                'url' => url('/payment-requests/'.$request->getKey().'?tab=approvals'),
            ];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function actions(Approval $approval, PaymentRequest $request, Workspace $workspace, string $token, string $locale): array
    {
        $actions = [];

        if ($this->gate->telegramAllowed($request, $workspace)) {
            $actions[] = ['type' => 'sp.apv', 'subject_id' => (int) $approval->getKey(), 'step_token' => $token, 'text' => (string) __('domain.telegram.buttons.approve', [], $locale)];
        }

        $actions[] = ['type' => 'sp.rej', 'subject_id' => (int) $approval->getKey(), 'step_token' => $token, 'text' => (string) __('domain.telegram.buttons.reject', [], $locale)];

        return $actions;
    }

    public function stripButtons(Approval $approval, string $statusKey): void
    {
        $message = $approval->getAttribute('telegram_message');

        if (! is_array($message) || ! isset($message['chat_id'], $message['message_id'])) {
            return;
        }

        $user = User::query()->find((int) $approval->getAttribute('approver_id'));

        if ($user === null) {
            return;
        }

        $request = PaymentRequest::query()->forWorkspace((int) $approval->getAttribute('workspace_id'))->find((int) $approval->getAttribute('payment_request_id'));
        $locale = DomainNotifier::locale($user);

        $this->notifier->publishTelegram($user, (int) $approval->getAttribute('workspace_id'), [
            'template' => 'sp.approval_closed',
            'params' => [
                'reference' => (string) $request?->getAttribute('reference'),
                'status_label' => (string) __('domain.telegram.closed.'.$statusKey, [], $locale),
            ],
            'actions' => [],
            'buttons' => [],
            'edit' => ['chat_id' => (int) $message['chat_id'], 'message_id' => (int) $message['message_id']],
            'reference' => ['type' => 'approval', 'id' => (string) $approval->getKey()],
        ]);
    }

    private function verificationStatus(PaymentRequest $request, Workspace $workspace, string $locale): string
    {
        $id = $request->getAttribute('satisfying_verification_id');

        if (! is_int($id)) {
            return (string) __('domain.notifications.verification_not_required', [], $locale);
        }

        $verification = Verification::query()->forWorkspace($workspace)->with('performer:id,name')->find($id);
        $at = $verification?->getAttribute('occurred_at');

        return (string) __('domain.notifications.verification_confirmed', [
            'verifier' => (string) $verification?->performer?->name,
            'when' => $at instanceof \DateTimeInterface ? CarbonImmutable::instance($at)->setTimezone($workspace->timezone ?: 'UTC')->format('Y-m-d H:i') : '',
        ], $locale);
    }
}
