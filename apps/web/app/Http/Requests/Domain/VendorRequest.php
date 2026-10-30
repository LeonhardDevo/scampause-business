<?php

declare(strict_types=1);

namespace App\Http\Requests\Domain;

use App\Domain\Money\Currencies;
use App\Domain\Support\BankValidators;
use App\Domain\Vendors\Enums\VendorKind;
use App\Domain\Vendors\Enums\VendorStatus;
use App\Domain\Vendors\Models\Vendor;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class VendorRequest extends DomainFormRequest
{
    protected function ability(): string
    {
        return 'vendors.manage';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(VendorKind::class)],
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'aliases' => ['nullable', 'array', 'max:10'],
            'aliases.*' => ['string', 'max:120'],
            'tax_id' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'default_currency' => ['nullable', 'string', 'size:3', Rule::in(Currencies::codes())],
            'status' => ['sometimes', Rule::enum(VendorStatus::class)],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'linked_user_id' => ['nullable', 'integer'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $workspace = app(CurrentWorkspace::class)->get();
            $data = $validator->getData();
            $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
            $vendor = $this->route('vendor');
            $vendorId = $vendor instanceof Vendor ? (int) $vendor->getKey() : null;

            if ($workspace !== null && $name !== '' && Vendor::query()->forWorkspace($workspace)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name, 'UTF-8')])
                ->when($vendorId !== null, fn ($query) => $query->whereKeyNot($vendorId))
                ->exists()) {
                $validator->errors()->add('name', (string) __('domain.errors.vendor_name_taken'));
            }

            $tax = is_string($data['tax_id'] ?? null) ? trim($data['tax_id']) : '';
            $country = is_string($data['country'] ?? null) ? strtoupper($data['country']) : null;
            $digits = (string) preg_replace('/\D+/', '', $tax);

            if ($tax !== '' && $country === 'RU' && in_array(strlen($digits), [10, 12], true) && ! BankValidators::inn($digits)) {
                $validator->errors()->add('tax_id', (string) __('domain.errors.invalid_inn'));
            }

            if ($tax !== '' && $country === 'US' && ! BankValidators::ein($tax)) {
                $validator->errors()->add('tax_id', (string) __('domain.errors.invalid_ein'));
            }

            $linked = $data['linked_user_id'] ?? null;

            if ($linked !== null && $linked !== '') {
                if (($data['kind'] ?? null) !== VendorKind::Employee->value) {
                    $validator->errors()->add('linked_user_id', (string) __('domain.errors.linked_user_only_employee'));
                } elseif ($workspace === null || ! $workspace->members()->where('users.id', (int) $linked)->exists()) {
                    $validator->errors()->add('linked_user_id', (string) __('domain.errors.not_a_member'));
                }
            }
        }];
    }
}
