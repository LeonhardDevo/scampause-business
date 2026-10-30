<?php

declare(strict_types=1);

namespace App\Domain\Policy;

use App\Audit\Auditor;
use App\Billing\PlanLimits;
use App\Domain\Approvals\Enums\RouteStatus;
use App\Domain\Approvals\Models\ApprovalRoute;
use App\Domain\Approvals\RouteBuilder;
use App\Domain\Money\Models\ExchangeRate;
use App\Domain\Policy\Models\ApprovalRule;
use App\Domain\Policy\Models\MemberLimit;
use App\Domain\Risk\Enums\RiskLevel;
use App\Domain\Workflow\GovernanceRecorder;
use App\Domain\Workflow\WorkflowException;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class PolicyService
{
    public const string RULES_LIMIT = 'approval_rules';

    public const array RULE_KEYS = [
        'name', 'description', 'is_active', 'min_amount_base_minor', 'max_amount_base_minor', 'kinds', 'min_risk_level',
        'applies_to_new_vendor', 'applies_to_bank_change', 'approvals_required', 'required_roles', 'approver_roles',
        'verification_required', 'expiry_hours',
    ];

    public const array SETTINGS_KEYS = [
        'own_domains', 'business_days', 'business_hours_start', 'business_hours_end', 'quiet_hours_start', 'quiet_hours_end',
        'default_expiry_hours', 'reminder_offsets_hours', 'escalate_after_hours', 'telegram_approvals_enabled',
        'telegram_approve_max_risk', 'verification_amount_threshold_minor', 'verification_valid_days', 'country',
        'callback_cooldown_days', 'new_approver_grace_hours', 'ai_extract_assist',
    ];

    public function __construct(
        private PlanLimits $limits,
        private GovernanceRecorder $governance,
        private RouteBuilder $routes,
        private Auditor $auditor,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createRule(Workspace $workspace, User $actor, array $data): ApprovalRule
    {
        if ((bool) ($data['is_active'] ?? true)) {
            $this->limits->ensure($workspace, self::RULES_LIMIT);
        }

        return DB::transaction(function () use ($workspace, $actor, $data): ApprovalRule {
            $rule = new ApprovalRule;
            $rule->forceFill([
                'workspace_id' => $workspace->id,
                ...$this->ruleAttributes($data),
                'position' => (int) ApprovalRule::query()->forWorkspace($workspace)->max('position') + 1,
                'version' => 1,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $this->governance->record($workspace, 'policy.rule_created', 'approval_rule', (int) $rule->getKey(), [
                'rule_id' => (int) $rule->getKey(),
                'version' => 1,
                'changed_keys' => array_values(array_intersect(self::RULE_KEYS, array_keys($data))),
            ], $actor);
            $this->auditor->record('policy.rule_created', $rule, actor: $actor, workspace: $workspace, subjectLabel: (string) $rule->getAttribute('name'));

            return $rule;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateRule(ApprovalRule $subject, User $actor, array $data): ApprovalRule
    {
        return DB::transaction(function () use ($subject, $actor, $data): ApprovalRule {
            $workspace = Workspace::query()->findOrFail((int) $subject->getAttribute('workspace_id'));
            $rule = ApprovalRule::query()->forWorkspace($workspace)->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
            $wasActive = (bool) $rule->getAttribute('is_active');
            $rule->forceFill($this->ruleAttributes($data));

            if (! $wasActive && (bool) $rule->getAttribute('is_active')) {
                $this->limits->ensure($workspace, self::RULES_LIMIT);
            }

            $changed = array_values(array_intersect(self::RULE_KEYS, array_keys($rule->getDirty())));

            if ($changed === []) {
                return $rule;
            }

            $rule->forceFill(['version' => (int) $rule->getAttribute('version') + 1, 'updated_by' => $actor->id])->save();
            $event = $wasActive && ! (bool) $rule->getAttribute('is_active') ? 'policy.rule_deactivated' : 'policy.rule_updated';
            $this->governance->record($workspace, $event, 'approval_rule', (int) $rule->getKey(), [
                'rule_id' => (int) $rule->getKey(),
                'version' => (int) $rule->getAttribute('version'),
                'changed_keys' => $changed,
            ], $actor);
            $this->auditor->record($event, $rule, metadata: ['changed_keys' => $changed], actor: $actor, workspace: $workspace, subjectLabel: (string) $rule->getAttribute('name'));

            return $rule;
        });
    }

    public function deleteRule(ApprovalRule $rule, User $actor): void
    {
        DB::transaction(function () use ($rule, $actor): void {
            $workspace = Workspace::query()->findOrFail((int) $rule->getAttribute('workspace_id'));
            $rule->forceFill(['is_active' => false, 'version' => (int) $rule->getAttribute('version') + 1, 'updated_by' => $actor->id])->save();
            $rule->delete();
            $this->governance->record($workspace, 'policy.rule_deactivated', 'approval_rule', (int) $rule->getKey(), [
                'rule_id' => (int) $rule->getKey(),
                'version' => (int) $rule->getAttribute('version'),
                'changed_keys' => ['deleted_at'],
            ], $actor);
            $this->auditor->record('policy.rule_deleted', $rule, actor: $actor, workspace: $workspace, subjectLabel: (string) $rule->getAttribute('name'));
        });
    }

    /**
     * @param  list<int>  $ids
     */
    public function reorder(Workspace $workspace, User $actor, array $ids): void
    {
        DB::transaction(function () use ($workspace, $actor, $ids): void {
            $rules = ApprovalRule::query()->forWorkspace($workspace)->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');

            if ($rules->count() !== count(array_unique($ids))) {
                throw new WorkflowException('invalid_rule_ids', 'ids');
            }

            foreach ($ids as $index => $id) {
                $rules->get($id)?->forceFill(['position' => $index + 1])->save();
            }

            $this->auditor->record('policy.rules_reordered', null, metadata: ['ids' => $ids], actor: $actor, workspace: $workspace);
        });
    }

    public function setLimits(Workspace $workspace, User $actor, User $member, ?int $approvalLimit, ?int $monthlyLimit): MemberLimit
    {
        if ($member->roleIn($workspace) === null) {
            throw new WorkflowException('not_a_member', 'user');
        }

        return DB::transaction(function () use ($workspace, $actor, $member, $approvalLimit, $monthlyLimit): MemberLimit {
            $limit = MemberLimit::query()->forWorkspace($workspace)->where('user_id', $member->id)->lockForUpdate()->first() ?? new MemberLimit;
            $before = ['approval_limit_base_minor' => $limit->getAttribute('approval_limit_base_minor'), 'monthly_limit_base_minor' => $limit->getAttribute('monthly_limit_base_minor')];
            $limit->forceFill([
                'workspace_id' => $workspace->id,
                'user_id' => $member->id,
                'approval_limit_base_minor' => $approvalLimit,
                'monthly_limit_base_minor' => $monthlyLimit,
                'updated_by' => $actor->id,
            ])->save();
            $changed = array_keys(array_filter([
                'approval_limit' => $before['approval_limit_base_minor'] !== $approvalLimit,
                'monthly_limit' => $before['monthly_limit_base_minor'] !== $monthlyLimit,
            ]));

            if ($changed !== []) {
                $this->governance->record($workspace, 'policy.limit_changed', 'user', $member->id, ['user_id' => $member->id, 'changed_keys' => $changed], $actor);
                $this->auditor->record('policy.limit_changed', $limit, Auditor::diff($before, ['approval_limit_base_minor' => $approvalLimit, 'monthly_limit_base_minor' => $monthlyLimit]), actor: $actor, workspace: $workspace, subjectLabel: $member->name);
                $this->refillRoutes($workspace, $actor);
            }

            return $limit;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSettings(Workspace $workspace, User $actor, array $data): void
    {
        DB::transaction(function () use ($workspace, $actor, $data): void {
            $settings = PaymentSettings::for($workspace);
            $attributes = array_intersect_key($data, array_flip(self::SETTINGS_KEYS));
            $country = $attributes['country'] ?? null;
            unset($attributes['country']);

            if (isset($attributes['telegram_approve_max_risk']) && is_string($attributes['telegram_approve_max_risk'])) {
                $attributes['telegram_approve_max_risk'] = RiskLevel::from($attributes['telegram_approve_max_risk']);
            }

            $settings->forceFill($attributes);
            $changed = array_keys($settings->getDirty());

            if (is_string($country) && $country !== '') {
                $workspace->forceFill(['country' => strtoupper($country)]);

                if ($workspace->isDirty('country')) {
                    $changed[] = 'country';
                }
            }

            if ($changed === []) {
                return;
            }

            $settings->save();
            $workspace->save();
            $this->governance->record($workspace, 'policy.settings_changed', 'workspace', $workspace->id, ['keys' => $changed], $actor);
            $this->auditor->record('policy.settings_changed', $settings, metadata: ['keys' => $changed], actor: $actor, workspace: $workspace);
        });
    }

    public function addRate(Workspace $workspace, User $actor, string $quote, string $rate, CarbonImmutable $date): ExchangeRate
    {
        $quote = strtoupper($quote);

        if ($quote === strtoupper($workspace->currency)) {
            throw new WorkflowException('rate_same_currency', 'quote_currency');
        }

        return DB::transaction(function () use ($workspace, $actor, $quote, $rate, $date): ExchangeRate {
            $model = ExchangeRate::query()->forWorkspace($workspace)->where('quote_currency', $quote)->where('effective_date', $date->toDateString())->first() ?? new ExchangeRate;
            $model->forceFill([
                'workspace_id' => $workspace->id,
                'quote_currency' => $quote,
                'base_currency' => strtoupper($workspace->currency),
                'rate' => self::normalizeRate($rate),
                'effective_date' => $date->toDateString(),
                'source' => 'manual',
                'created_by' => $actor->id,
            ])->save();
            $this->auditor->record('policy.exchange_rate_set', $model, metadata: ['quote_currency' => $quote, 'rate' => $model->getAttribute('rate'), 'effective_date' => $date->toDateString()], actor: $actor, workspace: $workspace, subjectLabel: $quote);

            return $model;
        });
    }

    public function deleteRate(ExchangeRate $rate, User $actor): void
    {
        $workspaceId = (int) $rate->getAttribute('workspace_id');
        $rate->delete();
        $this->auditor->record('policy.exchange_rate_deleted', $rate, metadata: ['quote_currency' => $rate->getAttribute('quote_currency')], actor: $actor, workspace: $workspaceId);
    }

    public static function normalizeRate(string $rate): string
    {
        $value = str_replace([',', ' '], ['.', ''], trim($rate));

        if (preg_match('/^\d{1,10}(\.\d{1,8})?$/', $value) !== 1 || (float) $value <= 0.0) {
            throw new WorkflowException('invalid_rate', 'rate');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ltrim($whole, '0') === '' ? '0.'.str_pad($fraction, 8, '0') : ltrim($whole, '0').'.'.str_pad($fraction, 8, '0');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function ruleAttributes(array $data): array
    {
        $out = [];

        foreach (self::RULE_KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            $out[$key] = match ($key) {
                'min_risk_level' => is_string($value) && $value !== '' ? RiskLevel::from($value) : null,
                'kinds' => is_array($value) && $value !== [] ? array_values(array_unique(array_map('strval', $value))) : null,
                'required_roles' => PolicyEvaluator::ordered(array_values(array_map('strval', is_array($value) ? $value : []))),
                'approver_roles' => PolicyEvaluator::ordered(array_values(array_map('strval', is_array($value) ? $value : []))),
                'name' => mb_substr(trim((string) $value), 0, 120, 'UTF-8'),
                'description' => is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 500, 'UTF-8') : null,
                'is_active', 'verification_required' => (bool) $value,
                'applies_to_new_vendor', 'applies_to_bank_change' => $value === null ? null : ((bool) $value ?: null),
                default => $value === null || $value === '' ? null : (int) $value,
            };
        }

        return $out;
    }

    private function refillRoutes(Workspace $workspace, User $actor): void
    {
        $routes = ApprovalRoute::query()->forWorkspace($workspace)->where('status', RouteStatus::Active->value)->orderBy('id')->get();

        foreach ($routes as $route) {
            $this->routes->fillMissing($route, $actor);
        }
    }
}
