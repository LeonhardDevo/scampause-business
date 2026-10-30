<?php

declare(strict_types=1);

namespace App\Http\Requests\Domain;

use App\Domain\Vendors\Enums\ContactSource;
use Illuminate\Validation\Rule;

final class ContactRequest extends DomainFormRequest
{
    protected function ability(): string
    {
        return 'vendors.manage';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'role_title' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:40', 'required_if_accepted:is_callback'],
            'is_callback' => ['required', 'boolean'],
            'source' => ['required', Rule::enum(ContactSource::class)],
            'source_ref' => ['nullable', 'string', 'max:255', 'required_if_accepted:is_callback'],
        ];
    }

    /**
     * @return array{name: string, role_title: string|null, email: string|null, phone: string|null, is_callback: bool, source: ContactSource, source_ref: string|null}
     */
    public function payload(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'role_title' => $this->optionalString('role_title'),
            'email' => $this->optionalString('email'),
            'phone' => $this->optionalString('phone'),
            'is_callback' => $this->boolean('is_callback'),
            'source' => ContactSource::from((string) $this->validated('source')),
            'source_ref' => $this->optionalString('source_ref'),
        ];
    }
}
