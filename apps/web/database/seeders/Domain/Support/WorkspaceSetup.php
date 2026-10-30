<?php

declare(strict_types=1);

namespace Database\Seeders\Domain\Support;

use App\Billing\Markets\MarketResolver;
use App\Domain\Policy\PaymentSettings;
use App\Domain\Policy\PolicyService;
use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceRole;
use App\Legal\LegalAcceptances;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class WorkspaceSetup
{
    /**
     * @param  array{name: string, timezone: string, currency: string, locale: string}  $attributes
     */
    public static function workspace(string $slug, array $attributes, User $owner, CarbonImmutable $createdAt): Workspace
    {
        $workspace = Workspace::query()->firstOrNew(['slug' => $slug]);

        if (! $workspace->exists) {
            $workspace->forceFill([...$attributes, 'created_by' => $owner->id])->save();
        }

        $workspace->forceFill($attributes);
        $workspace->setAttribute('created_at', $createdAt);
        $workspace->save();
        PaymentSettings::ensure($workspace);

        return $workspace->refresh();
    }

    public static function hasDomainData(Workspace $workspace): bool
    {
        return DB::table('payment_requests')->where('workspace_id', $workspace->id)->exists()
            || DB::table('vendors')->where('workspace_id', $workspace->id)->exists();
    }

    /**
     * @param  array<string, WorkspaceRole>  $roles
     * @param  array<string, User>  $users
     * @param  list<User>  $remove
     */
    public static function members(Workspace $workspace, array $roles, array $users, array $remove, CarbonImmutable $joinedAt): void
    {
        foreach ($remove as $user) {
            $workspace->members()->detach($user->id);

            if ($user->current_workspace_id === $workspace->id) {
                $user->forceFill(['current_workspace_id' => null])->save();
            }
        }

        foreach ($roles as $key => $role) {
            $user = $users[$key];
            $workspace->members()->syncWithoutDetaching([$user->id => ['role' => $role->value]]);
            $workspace->members()->updateExistingPivot($user->id, ['role' => $role->value, 'created_at' => $joinedAt, 'updated_at' => $joinedAt]);

            if ($user->current_workspace_id === null) {
                $user->forceFill(['current_workspace_id' => $workspace->id])->save();
            }
        }

        self::acceptLegal(array_map(static fn (string $key): User => $users[$key], array_keys($roles)), $workspace);
    }

    public static function subscription(Workspace $workspace, string $plan, CarbonImmutable $periodEnd): void
    {
        $market = app(MarketResolver::class)->forWorkspace($workspace);
        $subscription = Subscription::query()->firstOrNew(['workspace_id' => $workspace->id]);
        $subscription->forceFill([
            'gateway' => $market->gateway,
            'plan' => $plan,
            'status' => SubscriptionStatus::Active,
            'interval' => BillingInterval::Month->value,
            'currency' => $market->currency,
            'current_period_end' => $periodEnd,
            'cancel_at_period_end' => false,
            'payment_failed_at' => null,
        ])->save();
    }

    /** @param  list<User>  $users */
    public static function acceptLegal(array $users, ?Workspace $workspace = null): void
    {
        $acceptances = app(LegalAcceptances::class);

        foreach ($users as $user) {
            $acceptances->acceptPending($user, $workspace, $user->preferredLocale(), null);
        }
    }

    public static function resetLedger(Workspace $workspace): void
    {
        DB::transaction(function () use ($workspace): void {
            DB::statement("SET LOCAL scampause.allow_evidence_purge = 'on'");
            DB::table('evidence_entries')->where('workspace_id', $workspace->id)->delete();
            DB::table('evidence_heads')->where('workspace_id', $workspace->id)->delete();
        });
    }

    /**
     * @param  array<string, string>  $rates
     */
    public static function rates(Workspace $workspace, array $rates, CarbonImmutable $from, CarbonImmutable $to, ?User $creator): void
    {
        $date = $from->startOfDay();
        $rows = [];

        while ($date->lessThanOrEqualTo($to)) {
            foreach ($rates as $quote => $rate) {
                $rows[] = [
                    'workspace_id' => $workspace->id,
                    'quote_currency' => $quote,
                    'base_currency' => strtoupper($workspace->currency),
                    'rate' => PolicyService::normalizeRate($rate),
                    'effective_date' => $date->toDateString(),
                    'source' => 'seed',
                    'created_by' => $creator?->id,
                    'created_at' => CarbonImmutable::now(),
                    'updated_at' => CarbonImmutable::now(),
                ];
            }

            $date = $date->equalTo($to->startOfDay()) ? $date->addDay() : min($date->addDays(7), $to->startOfDay());
        }

        DB::table('exchange_rates')->upsert($rows, ['workspace_id', 'quote_currency', 'effective_date'], ['rate', 'source', 'updated_at']);
    }
}
