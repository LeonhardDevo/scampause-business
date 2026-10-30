<?php

declare(strict_types=1);

namespace App\Http\Requests\Domain;

use App\Domain\Vendors\Enums\BankScheme;
use App\Domain\Vendors\Enums\BlockReason;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class BankAccountRequest extends DomainFormRequest
{
    protected function ability(): string
    {
        return $this->routeIs('blocked-accounts.store') ? 'blocklist.manage' : 'vendors.manage';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->routeIs('blocked-accounts.store')) {
            return [
                ...BankAccountChecks::rules(false),
                'reason' => ['sometimes', Rule::in([BlockReason::Manual->value])],
                'note' => ['nullable', 'string', 'max:1000'],
            ];
        }

        return BankAccountChecks::rules();
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [static fn (Validator $validator) => BankAccountChecks::check($validator)];
    }

    /**
     * @return array{scheme: BankScheme, account_number: string, routing_code: string|null, bic: string|null, account_holder: string|null, bank_name: string|null, bank_country: string|null, currency: string|null, correspondent_account: string|null}
     */
    public function payload(): array
    {
        return BankAccountChecks::data($this->validated());
    }
}
