<?php

declare(strict_types=1);

namespace App\Http\Requests\Domain;

use App\Domain\Intake\Enums\DecisionReason;
use Illuminate\Validation\Rule;

final class DecisionRequest extends DomainFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $commentRequired = $this->routeIs('payment-requests.revoke') ? 'required' : 'required_if:reason,other';

        return [
            'reason' => ['required', Rule::enum(DecisionReason::class)],
            'comment' => ['nullable', $commentRequired, 'string', 'max:2000'],
        ];
    }

    public function reason(): DecisionReason
    {
        return DecisionReason::from((string) $this->validated('reason'));
    }

    public function comment(): ?string
    {
        return $this->optionalString('comment');
    }
}
