<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

use App\Domain\Approvals\ApprovalGate;
use App\Domain\Approvals\ApprovalPublisher;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Enums\DecisionChannel;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Approvals\Models\ApprovalRoute;
use App\Domain\Approvals\StepTokens;
use App\Domain\Evidence\Enums\EvidenceChannel;
use App\Domain\Intake\Enums\DecisionReason;
use App\Domain\Intake\Enums\EmployeeResponse;
use App\Domain\Intake\Exceptions\InvalidTransition;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Vendors\Models\Vendor;
use App\Domain\Verification\EmployeeAlertService;
use App\Domain\Verification\Models\EmployeeAlert;
use App\Domain\Workflow\WorkflowException;
use App\Locale\WithLocale;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Psr\Clock\ClockInterface;

final readonly class TelegramCallbackService
{
    public const array REJECT_REASONS = ['fraud_confirmed', 'details_mismatch', 'not_ordered', 'duplicate', 'other'];

    public function __construct(
        private ApprovalService $approvals,
        private ApprovalPublisher $publisher,
        private ApprovalGate $gate,
        private EmployeeAlertService $alerts,
        private ClockInterface $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function handle(array $input): array
    {
        $account = TelegramAccount::query()->where('telegram_user_id', (int) $input['telegram_user_id'])->first();
        $user = $account?->user;
        $locale = is_string($input['language_code'] ?? null) && str_starts_with((string) $input['language_code'], 'ru') ? 'ru' : 'en';

        if ($user === null || $user->getAttribute('deleted_at') !== null) {
            return self::answer('not_linked', $locale, true);
        }

        $locale = $user->preferredLocale() === 'ru' ? 'ru' : 'en';
        $workspace = Workspace::query()->find((int) $input['workspace_id']);

        if ($workspace === null || ! $user->belongsToWorkspace($workspace)) {
            return self::answer('approver_no_longer_eligible', $locale, true);
        }

        return WithLocale::run($locale, fn (): array => match ((string) $input['action_type']) {
            'sp.pay_me', 'sp.pay_not_me' => $this->payroll($input, $user, $workspace, $locale),
            default => $this->approval($input, $user, $workspace, $locale),
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function approval(array $input, User $user, Workspace $workspace, string $locale): array
    {
        $approval = Approval::query()->forWorkspace($workspace)->find((int) $input['subject_id']);

        if ($approval === null || (int) $approval->getAttribute('approver_id') !== $user->id) {
            return self::answer('no_longer_valid', $locale, true);
        }

        if ($approval->getAttribute('status') !== ApprovalStatus::Pending || ! StepTokens::matches(is_string($input['step_token'] ?? null) ? $input['step_token'] : null, $approval->getAttribute('telegram_nonce_hash'))) {
            return self::answer('no_longer_valid', $locale, true);
        }

        $request = PaymentRequest::query()->forWorkspace($workspace)->findOrFail((int) $approval->getAttribute('payment_request_id'));
        $route = ApprovalRoute::query()->forWorkspace($workspace)->findOrFail((int) $approval->getAttribute('approval_route_id'));

        try {
            return match ((string) $input['action_type']) {
                'sp.apv' => $this->approveStep($approval, $request, $workspace, $route, $locale),
                'sp.apv_confirm' => $this->approveConfirm($approval, $user, $workspace, $route, $locale),
                'sp.rej' => $this->rejectStep($approval, $request, $locale),
                'sp.rej_reason' => $this->rejectConfirm($approval, $user, $input, $locale),
                'sp.cancel' => $this->cancelStep($approval, $request, $workspace, $route, $locale),
                default => self::answer('no_longer_valid', $locale, true),
            };
        } catch (WorkflowException $exception) {
            if ($exception->errorCode === 'telegram_approve_blocked') {
                return $this->blocked($approval, $request, $workspace, $route, $locale, (string) ($exception->replace['reason'] ?? ApprovalGate::HIGH_RISK));
            }

            return ['answer' => ['text' => $exception->localized(), 'show_alert' => true]];
        } catch (InvalidTransition) {
            return self::answer('no_longer_valid', $locale, true);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function approveStep(Approval $approval, PaymentRequest $request, Workspace $workspace, ApprovalRoute $route, string $locale): array
    {
        $reason = $this->gate->telegramBlockReason($request, $workspace);

        if ($reason !== null) {
            return $this->blocked($approval, $request, $workspace, $route, $locale, $reason);
        }

        $token = $this->rotate($approval);

        return [
            'answer' => ['text' => '', 'show_alert' => false],
            'edit' => [
                'template' => 'sp.approve_confirm',
                'params' => $this->publisher->params($approval, $request, $workspace, $route, $locale),
                'actions' => [
                    ['type' => 'sp.apv_confirm', 'subject_id' => (int) $approval->getKey(), 'step_token' => $token, 'text' => (string) __('domain.telegram.buttons.confirm')],
                    ['type' => 'sp.cancel', 'subject_id' => (int) $approval->getKey(), 'step_token' => $token, 'text' => (string) __('domain.telegram.buttons.cancel')],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function approveConfirm(Approval $approval, User $user, Workspace $workspace, ApprovalRoute $route, string $locale): array
    {
        $this->approvals->approve($approval, $user, DecisionChannel::Telegram);
        $route->refresh();
        $approved = Approval::query()->forWorkspace($workspace)->where('approval_route_id', $route->getKey())->where('status', ApprovalStatus::Approved->value)->count();

        return [
            'answer' => ['text' => (string) __('domain.telegram.answers.done'), 'show_alert' => false],
            'edit' => [
                'template' => 'sp.approved_ack',
                'params' => [
                    'time_local' => CarbonImmutable::instance($this->clock->now())->setTimezone($workspace->timezone ?: 'UTC')->format('H:i'),
                    'remaining' => max(0, (int) $route->getAttribute('approvals_required') - $approved),
                ],
                'actions' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rejectStep(Approval $approval, PaymentRequest $request, string $locale): array
    {
        $token = $this->rotate($approval);
        $actions = array_map(static fn (string $reason): array => [
            'type' => 'sp.rej_reason',
            'subject_id' => (int) $approval->getKey(),
            'step_token' => $token,
            'reason' => $reason,
            'text' => DecisionReason::from($reason)->label($locale),
        ], self::REJECT_REASONS);
        $actions[] = ['type' => 'sp.cancel', 'subject_id' => (int) $approval->getKey(), 'step_token' => $token, 'text' => (string) __('domain.telegram.buttons.cancel')];

        return [
            'answer' => ['text' => '', 'show_alert' => false],
            'edit' => ['template' => 'sp.reject_reason_prompt', 'params' => ['reference' => (string) $request->getAttribute('reference')], 'actions' => $actions],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function rejectConfirm(Approval $approval, User $user, array $input, string $locale): array
    {
        $value = is_string($input['reason'] ?? null) ? $input['reason'] : '';

        if (! in_array($value, self::REJECT_REASONS, true)) {
            return self::answer('no_longer_valid', $locale, true);
        }

        $reason = DecisionReason::from($value);
        $comment = $reason === DecisionReason::Other ? (string) __('domain.telegram.other_comment') : null;
        $this->approvals->reject($approval, $user, $reason, $comment, DecisionChannel::Telegram);

        return [
            'answer' => ['text' => (string) __('domain.telegram.answers.done'), 'show_alert' => false],
            'edit' => [
                'template' => 'sp.rejected_ack',
                'params' => ['reason_label' => $reason->label($locale), 'blocked' => in_array($reason, [DecisionReason::FraudConfirmed, DecisionReason::DetailsMismatch], true)],
                'actions' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cancelStep(Approval $approval, PaymentRequest $request, Workspace $workspace, ApprovalRoute $route, string $locale): array
    {
        $token = $this->rotate($approval);

        return [
            'answer' => ['text' => '', 'show_alert' => false],
            'edit' => [
                'template' => 'sp.approval_requested',
                'params' => $this->publisher->params($approval, $request, $workspace, $route, $locale),
                'actions' => $this->publisher->actions($approval, $request, $workspace, $token, $locale),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blocked(Approval $approval, PaymentRequest $request, Workspace $workspace, ApprovalRoute $route, string $locale, string $reason): array
    {
        $token = $this->rotate($approval);

        return [
            'answer' => [
                'text' => (string) __('domain.telegram.approve_blocked', ['reason' => (string) __('domain.telegram.block_reasons.'.$reason)]),
                'show_alert' => true,
            ],
            'edit' => [
                'template' => 'sp.approval_requested',
                'params' => $this->publisher->params($approval, $request, $workspace, $route, $locale),
                'actions' => array_values(array_filter(
                    $this->publisher->actions($approval, $request, $workspace, $token, $locale),
                    static fn (array $action): bool => $action['type'] !== 'sp.apv',
                )),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function payroll(array $input, User $user, Workspace $workspace, string $locale): array
    {
        $alert = EmployeeAlert::query()->forWorkspace($workspace)->find((int) $input['subject_id']);
        $vendor = $alert !== null ? Vendor::query()->forWorkspace($workspace)->withTrashed()->find((int) $alert->getAttribute('vendor_id')) : null;

        if ($alert === null || $vendor === null || (int) $vendor->getAttribute('linked_user_id') !== $user->id
            || ! StepTokens::matches(is_string($input['step_token'] ?? null) ? $input['step_token'] : null, $alert->getAttribute('nonce_hash'))) {
            return self::answer('no_longer_valid', $locale, true);
        }

        $response = $input['action_type'] === 'sp.pay_not_me' ? EmployeeResponse::NotMe : EmployeeResponse::ItWasMe;

        try {
            $this->alerts->answer($alert, $response, EvidenceChannel::Telegram, null, null, $user);
        } catch (WorkflowException $exception) {
            return ['answer' => ['text' => $exception->localized(), 'show_alert' => true]];
        }

        $text = (string) __('domain.employee_response.'.($response === EmployeeResponse::NotMe ? 'thanks_not_me' : 'thanks_me'));

        return [
            'answer' => ['text' => $text, 'show_alert' => false],
            'edit' => ['template' => 'sp.payroll_answered', 'params' => ['text' => $text, 'response' => $response->value], 'actions' => []],
        ];
    }

    private function rotate(Approval $approval): string
    {
        $token = StepTokens::issue();
        $approval->forceFill(['telegram_nonce_hash' => $token['hash']])->save();

        return $token['token'];
    }

    /**
     * @return array{answer: array{text: string, show_alert: bool}}
     */
    private static function answer(string $key, string $locale, bool $alert): array
    {
        return ['answer' => ['text' => (string) __('domain.telegram.answers.'.$key, [], $locale), 'show_alert' => $alert]];
    }
}
