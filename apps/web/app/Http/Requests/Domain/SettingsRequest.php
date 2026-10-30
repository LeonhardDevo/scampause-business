<?php

declare(strict_types=1);

namespace App\Http\Requests\Domain;

use App\Domain\Money\MoneyParser;
use App\Domain\Support\Normalize\DomainNormalizer;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SettingsRequest extends DomainFormRequest
{
    protected function ability(): string
    {
        return 'policies.manage';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'own_domains' => ['present', 'array', 'max:10'],
            'own_domains.*' => ['string', 'max:253'],
            'business_days' => ['required', 'array', 'min:1', 'max:7'],
            'business_days.*' => ['integer', 'min:1', 'max:7', 'distinct'],
            'business_hours_start' => ['required', 'date_format:H:i'],
            'business_hours_end' => ['required', 'date_format:H:i', 'after:business_hours_start'],
            'quiet_hours_start' => ['required', 'date_format:H:i'],
            'quiet_hours_end' => ['required', 'date_format:H:i'],
            'default_expiry_hours' => ['required', 'integer', 'min:4', 'max:168'],
            'reminder_offsets_hours' => ['present', 'array', 'max:4'],
            'reminder_offsets_hours.*' => ['integer', 'min:1', 'max:167'],
            'escalate_after_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'telegram_approvals_enabled' => ['required', 'boolean'],
            'telegram_approve_max_risk' => ['required', Rule::in(['low', 'medium', 'high'])],
            'verification_amount_threshold' => ['required', 'string', 'max:20'],
            'verification_valid_days' => ['required', 'integer', 'min:1', 'max:30'],
            'country' => ['sometimes', 'string', 'size:2', 'alpha'],
            'callback_cooldown_days' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'new_approver_grace_hours' => ['sometimes', 'integer', 'min:0', 'max:168'],
            'ai_extract_assist' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $data = $validator->getData();

            foreach (is_array($data['own_domains'] ?? null) ? $data['own_domains'] : [] as $index => $domain) {
                if (! is_string($domain) || DomainNormalizer::registrable($domain) === null) {
                    $validator->errors()->add('own_domains.'.$index, (string) __('domain.errors.invalid_domain'));
                }
            }

            $offsets = is_array($data['reminder_offsets_hours'] ?? null) ? array_values($data['reminder_offsets_hours']) : [];
            $sorted = $offsets;
            sort($sorted);

            if ($offsets !== $sorted || count($offsets) !== count(array_unique($offsets))) {
                $validator->errors()->add('reminder_offsets_hours', (string) __('domain.errors.offsets_ascending'));
            }

            foreach ($offsets as $offset) {
                if ((int) $offset >= (int) ($data['default_expiry_hours'] ?? 0)) {
                    $validator->errors()->add('reminder_offsets_hours', (string) __('domain.errors.offsets_before_expiry'));

                    break;
                }
            }

            if ($this->threshold() === null) {
                $validator->errors()->add('verification_amount_threshold', (string) __('domain.validation.amount'));
            }
        }];
    }

    public function threshold(): ?int
    {
        $value = $this->input('verification_amount_threshold');
        $currency = strtoupper((string) app(CurrentWorkspace::class)->get()?->currency);

        return is_string($value) ? MoneyParser::toMinor($value, $currency === '' ? 'USD' : $currency) : null;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $validated = $this->validated();
        $domains = [];

        foreach ($validated['own_domains'] ?? [] as $domain) {
            $normalized = DomainNormalizer::registrable((string) $domain);

            if ($normalized !== null && ! in_array($normalized, $domains, true)) {
                $domains[] = $normalized;
            }
        }

        $data = [
            'own_domains' => $domains,
            'business_days' => array_values(array_map('intval', $validated['business_days'])),
            'business_hours_start' => $validated['business_hours_start'],
            'business_hours_end' => $validated['business_hours_end'],
            'quiet_hours_start' => $validated['quiet_hours_start'],
            'quiet_hours_end' => $validated['quiet_hours_end'],
            'default_expiry_hours' => (int) $validated['default_expiry_hours'],
            'reminder_offsets_hours' => array_values(array_map('intval', $validated['reminder_offsets_hours'] ?? [])),
            'escalate_after_hours' => (int) $validated['escalate_after_hours'],
            'telegram_approvals_enabled' => (bool) $validated['telegram_approvals_enabled'],
            'telegram_approve_max_risk' => $validated['telegram_approve_max_risk'],
            'verification_amount_threshold_minor' => (int) $this->threshold(),
            'verification_valid_days' => (int) $validated['verification_valid_days'],
        ];

        foreach (['callback_cooldown_days', 'new_approver_grace_hours'] as $key) {
            if (array_key_exists($key, $validated)) {
                $data[$key] = (int) $validated[$key];
            }
        }

        if (array_key_exists('country', $validated)) {
            $data['country'] = strtoupper((string) $validated['country']);
        }

        if (array_key_exists('ai_extract_assist', $validated)) {
            $data['ai_extract_assist'] = (bool) $validated['ai_extract_assist'];
        }

        return $data;
    }
}
