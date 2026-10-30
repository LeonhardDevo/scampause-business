<?php

declare(strict_types=1);

namespace App\Domain\Policy;

use App\Domain\Policy\Models\WorkspacePaymentSettings;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

final class PaymentSettings
{
    public static function for(Workspace|int $workspace): WorkspacePaymentSettings
    {
        $workspaceModel = $workspace instanceof Workspace ? $workspace : Workspace::query()->findOrFail($workspace);
        $existing = WorkspacePaymentSettings::query()->find($workspaceModel->id);

        return $existing ?? self::ensure($workspaceModel);
    }

    public static function ensure(Workspace $workspace): WorkspacePaymentSettings
    {
        DB::table('workspace_payment_settings')->insertOrIgnore([
            'workspace_id' => $workspace->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($workspace->country === null) {
            $workspace->forceFill(['country' => self::defaultCountry($workspace)])->save();
        }

        return WorkspacePaymentSettings::query()->findOrFail($workspace->id);
    }

    public static function country(Workspace $workspace): string
    {
        $country = $workspace->country;

        return is_string($country) && preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : self::defaultCountry($workspace);
    }

    public static function defaultCountry(Workspace $workspace): string
    {
        return $workspace->locale === 'ru' || $workspace->currency === 'RUB' ? 'RU' : 'US';
    }

    /** @return list<string> */
    public static function ownDomains(WorkspacePaymentSettings $settings): array
    {
        $domains = $settings->getAttribute('own_domains');

        return is_array($domains) ? array_values(array_filter($domains, 'is_string')) : [];
    }
}
