<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal\Domain;

use App\Domain\Approvals\ApprovalGate;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Money\Money;
use App\Domain\Notifications\DomainNotifier;
use App\Domain\Risk\Enums\RiskLevel;
use App\Domain\Telegram\TelegramCallbackService;
use App\Domain\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Internal\Domain\Concerns\InternalResponses;
use App\Locale\WithLocale;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Psr\Clock\ClockInterface;

final class TelegramDomainController extends Controller
{
    use InternalResponses;

    public function callback(Request $request, TelegramCallbackService $service): JsonResponse
    {
        $validated = $request->validate([
            'action_type' => ['required', Rule::in(['sp.apv', 'sp.apv_confirm', 'sp.rej', 'sp.rej_reason', 'sp.cancel', 'sp.pay_me', 'sp.pay_not_me'])],
            'subject_id' => ['required', 'integer'],
            'workspace_id' => ['required', 'integer'],
            'telegram_user_id' => ['required', 'integer'],
            'chat_id' => ['nullable', 'integer'],
            'message_id' => ['nullable', 'integer'],
            'step_token' => ['required', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:32'],
            'language_code' => ['nullable', 'string', 'max:16'],
        ]);

        return $this->guard(fn (): JsonResponse => response()->json($service->handle($validated)));
    }

    public function workspace(Request $request): JsonResponse
    {
        $validated = $request->validate(['telegram_user_id' => ['required', 'integer'], 'workspace_id' => ['nullable', 'integer']]);
        $account = TelegramAccount::query()->where('telegram_user_id', (int) $validated['telegram_user_id'])->first();
        $user = $account?->user;

        if ($account === null || $user === null) {
            return self::error('not_linked', 404);
        }

        if (isset($validated['workspace_id'])) {
            $workspace = $user->workspaces()->whereKey((int) $validated['workspace_id'])->first();

            if ($workspace === null) {
                return self::error('forbidden', 403);
            }

            $account->forceFill(['workspace_id' => $workspace->id])->save();

            return response()->json(['ok' => true]);
        }

        return response()->json(['workspaces' => array_values($user->workspaces()->orderBy('workspaces.name')->get()
            ->map(static fn (Workspace $workspace): array => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => (string) $workspace->getAttribute('slug'),
                'current' => (int) $account->workspace_id === $workspace->id,
            ])->all())]);
    }

    public function pending(Request $request, ClockInterface $clock): JsonResponse
    {
        $validated = $request->validate(['telegram_user_id' => ['required', 'integer']]);
        $user = TelegramAccount::query()->where('telegram_user_id', (int) $validated['telegram_user_id'])->first()?->user;

        if ($user === null) {
            return self::error('not_linked', 404);
        }

        $locale = DomainNotifier::locale($user);
        $workspaceIds = $user->workspaces()->pluck('workspaces.id')->all();
        $approvals = Approval::query()->withoutGlobalScopes()->whereIn('workspace_id', $workspaceIds)
            ->where('approver_id', $user->id)->where('status', ApprovalStatus::Pending->value)
            ->whereHas('paymentRequest', fn ($query) => $query->withoutGlobalScopes()->where('status', PaymentRequestStatus::AwaitingApproval->value))
            ->orderBy('created_at')->limit(10)->get();
        $now = CarbonImmutable::instance($clock->now());

        return response()->json(['items' => array_values($approvals->map(fn (Approval $approval): array => WithLocale::run($locale, fn (): array => $this->pendingItem($approval, $locale, $now)))->all())]);
    }

    public function request(Request $request): JsonResponse
    {
        $validated = $request->validate(['telegram_user_id' => ['required', 'integer'], 'reference' => ['required', 'string', 'max:80']]);
        $account = TelegramAccount::query()->where('telegram_user_id', (int) $validated['telegram_user_id'])->first();
        $user = $account?->user;

        if ($account === null || $user === null) {
            return self::error('not_linked', 404);
        }

        $raw = strtoupper(trim((string) $validated['reference']));
        $slug = null;

        if (str_contains($raw, '/')) {
            [$slug, $raw] = explode('/', $raw, 2);
            $slug = strtolower($slug);
        }

        if (preg_match('/^SP-\d{5}$/', $raw) !== 1) {
            return self::error('not_found', 404);
        }

        $workspaces = $user->workspaces()->when($slug !== null, fn ($query) => $query->where('workspaces.slug', $slug))->get();
        $matches = [];

        foreach ($workspaces as $workspace) {
            $role = $user->roleIn($workspace);
            $request = PaymentRequest::query()->forWorkspace($workspace)->where('reference', $raw)->first();

            if ($request !== null && $role !== null && ($role->allows('payment_requests.view_all') || (int) $request->getAttribute('requested_by') === $user->id)) {
                $matches[] = [$workspace, $request];
            }
        }

        if ($matches === []) {
            return self::error('not_found', 404);
        }

        if (count($matches) > 1 && $slug === null) {
            $current = array_values(array_filter($matches, static fn (array $pair): bool => $pair[0]->id === (int) $account->workspace_id));

            if (count($current) !== 1) {
                return response()->json(['ambiguous' => array_map(static fn (array $pair): array => [
                    'workspace' => ['id' => $pair[0]->id, 'name' => $pair[0]->name, 'slug' => (string) $pair[0]->getAttribute('slug')],
                    'reference' => $raw,
                ], $matches)]);
            }

            $matches = $current;
        }

        [$workspace, $model] = $matches[0];
        $locale = DomainNotifier::locale($user);

        return response()->json(WithLocale::run($locale, fn (): array => [
            'template' => 'sp.request_status',
            'params' => [
                ...app(DomainNotifier::class)->requestParams($model, $workspace, $locale),
                'status_label' => $model->getAttribute('status')?->label($locale),
                'status' => $model->getAttribute('status')?->value,
            ],
            'actions' => [],
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingItem(Approval $approval, string $locale, CarbonImmutable $now): array
    {
        $workspace = Workspace::query()->findOrFail((int) $approval->getAttribute('workspace_id'));
        $request = PaymentRequest::query()->forWorkspace($workspace)->findOrFail((int) $approval->getAttribute('payment_request_id'));
        $vendor = is_int($v = $request->getAttribute('vendor_id')) ? Vendor::query()->forWorkspace($workspace)->withTrashed()->find($v) : null;
        $expires = $request->getAttribute('expires_at');
        $hours = $expires instanceof \DateTimeInterface ? max(0, (int) ceil(($expires->getTimestamp() - $now->getTimestamp()) / 3600)) : null;
        $level = $request->getAttribute('risk_level');
        $amount = $request->getAttribute('amount_minor');
        $currency = $request->getAttribute('currency');

        return [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'approval_id' => (int) $approval->getKey(),
            'reference' => (string) $request->getAttribute('reference'),
            'title' => (string) $request->getAttribute('title'),
            'vendor' => $vendor?->getAttribute('name'),
            'amount_formatted' => is_int($amount) && is_string($currency) ? Money::of($amount, $currency)->format($locale) : null,
            'risk_level' => $level instanceof RiskLevel ? $level->value : null,
            'risk_label' => $level instanceof RiskLevel ? $level->label($locale) : null,
            'telegram_approve_allowed' => app(ApprovalGate::class)->telegramAllowed($request, $workspace),
            'expires_at' => $expires instanceof \DateTimeInterface ? CarbonImmutable::instance($expires)->utc()->toIso8601ZuluString() : null,
            'expires_human' => $hours !== null ? trans_choice('domain.time.hours_left', $hours, ['count' => $hours]) : null,
            'url' => url('/payment-requests/'.$request->getKey().'?tab=approvals'),
        ];
    }

    public static function userOf(int $telegramUserId): ?User
    {
        return TelegramAccount::query()->where('telegram_user_id', $telegramUserId)->first()?->user;
    }
}
