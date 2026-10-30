<?php

declare(strict_types=1);

namespace App\Http\Requests\Domain;

use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Money\MoneyParser;
use App\Domain\Risk\Enums\RiskLevel;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class RuleRequest extends DomainFormRequest
{
    protected function ability(): string
    {
        return 'policies.manage';
    }

    public const array ROLES = ['owner', 'admin', 'approver'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['required', 'boolean'],
            'min_amount' => ['nullable', 'string', 'max:20'],
            'max_amount' => ['nullable', 'string', 'max:20'],
            'kinds' => ['nullable', 'array'],
            'kinds.*' => [Rule::enum(PaymentRequestKind::class)],
            'min_risk_level' => ['nullable', Rule::enum(RiskLevel::class)],
            'applies_to_new_vendor' => ['nullable', 'boolean'],
            'applies_to_bank_change' => ['nullable', 'boolean'],
            'approvals_required' => ['required', 'integer', 'min:1', 'max:5'],
            'required_roles' => ['present', 'array'],
            'required_roles.*' => [Rule::in(self::ROLES)],
            'approver_roles' => ['required', 'array', 'min:1'],
            'approver_roles.*' => [Rule::in(self::ROLES)],
            'verification_required' => ['required', 'boolean'],
            'expiry_hours' => ['nullable', 'integer', 'min:4', 'max:168'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $data = $validator->getData();
            $min = $this->minor($data['min_amount'] ?? null);
            $max = $this->minor($data['max_amount'] ?? null);

            foreach (['min_amount' => $min, 'max_amount' => $max] as $key => $value) {
                if (is_string($data[$key] ?? null) && trim((string) $data[$key]) !== '' && $value === null) {
                    $validator->errors()->add($key, (string) __('domain.validation.amount'));
                }
            }

            if ($min !== null && $max !== null && $max <= $min) {
                $validator->errors()->add('max_amount', (string) __('domain.errors.max_gt_min'));
            }

            $required = is_array($data['required_roles'] ?? null) ? count(array_unique($data['required_roles'])) : 0;

            if ($required > (int) ($data['approvals_required'] ?? 1)) {
                $validator->errors()->add('approvals_required', (string) __('domain.errors.approvals_below_roles'));
            }
        }];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $validated = $this->validated();

        return [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => (bool) $validated['is_active'],
            'min_amount_base_minor' => $this->minor($validated['min_amount'] ?? null),
            'max_amount_base_minor' => $this->minor($validated['max_amount'] ?? null),
            'kinds' => $validated['kinds'] ?? null,
            'min_risk_level' => $validated['min_risk_level'] ?? null,
            'applies_to_new_vendor' => isset($validated['applies_to_new_vendor']) ? (bool) $validated['applies_to_new_vendor'] : null,
            'applies_to_bank_change' => isset($validated['applies_to_bank_change']) ? (bool) $validated['applies_to_bank_change'] : null,
            'approvals_required' => (int) $validated['approvals_required'],
            'required_roles' => $validated['required_roles'] ?? [],
            'approver_roles' => $validated['approver_roles'],
            'verification_required' => (bool) $validated['verification_required'],
            'expiry_hours' => isset($validated['expiry_hours']) ? (int) $validated['expiry_hours'] : null,
        ];
    }

    private function minor(mixed $value): ?int
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $currency = strtoupper((string) app(CurrentWorkspace::class)->get()?->currency);

        return MoneyParser::toMinor($value, $currency === '' ? 'USD' : $currency);
    }
}
