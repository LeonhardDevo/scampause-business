<?php

declare(strict_types=1);

namespace App\Http\Requests\Domain;

use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AssignVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'vendor_id' => ['present', 'nullable', 'integer', Rule::exists('vendors', 'id')->where('workspace_id', app(CurrentWorkspace::class)->id())->whereNull('deleted_at')],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['vendor_id' => (string) __('domain.attributes.vendor_id')];
    }

    public function vendorId(): ?int
    {
        $vendor = $this->validated('vendor_id');

        return is_numeric($vendor) ? (int) $vendor : null;
    }
}
