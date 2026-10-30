<?php

declare(strict_types=1);

namespace App\Http\Requests\Domain;

use App\Domain\Intake\Data\ManualEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ManualPaymentRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return PaymentRequestFields::rules(partial: false);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return PaymentRequestFields::attributes();
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [static fn (Validator $validator) => PaymentRequestFields::after($validator, false)];
    }

    public function entry(): ManualEntry
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return PaymentRequestFields::entry($validated, array_map('strval', array_keys(array_filter(
            $validated,
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        ))));
    }
}
