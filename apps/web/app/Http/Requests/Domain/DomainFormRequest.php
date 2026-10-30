<?php

declare(strict_types=1);

namespace App\Http\Requests\Domain;

use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;

abstract class DomainFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ability = $this->ability();

        return $ability === null || app(CurrentWorkspace::class)->allows($ability);
    }

    protected function ability(): ?string
    {
        return null;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $attributes = __('domain.attributes');
        $out = [];

        foreach (is_array($attributes) ? $attributes : [] as $key => $label) {
            if (is_string($label)) {
                $out[(string) $key] = $label;
            }
        }

        return $out;
    }

    public function optionalString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function optionalInt(string $key): ?int
    {
        $value = $this->validated($key);

        return is_numeric($value) ? (int) $value : null;
    }
}
