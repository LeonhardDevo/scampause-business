<?php

declare(strict_types=1);

namespace App\Http\Requests\Domain;

use App\Domain\Intake\Data\ManualEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ReviewPaymentRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return PaymentRequestFields::rules(partial: true);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return PaymentRequestFields::attributes();
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [static fn (Validator $validator) => PaymentRequestFields::after($validator, true)];
    }

    public function entry(): ManualEntry
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();
        $provided = array_map('strval', array_keys($validated));

        if (in_array('amount', $provided, true) && ! in_array('currency', $provided, true)) {
            $provided[] = 'currency';
        }

        return PaymentRequestFields::entry($validated, $provided);
    }
}
