<?php

declare(strict_types=1);

namespace App\Http\Controllers\Domain;

use App\Billing\PlanLimits;
use App\Domain\Approvals\ApproverPool;
use App\Domain\Billing\Entitlements;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Presenters\Refs;
use App\Domain\Money\Currencies;
use App\Domain\Money\FxConverter;
use App\Domain\Money\Models\ExchangeRate;
use App\Domain\Money\MoneyParser;
use App\Domain\Policy\Data\PolicyInput;
use App\Domain\Policy\Models\ApprovalRule;
use App\Domain\Policy\Models\MemberLimit;
use App\Domain\Policy\PaymentSettings;
use App\Domain\Policy\PolicyService;
use App\Domain\Policy\RequestPolicy;
use App\Domain\Risk\Enums\RiskLevel;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Domain\Concerns\RunsWorkflow;
use App\Http\Requests\Domain\RuleRequest;
use App\Http\Requests\Domain\SettingsRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Psr\Clock\ClockInterface;

final class PolicyController extends Controller
{
    use RunsWorkflow;

    public function index(Request $request, CurrentWorkspace $current, ApproverPool $pool, PlanLimits $limits, Entitlements $entitlements): Response
    {
        $this->allow($current, 'policies.view');
        $workspace = $this->workspace($current);
        $tab = in_array($request->query('tab'), ['rules', 'limits', 'settings', 'currencies'], true) ? (string) $request->query('tab') : 'rules';
        $settings = PaymentSettings::for($workspace);
        $base = strtoupper($workspace->currency);
        $limitRows = MemberLimit::query()->forWorkspace($workspace)->get()->keyBy('user_id');
        $members = [];

        foreach ($workspace->members()->orderBy('users.name')->get() as $member) {
            $role = $member->membership?->role;
            $role = $role instanceof WorkspaceRole ? $role : WorkspaceRole::tryFrom((string) $role);
            $limit = $limitRows->get($member->id);
            $members[] = [
                ...(Refs::user($member) ?? []),
                'role' => $role !== null ? $role->value : 'member',
                'approval_limit' => Refs::money($limit?->getAttribute('approval_limit_base_minor'), $base),
                'monthly_limit' => Refs::money($limit?->getAttribute('monthly_limit_base_minor'), $base),
                'approved_this_month' => Refs::money($pool->approvedThisMonth($workspace, $member->id), $base),
                'can_approve' => $role !== null && $role->allows('payment_requests.approve'),
            ];
        }

        $threshold = (int) $settings->getAttribute('verification_amount_threshold_minor');

        return Inertia::render('policies/Index', [
            'tab' => $tab,
            'rules' => array_values(ApprovalRule::query()->forWorkspace($workspace)->with('updater:id,name,deleted_at')->orderBy('position')->orderBy('id')->get()
                ->map(static fn (ApprovalRule $rule): array => self::ruleDto($rule, $base))->all()),
            'members' => $members,
            'settings' => [
                'own_domains' => PaymentSettings::ownDomains($settings),
                'business_days' => array_values(array_map('intval', (array) $settings->getAttribute('business_days'))),
                'business_hours_start' => substr((string) $settings->getAttribute('business_hours_start'), 0, 5),
                'business_hours_end' => substr((string) $settings->getAttribute('business_hours_end'), 0, 5),
                'quiet_hours_start' => substr((string) $settings->getAttribute('quiet_hours_start'), 0, 5),
                'quiet_hours_end' => substr((string) $settings->getAttribute('quiet_hours_end'), 0, 5),
                'default_expiry_hours' => (int) $settings->getAttribute('default_expiry_hours'),
                'reminder_offsets_hours' => array_values(array_map('intval', (array) $settings->getAttribute('reminder_offsets_hours'))),
                'escalate_after_hours' => (int) $settings->getAttribute('escalate_after_hours'),
                'telegram_approvals_enabled' => (bool) $settings->getAttribute('telegram_approvals_enabled'),
                'telegram_approve_max_risk' => (string) Refs::enum($settings->getAttribute('telegram_approve_max_risk')),
                'verification_amount_threshold' => Refs::money($threshold, $base),
                'verification_amount_threshold_input' => MoneyParser::fromMinor($threshold, $base),
                'verification_valid_days' => (int) $settings->getAttribute('verification_valid_days'),
                'country' => PaymentSettings::country($workspace),
                'callback_cooldown_days' => (int) $settings->getAttribute('callback_cooldown_days'),
                'new_approver_grace_hours' => (int) $settings->getAttribute('new_approver_grace_hours'),
                'ai_extract_assist' => (bool) $settings->getAttribute('ai_extract_assist'),
            ],
            'exchange_rates' => array_values(ExchangeRate::query()->forWorkspace($workspace)->orderByDesc('effective_date')->orderBy('quote_currency')->limit(200)->get()
                ->map(static fn (ExchangeRate $rate): array => [
                    'id' => (int) $rate->getKey(),
                    'quote_currency' => (string) $rate->getAttribute('quote_currency'),
                    'rate' => (string) $rate->getAttribute('rate'),
                    'effective_date' => (string) Refs::date($rate->getAttribute('effective_date')),
                    'source' => (string) $rate->getAttribute('source'),
                ])->all()),
            'base_currency' => $base,
            'currencies' => Currencies::codes(),
            'options' => [
                'kinds' => PaymentRequestKind::options(),
                'roles' => array_map(static fn (WorkspaceRole $role): array => ['value' => $role->value, 'label' => $role->label()], [WorkspaceRole::Owner, WorkspaceRole::Admin, WorkspaceRole::Approver]),
                'risk_levels' => RiskLevel::options(),
            ],
            'limits' => [
                'rules' => ['used' => $limits->used($workspace, PolicyService::RULES_LIMIT), 'limit' => $limits->limit($workspace, PolicyService::RULES_LIMIT)],
                'telegram_approvals' => $entitlements->allows($workspace, 'telegram_approvals'),
            ],
            'abilities' => ['manage' => $current->allows('policies.manage')],
        ]);
    }

    public function storeRule(RuleRequest $request, CurrentWorkspace $current, PolicyService $policies): RedirectResponse
    {
        $this->allow($current, 'policies.manage');
        $workspace = $this->workspace($current);
        $user = $this->user($request);

        return $this->run(fn () => $policies->createRule($workspace, $user, $request->payload()), 'domain.flash.rule_saved');
    }

    public function updateRule(RuleRequest $request, int $rule, CurrentWorkspace $current, PolicyService $policies): RedirectResponse
    {
        $this->allow($current, 'policies.manage');
        $model = $this->rule($rule);
        $user = $this->user($request);

        return $this->run(fn () => $policies->updateRule($model, $user, $request->payload()), 'domain.flash.rule_saved');
    }

    public function destroyRule(Request $request, int $rule, CurrentWorkspace $current, PolicyService $policies): RedirectResponse
    {
        $this->allow($current, 'policies.manage');
        $model = $this->rule($rule);
        $user = $this->user($request);

        return $this->run(fn () => $policies->deleteRule($model, $user), 'domain.flash.rule_deleted');
    }

    public function reorder(Request $request, CurrentWorkspace $current, PolicyService $policies): RedirectResponse
    {
        $this->allow($current, 'policies.manage');
        $validated = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct']]);
        $workspace = $this->workspace($current);
        $user = $this->user($request);

        return $this->run(fn () => $policies->reorder($workspace, $user, array_values(array_map('intval', $validated['ids']))), 'domain.flash.rules_reordered');
    }

    public function limits(Request $request, int $user, CurrentWorkspace $current, PolicyService $policies): RedirectResponse
    {
        $this->allow($current, 'policies.manage');
        $workspace = $this->workspace($current);
        $validated = $request->validate([
            'approval_limit' => ['present', 'nullable', 'string', 'max:20'],
            'monthly_limit' => ['present', 'nullable', 'string', 'max:20'],
        ]);
        $member = $workspace->members()->where('users.id', $user)->first();
        abort_unless($member instanceof User, 404);
        $parse = static function (mixed $value, string $key) use ($workspace): ?int {
            if (! is_string($value) || trim($value) === '') {
                return null;
            }

            $minor = MoneyParser::toMinor($value, strtoupper($workspace->currency));

            if ($minor === null) {
                throw ValidationException::withMessages([$key => (string) __('domain.validation.amount')]);
            }

            return $minor;
        };
        $approval = $parse($validated['approval_limit'] ?? null, 'approval_limit');
        $monthly = $parse($validated['monthly_limit'] ?? null, 'monthly_limit');
        $actor = $this->user($request);

        return $this->run(fn () => $policies->setLimits($workspace, $actor, $member, $approval, $monthly), 'domain.flash.limits_saved');
    }

    public function settings(SettingsRequest $request, CurrentWorkspace $current, PolicyService $policies): RedirectResponse
    {
        $this->allow($current, 'policies.manage');
        $workspace = $this->workspace($current);
        $user = $this->user($request);

        return $this->run(fn () => $policies->updateSettings($workspace, $user, $request->payload()), 'domain.flash.settings_saved');
    }

    public function storeRate(Request $request, CurrentWorkspace $current, PolicyService $policies): RedirectResponse
    {
        $this->allow($current, 'policies.manage');
        $validated = $request->validate([
            'quote_currency' => ['required', 'string', 'size:3', Rule::in(Currencies::codes())],
            'rate' => ['required', 'string', 'max:20'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
        ]);
        $workspace = $this->workspace($current);
        $user = $this->user($request);

        return $this->run(fn () => $policies->addRate($workspace, $user, (string) $validated['quote_currency'], (string) $validated['rate'], CarbonImmutable::parse((string) $validated['effective_date'])), 'domain.flash.rate_saved');
    }

    public function destroyRate(Request $request, int $rate, CurrentWorkspace $current, PolicyService $policies): RedirectResponse
    {
        $this->allow($current, 'policies.manage');
        $model = ExchangeRate::query()->whereKey($rate)->first();
        abort_if($model === null, 404);
        $user = $this->user($request);

        return $this->run(fn () => $policies->deleteRate($model, $user), 'domain.flash.rate_deleted');
    }

    public function simulate(Request $request, CurrentWorkspace $current, RequestPolicy $policy, FxConverter $fx, ClockInterface $clock): JsonResponse
    {
        $this->allow($current, 'policies.view');
        $workspace = $this->workspace($current);
        $validated = $request->validate([
            'amount' => ['required', 'string', 'max:20'],
            'currency' => ['nullable', 'string', 'size:3', Rule::in(Currencies::codes())],
            'kind' => ['nullable', Rule::enum(PaymentRequestKind::class)],
            'risk_level' => ['nullable', Rule::enum(RiskLevel::class)],
            'new_vendor' => ['nullable', 'boolean'],
            'bank_change' => ['nullable', 'boolean'],
        ]);

        return response()->json(self::simulated($workspace, $validated, $policy, $fx, CarbonImmutable::instance($clock->now())));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function simulated(Workspace $workspace, array $input, RequestPolicy $policy, FxConverter $fx, CarbonImmutable $now): array
    {
        $base = strtoupper($workspace->currency);
        $currency = is_string($input['currency'] ?? null) && $input['currency'] !== '' ? strtoupper($input['currency']) : $base;
        $minor = MoneyParser::toMinor(str_replace(',', '.', (string) $input['amount']), $currency);

        if ($minor === null) {
            throw ValidationException::withMessages(['amount' => (string) __('domain.validation.amount')]);
        }

        $conversion = $fx->convert($workspace->id, $minor, $currency, $base, $now->setTimezone($workspace->timezone ?: 'UTC'));

        return $policy->simulate($workspace, new PolicyInput(
            amountBaseMinor: $conversion->amountBaseMinor,
            kind: is_string($input['kind'] ?? null) ? PaymentRequestKind::from($input['kind']) : PaymentRequestKind::Invoice,
            riskLevel: is_string($input['risk_level'] ?? null) ? RiskLevel::from($input['risk_level']) : RiskLevel::Low,
            isNewVendor: filter_var($input['new_vendor'] ?? false, FILTER_VALIDATE_BOOLEAN),
            hasBankChange: filter_var($input['bank_change'] ?? false, FILTER_VALIDATE_BOOLEAN),
            baseCurrency: $base,
        ))->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public static function ruleDto(ApprovalRule $rule, string $base): array
    {
        return [
            'id' => (int) $rule->getKey(),
            'name' => (string) $rule->getAttribute('name'),
            'description' => is_string($d = $rule->getAttribute('description')) ? $d : null,
            'position' => (int) $rule->getAttribute('position'),
            'is_active' => (bool) $rule->getAttribute('is_active'),
            'version' => (int) $rule->getAttribute('version'),
            'min_amount' => Refs::money($rule->getAttribute('min_amount_base_minor'), $base),
            'max_amount' => Refs::money($rule->getAttribute('max_amount_base_minor'), $base),
            'kinds' => is_array($k = $rule->getAttribute('kinds')) ? array_values($k) : null,
            'min_risk_level' => Refs::enum($rule->getAttribute('min_risk_level')),
            'applies_to_new_vendor' => is_bool($n = $rule->getAttribute('applies_to_new_vendor')) ? $n : null,
            'applies_to_bank_change' => is_bool($b = $rule->getAttribute('applies_to_bank_change')) ? $b : null,
            'approvals_required' => (int) $rule->getAttribute('approvals_required'),
            'required_roles' => array_values((array) $rule->getAttribute('required_roles')),
            'approver_roles' => array_values((array) $rule->getAttribute('approver_roles')),
            'verification_required' => (bool) $rule->getAttribute('verification_required'),
            'expiry_hours' => is_int($e = $rule->getAttribute('expiry_hours')) ? $e : null,
            'updated_by' => Refs::user($rule->updater),
            'updated_at' => (string) Refs::iso($rule->getAttribute('updated_at')),
        ];
    }

    private function rule(int $rule): ApprovalRule
    {
        $model = ApprovalRule::query()->whereKey($rule)->first();
        abort_if($model === null, 404);

        return $model;
    }
}
