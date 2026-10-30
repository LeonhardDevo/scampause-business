<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Domain\Billing\Entitlements;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Policy\Data\PolicyInput;
use App\Domain\Policy\PaymentSettings;
use App\Domain\Risk\Enums\RiskLevel;
use App\Domain\Risk\Models\RiskSignal;
use App\Models\Workspace;

final readonly class ApprovalGate
{
    public const string PLAN = 'plan';

    public const string DISABLED = 'disabled';

    public const string BANK_CHANGE = 'bank_change';

    public const string HIGH_RISK = 'high_risk';

    public function __construct(private Entitlements $entitlements) {}

    public function telegramBlockReason(PaymentRequest $request, Workspace $workspace): ?string
    {
        $settings = PaymentSettings::for($workspace);

        if (! $this->entitlements->allows($workspace, 'telegram_approvals')) {
            return self::PLAN;
        }

        if (! (bool) $settings->getAttribute('telegram_approvals_enabled')) {
            return self::DISABLED;
        }

        if (self::hasWebOnlySignal($request)) {
            return self::BANK_CHANGE;
        }

        $max = $settings->getAttribute('telegram_approve_max_risk');
        $max = $max instanceof RiskLevel ? $max : RiskLevel::Medium;
        $level = $request->getAttribute('risk_level');

        if (! $level instanceof RiskLevel || $level->rank() > $max->rank()) {
            return self::HIGH_RISK;
        }

        return null;
    }

    public function telegramAllowed(PaymentRequest $request, Workspace $workspace): bool
    {
        return $this->telegramBlockReason($request, $workspace) === null;
    }

    public static function inlineApproveAllowed(PaymentRequest $request): bool
    {
        $level = $request->getAttribute('risk_level');

        return $level instanceof RiskLevel && $level->rank() <= RiskLevel::Medium->rank() && ! self::hasWebOnlySignal($request);
    }

    public static function hasWebOnlySignal(PaymentRequest $request): bool
    {
        $assessment = $request->getAttribute('risk_assessment_id');

        if (! is_int($assessment)) {
            return false;
        }

        return RiskSignal::query()->forWorkspace((int) $request->getAttribute('workspace_id'))
            ->where('risk_assessment_id', $assessment)
            ->whereIn('code', PolicyInput::webOnlySignals())
            ->exists();
    }
}
