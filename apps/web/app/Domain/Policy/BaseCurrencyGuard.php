<?php

declare(strict_types=1);

namespace App\Domain\Policy;

use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BaseCurrencyGuard
{
    public const array TABLES = ['payment_requests', 'approval_rules', 'member_limits', 'exchange_rates', 'vendor_bank_accounts'];

    public static function check(Workspace $workspace): void
    {
        if (! $workspace->exists || ! $workspace->isDirty('currency')) {
            return;
        }

        $before = strtoupper((string) $workspace->getOriginal('currency'));
        $after = strtoupper((string) $workspace->getAttribute('currency'));

        if ($before === $after || ! self::locked($workspace->id)) {
            return;
        }

        throw ValidationException::withMessages(['currency' => __('domain.validation.base_currency_locked')]);
    }

    public static function locked(int $workspaceId): bool
    {
        foreach (self::TABLES as $table) {
            if (DB::table($table)->where('workspace_id', $workspaceId)->exists()) {
                return true;
            }
        }

        return false;
    }
}
