<?php

declare(strict_types=1);

namespace App\Domain\Policy\Data;

use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Risk\Enums\RiskLevel;

final readonly class PolicyInput
{
    public function __construct(
        public ?int $amountBaseMinor,
        public PaymentRequestKind $kind = PaymentRequestKind::Invoice,
        public RiskLevel $riskLevel = RiskLevel::Low,
        public bool $isNewVendor = false,
        public bool $hasBankChange = false,
        public string $baseCurrency = 'USD',
    ) {}

    /**
     * @param  list<string>  $signalCodes
     */
    public static function newVendorFrom(?int $vendorId, array $signalCodes): bool
    {
        return $vendorId === null || in_array('NEW_VENDOR', $signalCodes, true) || in_array('BANK_ACCOUNT_FIRST_USE', $signalCodes, true);
    }

    /**
     * @param  list<string>  $signalCodes
     */
    public static function bankChangeFrom(array $signalCodes): bool
    {
        return array_intersect($signalCodes, self::bankChangeSignals()) !== [];
    }

    /** @return list<string> */
    public static function bankChangeSignals(): array
    {
        return array_values(array_filter((array) config('scampause.bank_change_signals', []), 'is_string'));
    }

    /** @return list<string> */
    public static function webOnlySignals(): array
    {
        return array_values(array_filter((array) config('scampause.web_only_signals', []), 'is_string'));
    }
}
