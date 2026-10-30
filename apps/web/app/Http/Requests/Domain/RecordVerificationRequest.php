<?php

declare(strict_types=1);

namespace App\Http\Requests\Domain;

use App\Domain\Intake\Enums\DecisionReason;
use App\Domain\Verification\Data\VerificationInput;
use App\Domain\Verification\Enums\VerificationMethod;
use App\Domain\Verification\Enums\VerificationOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

final class RecordVerificationRequest extends DomainFormRequest
{
    protected function ability(): string
    {
        return $this->routeIs('vendors.bank-accounts.verify') ? 'vendors.verify_bank' : 'payment_requests.verify';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'method' => ['required', Rule::enum(VerificationMethod::class)],
            'contact_id' => ['nullable', 'integer', 'required_unless:method,in_person,vendor_portal'],
            'spoken_to_name' => ['required', 'string', 'min:2', 'max:160'],
            'outcome' => ['required', Rule::enum(VerificationOutcome::class)],
            'occurred_at' => ['required', 'date'],
            'checklist' => ['nullable', 'array'],
            'checklist.account_last4' => ['nullable', 'boolean'],
            'checklist.amount' => ['nullable', 'boolean'],
            'checklist.account_holder' => ['nullable', 'boolean'],
            'checklist.change_initiated_by_vendor' => ['nullable', 'boolean'],
            'checklist.invoice_number' => ['nullable', 'boolean'],
            'decision_reason' => ['nullable', 'required_if:outcome,denied', Rule::in([DecisionReason::FraudConfirmed->value, DecisionReason::DetailsMismatch->value])],
            'replaces_account_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function verification(): VerificationInput
    {
        $checklist = $this->validated('checklist');
        $out = [];

        foreach (is_array($checklist) ? $checklist : [] as $key => $value) {
            $out[(string) $key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return new VerificationInput(
            method: VerificationMethod::from((string) $this->validated('method')),
            contactId: $this->optionalInt('contact_id'),
            spokenToName: (string) $this->validated('spoken_to_name'),
            outcome: VerificationOutcome::from((string) $this->validated('outcome')),
            occurredAt: CarbonImmutable::parse((string) $this->validated('occurred_at'))->utc(),
            checklist: $out,
            decisionReason: is_string($r = $this->validated('decision_reason')) ? DecisionReason::from($r) : null,
            replacesAccountId: $this->optionalInt('replaces_account_id'),
            notes: $this->optionalString('notes'),
        );
    }
}
