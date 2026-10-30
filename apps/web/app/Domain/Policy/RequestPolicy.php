<?php

declare(strict_types=1);

namespace App\Domain\Policy;

use App\Domain\Approvals\ApproverPool;
use App\Domain\Intake\Contracts\PolicyPreview;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Policy\Data\PolicyEvaluation;
use App\Domain\Policy\Data\PolicyInput;
use App\Domain\Policy\Data\PolicyRule;
use App\Domain\Policy\Models\ApprovalRule;
use App\Domain\Risk\Contracts\PolicyVerificationResolver;
use App\Domain\Risk\Enums\RiskLevel;
use App\Domain\Risk\Models\RiskSignal;
use App\Domain\Risk\RiskResult;
use App\Models\Workspace;

final readonly class RequestPolicy implements PolicyPreview, PolicyVerificationResolver
{
    public function __construct(private ApproverPool $pool) {}

    public function resolve(PaymentRequest $request, RiskResult $preliminary): array
    {
        $workspace = Workspace::query()->findOrFail((int) $request->getAttribute('workspace_id'));
        $evaluation = $this->evaluate($workspace, $this->inputFor($request, $preliminary->codes(), $preliminary->level));

        return [
            'required' => $evaluation->verificationRequired,
            'reasons' => $evaluation->verificationRequired
                ? array_map(static fn (int $id): string => 'rule:'.$id, $evaluation->ruleIds())
                : [],
        ];
    }

    public function preview(PaymentRequest $request): array
    {
        return $this->forRequest($request)->toArray();
    }

    public function forRequest(PaymentRequest $request): PolicyEvaluation
    {
        $workspace = Workspace::query()->findOrFail((int) $request->getAttribute('workspace_id'));
        $level = $request->getAttribute('risk_level');
        $evaluation = $this->evaluate($workspace, $this->inputFor($request, $this->signalCodes($request), $level instanceof RiskLevel ? $level : RiskLevel::Low));
        $excluded = array_values(array_filter([
            (int) $request->getAttribute('requested_by'),
            is_int($s = $request->getAttribute('submitted_by')) ? $s : null,
        ]));
        $solo = $this->pool->soloMode($workspace);
        $eligible = $this->pool->eligible($workspace, $evaluation->approverRoles, $evaluation->amountBaseMinor, $excluded, (int) $request->getKey(), $solo);

        return $evaluation->withEligible(count($eligible));
    }

    public function evaluate(Workspace $workspace, PolicyInput $input): PolicyEvaluation
    {
        return PolicyEvaluator::evaluate(
            $this->rules($workspace),
            $input,
            (int) PaymentSettings::for($workspace)->getAttribute('default_expiry_hours'),
        );
    }

    public function simulate(Workspace $workspace, PolicyInput $input): PolicyEvaluation
    {
        $evaluation = $this->evaluate($workspace, $input);
        $eligible = $this->pool->eligible($workspace, $evaluation->approverRoles, $evaluation->amountBaseMinor, [], null, $this->pool->soloMode($workspace));

        return $evaluation->withEligible(count($eligible));
    }

    /**
     * @return list<PolicyRule>
     */
    public function rules(Workspace $workspace): array
    {
        return array_values(ApprovalRule::query()->forWorkspace($workspace)
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(static fn (ApprovalRule $rule): PolicyRule => PolicyRule::fromModel($rule))
            ->all());
    }

    /**
     * @param  list<string>  $codes
     */
    public function inputFor(PaymentRequest $request, array $codes, RiskLevel $level): PolicyInput
    {
        /** @var PaymentRequestKind $kind */
        $kind = $request->getAttribute('kind');
        $amount = $request->getAttribute('amount_minor');
        $base = $request->getAttribute('amount_base_minor');
        $announcement = in_array($kind, [PaymentRequestKind::BankDetailsChange, PaymentRequestKind::PayrollChange], true);

        if (! is_int($amount) && $announcement) {
            $amountBase = 0;
        } else {
            $amountBase = is_int($base) ? $base : null;
        }

        return new PolicyInput(
            amountBaseMinor: $amountBase,
            kind: $kind,
            riskLevel: $level,
            isNewVendor: PolicyInput::newVendorFrom(is_int($v = $request->getAttribute('vendor_id')) ? $v : null, $codes),
            hasBankChange: PolicyInput::bankChangeFrom($codes) || ($announcement && ! is_int($amount)),
            baseCurrency: (string) $request->getAttribute('base_currency'),
        );
    }

    /** @return list<string> */
    public function signalCodes(PaymentRequest $request): array
    {
        $assessment = $request->getAttribute('risk_assessment_id');

        if (! is_int($assessment)) {
            return [];
        }

        return array_values(RiskSignal::query()->forWorkspace((int) $request->getAttribute('workspace_id'))
            ->where('risk_assessment_id', $assessment)
            ->pluck('code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all());
    }
}
