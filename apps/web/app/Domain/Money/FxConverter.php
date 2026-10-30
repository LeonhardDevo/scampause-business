<?php

declare(strict_types=1);

namespace App\Domain\Money;

use App\Domain\Money\Models\ExchangeRate;
use Carbon\CarbonImmutable;

final class FxConverter
{
    public const int LOOKBACK_DAYS = 31;

    public function convert(int $workspaceId, ?int $amountMinor, ?string $currency, string $baseCurrency, CarbonImmutable $onDate): FxConversion
    {
        if ($amountMinor === null || $currency === null) {
            return new FxConversion(null, null, null, false);
        }

        $currency = strtoupper($currency);
        $baseCurrency = strtoupper($baseCurrency);

        if ($currency === $baseCurrency) {
            return new FxConversion($amountMinor, '1.00000000', $onDate->startOfDay(), false);
        }

        $rate = ExchangeRate::query()
            ->forWorkspace($workspaceId)
            ->where('quote_currency', $currency)
            ->where('base_currency', $baseCurrency)
            ->whereDate('effective_date', '<=', $onDate->toDateString())
            ->whereDate('effective_date', '>=', $onDate->subDays(self::LOOKBACK_DAYS)->toDateString())
            ->orderByDesc('effective_date')
            ->first();

        if ($rate === null) {
            return FxConversion::missing();
        }

        $rateValue = (string) $rate->getAttribute('rate');
        /** @var CarbonImmutable $effective */
        $effective = $rate->getAttribute('effective_date');

        return new FxConversion(
            DecimalMul::convert($amountMinor, $rateValue, Currencies::exponent($currency), Currencies::exponent($baseCurrency)),
            DecimalMul::normalizeRate($rateValue),
            $effective,
            false,
        );
    }
}
