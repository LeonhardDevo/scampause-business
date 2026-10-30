<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Money\Money;
use App\Domain\Notifications\Models\SpNotification;
use App\Domain\Risk\Enums\RiskLevel;
use App\Domain\Risk\Models\RiskSignal;
use App\Domain\Risk\SignalPresenter;
use App\Domain\Support\Masking;
use App\Domain\Vendors\Enums\BankScheme;
use App\Domain\Vendors\Models\Vendor;
use App\Enums\WorkspaceRole;
use App\Locale\WithLocale;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Domain\DomainAlert;
use App\Notifications\InAppMessage;
use App\Outbox\Outbox;
use App\Outbox\Topics;
use Carbon\CarbonImmutable;

final readonly class DomainNotifier
{
    public const string DATABASE = 'database';

    public const string TELEGRAM = 'telegram';

    public const string EMAIL = 'email';

    public function __construct(private Outbox $outbox) {}

    /**
     * @param  iterable<User>  $recipients
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $extra
     * @return list<SpNotification>
     */
    public function notify(
        string $template,
        iterable $recipients,
        int $workspaceId,
        ?PaymentRequest $request,
        array $channels,
        array $extra = [],
        string $kind = 'info',
        ?string $href = null,
        ?int $approvalId = null,
    ): array {
        $rows = [];
        $seen = [];
        $workspace = Workspace::query()->find($workspaceId);

        foreach ($recipients as $user) {
            if (isset($seen[$user->id]) || $user->getAttribute('deleted_at') !== null) {
                continue;
            }

            $seen[$user->id] = true;
            $locale = self::locale($user);
            $params = WithLocale::run($locale, fn (): array => [...$this->requestParams($request, $workspace, $locale), ...self::resolveExtra($extra, $locale)]);
            $link = $href ?? ($request !== null ? '/payment-requests/'.$request->getKey() : null);

            foreach ($channels as $channel) {
                $row = match ($channel) {
                    self::DATABASE => $this->database($template, $user, $workspaceId, $request, $params, $kind, $link, $approvalId),
                    self::TELEGRAM => $this->telegram($template, $user, $workspaceId, $request, $params, $locale, $link, $approvalId),
                    self::EMAIL => $this->email($template, $user, $workspaceId, $request, $params, $locale, $link, $approvalId),
                    default => null,
                };

                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * @param  list<WorkspaceRole>  $roles
     * @return list<User>
     */
    public static function membersWithRoles(Workspace|int $workspace, array $roles): array
    {
        $model = $workspace instanceof Workspace ? $workspace : Workspace::query()->findOrFail($workspace);

        return array_values($model->members()
            ->wherePivotIn('role', array_map(static fn (WorkspaceRole $role): string => $role->value, $roles))
            ->whereNull('users.deleted_at')
            ->orderBy('users.id')
            ->get()
            ->all());
    }

    /**
     * @return list<User>
     */
    public static function membersWithAbility(Workspace|int $workspace, string $ability): array
    {
        $roles = array_values(array_filter(WorkspaceRole::cases(), static fn (WorkspaceRole $role): bool => $role->allows($ability)));

        return self::membersWithRoles($workspace, $roles);
    }

    /**
     * @param  array<int|null>  $ids
     * @return list<User>
     */
    public static function users(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, 'is_int')));

        return $ids === [] ? [] : array_values(User::query()->whereIn('id', $ids)->whereNull('deleted_at')->orderBy('id')->get()->all());
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function resolveExtra(array $extra, string $locale): array
    {
        $out = [];

        foreach ($extra as $key => $value) {
            $out[$key] = $value instanceof \Closure ? $value($locale) : $value;
        }

        return $out;
    }

    public static function locale(User $user): string
    {
        $locale = $user->preferredLocale();

        return in_array($locale, ['en', 'ru'], true) ? $locale : 'en';
    }

    /**
     * @return array<string, string|int|bool|null|list<string>>
     */
    public function requestParams(?PaymentRequest $request, ?Workspace $workspace, string $locale): array
    {
        $name = $workspace !== null ? $workspace->name : '';
        $params = ['workspace' => $name, 'company' => $name];

        if ($request === null) {
            return $params;
        }

        $vendor = is_int($vendorId = $request->getAttribute('vendor_id'))
            ? Vendor::query()->forWorkspace((int) $request->getAttribute('workspace_id'))->withTrashed()->find($vendorId)
            : null;
        $level = $request->getAttribute('risk_level');
        $amount = $request->getAttribute('amount_minor');
        $currency = $request->getAttribute('currency');
        $signals = self::topSignals($request, $locale);
        $due = $request->getAttribute('due_date');

        return [
            ...$params,
            'reference' => (string) $request->getAttribute('reference'),
            'title' => mb_substr((string) $request->getAttribute('title'), 0, 120, 'UTF-8'),
            'vendor' => $vendor !== null ? (string) $vendor->getAttribute('name') : (string) __('domain.notifications.unknown_vendor', [], $locale),
            'amount' => is_int($amount) && is_string($currency) ? Money::of($amount, $currency)->format($locale) : '—',
            'payee_masked' => self::payeeMasked($request),
            'bank' => is_string($bank = $request->getAttribute('payee_bank_name')) ? $bank : '—',
            'risk_label' => $level instanceof RiskLevel ? $level->label($locale) : '—',
            'risk_level' => $level instanceof RiskLevel ? $level->value : null,
            'score' => is_int($score = $request->getAttribute('risk_score')) ? $score : 0,
            'top_signals' => $signals,
            'top_signals_bullets' => $signals === [] ? '' : '• '.implode("\n• ", $signals),
            'due_date' => $due instanceof \DateTimeInterface ? CarbonImmutable::instance($due)->toDateString() : '—',
            'url' => url('/payment-requests/'.$request->getKey()),
        ];
    }

    public static function payeeMasked(PaymentRequest $request): string
    {
        $scheme = $request->getAttribute('payee_scheme');
        $last4 = $request->getAttribute('payee_last4');

        if (! $scheme instanceof BankScheme || ! is_string($last4)) {
            return '—';
        }

        $country = $request->getAttribute('payee_bank_country');

        return trim(Masking::account($scheme, $last4).(is_string($country) ? ' ('.$country.')' : ''));
    }

    /**
     * @return list<string>
     */
    public static function topSignals(PaymentRequest $request, string $locale, int $limit = 3): array
    {
        $assessment = $request->getAttribute('risk_assessment_id');

        if (! is_int($assessment)) {
            return [];
        }

        $codes = RiskSignal::query()->forWorkspace((int) $request->getAttribute('workspace_id'))
            ->where('risk_assessment_id', $assessment)
            ->where('points', '>', 0)
            ->orderByDesc('points')
            ->orderBy('code')
            ->limit($limit)
            ->pluck('code')
            ->all();

        return array_values(array_map(static fn (mixed $code): string => WithLocale::run($locale, static fn (): string => SignalPresenter::title((string) $code)), $codes));
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{lines?: list<string>, footer?: list<string>, sender?: string, action_label?: string, notice_url?: string|null}  $options
     * @return array{subject: string, html: string, text: string}
     */
    public static function renderEmail(string $template, array $params, string $locale, ?string $url, array $options = []): array
    {
        return WithLocale::run($locale, static function () use ($template, $params, $url, $options, $locale): array {
            $scalar = self::scalarParams($params);
            $subject = (string) __('domain.notifications.'.$template.'.subject', $scalar);
            $heading = (string) __('domain.notifications.'.$template.'.title', $scalar);
            $lines = array_values(array_filter([(string) __('domain.notifications.'.$template.'.body', $scalar), ...($options['lines'] ?? [])], static fn (string $line): bool => trim($line) !== ''));
            $bullets = is_array($params['top_signals'] ?? null) ? array_values(array_filter($params['top_signals'], 'is_string')) : [];
            $footer = $options['footer'] ?? [(string) __('domain.notifications.footer')];
            $data = [
                'locale' => $locale,
                'subject' => $subject,
                'sender' => (string) ($options['sender'] ?? 'ScamPause'),
                'heading' => $heading,
                'lines' => $lines,
                'bullets' => $bullets,
                'actionUrl' => $url,
                'actionLabel' => (string) ($options['action_label'] ?? __('domain.notifications.open')),
                'footer' => $footer,
                'privacyUrl' => url('/legal/privacy'),
                'privacyLabel' => (string) __('domain.notifications.privacy'),
                'noticeUrl' => $options['notice_url'] ?? null,
                'noticeLabel' => (string) __('domain.notifications.data_subject_notice'),
            ];
            $html = view('emails.domain.notification', $data)->render();
            $text = implode("\n\n", array_filter([
                $heading,
                implode("\n", $lines),
                $bullets === [] ? null : '- '.implode("\n- ", $bullets),
                $url !== null ? $data['actionLabel'].': '.$url : null,
                implode("\n", $footer),
            ]));

            return ['subject' => $subject, 'html' => $html, 'text' => $text];
        });
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, string|int>
     */
    public static function scalarParams(array $params): array
    {
        $out = [];

        foreach ($params as $key => $value) {
            if (is_string($value) || is_int($value)) {
                $out[$key] = $value;
            } elseif (is_bool($value)) {
                $out[$key] = $value ? 1 : 0;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function publishTelegram(User $user, int $workspaceId, array $payload): void
    {
        $this->outbox->publish(Topics::TELEGRAM_NOTIFY, ['user_id' => $user->id, 'locale' => self::locale($user), ...$payload], $workspaceId);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function database(string $template, User $user, int $workspaceId, ?PaymentRequest $request, array $params, string $kind, ?string $href, ?int $approvalId): SpNotification
    {
        $scalar = self::scalarParams($params);
        unset($scalar['url'], $scalar['top_signals_bullets']);
        $user->notify(new DomainAlert(new InAppMessage(
            kind: in_array($kind, InAppMessage::KINDS, true) ? $kind : 'info',
            titleKey: 'domain.notifications.'.$template.'.title',
            titleParams: $scalar,
            bodyKey: 'domain.notifications.'.$template.'.body',
            bodyParams: $scalar,
            href: $href,
            workspaceId: $workspaceId,
        )));

        return $this->row($template, $user, $workspaceId, $request, self::DATABASE, 'sent', $approvalId);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function telegram(string $template, User $user, int $workspaceId, ?PaymentRequest $request, array $params, string $locale, ?string $href, ?int $approvalId): ?SpNotification
    {
        if ($user->telegramAccount === null) {
            return null;
        }

        $row = $this->row($template, $user, $workspaceId, $request, self::TELEGRAM, 'queued', $approvalId);
        $buttons = $href !== null ? [['text' => (string) __('domain.notifications.open', [], $locale), 'url' => url($href)]] : [];
        $this->publishTelegram($user, $workspaceId, [
            'template' => 'sp.'.$template,
            'params' => $params,
            'actions' => [],
            'buttons' => $buttons,
            'reference' => ['type' => 'sp_notification', 'id' => (string) $row->getKey()],
        ]);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function email(string $template, User $user, int $workspaceId, ?PaymentRequest $request, array $params, string $locale, ?string $href, ?int $approvalId): SpNotification
    {
        $row = $this->row($template, $user, $workspaceId, $request, self::EMAIL, 'queued', $approvalId);
        $rendered = self::renderEmail($template, $params, $locale, $href !== null ? url($href) : null);
        $this->outbox->publish(Topics::EMAIL_SEND, [
            'to' => $user->email,
            'to_name' => $user->name,
            'subject' => $rendered['subject'],
            'html' => $rendered['html'],
            'text' => $rendered['text'],
            'locale' => $locale,
            'reference' => ['type' => 'sp_notification', 'id' => (string) $row->getKey()],
            'workspace_id' => $workspaceId,
        ], $workspaceId);

        return $row;
    }

    public function row(string $template, ?User $user, int $workspaceId, ?PaymentRequest $request, string $channel, string $status, ?int $approvalId = null, ?int $contactId = null): SpNotification
    {
        $row = new SpNotification;
        $row->forceFill([
            'workspace_id' => $workspaceId,
            'payment_request_id' => $request?->getKey(),
            'approval_id' => $approvalId,
            'template' => $template,
            'recipient_user_id' => $user?->id,
            'recipient_vendor_contact_id' => $contactId,
            'channel' => $channel,
            'status' => $status,
        ])->save();

        return $row;
    }
}
