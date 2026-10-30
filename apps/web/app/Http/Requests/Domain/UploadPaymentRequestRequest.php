<?php

declare(strict_types=1);

namespace App\Http\Requests\Domain;

use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Files\UploadInspector;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UploadPaymentRequestRequest extends FormRequest
{
    /** @var list<array{bytes: string, name: string, mime: string}> */
    private array $inspected = [];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $workspaceId = app(CurrentWorkspace::class)->id();

        return [
            'files' => ['required', 'array', 'min:1', 'max:'.(int) config('scampause.uploads.max_files', 5)],
            'files.*' => ['required', 'file', 'max:'.(int) config('scampause.uploads.max_file_kb', 15360)],
            'kind' => ['nullable', Rule::enum(PaymentRequestKind::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at')],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'files' => (string) __('domain.attributes.files'),
            'files.*' => (string) __('domain.attributes.file'),
            'kind' => (string) __('domain.attributes.kind'),
            'description' => (string) __('domain.attributes.description'),
            'vendor_id' => (string) __('domain.attributes.vendor_id'),
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $files = $this->file('files');
            $files = is_array($files) ? $files : [];
            $total = 0;
            $inspector = new UploadInspector;

            foreach (array_values($files) as $index => $file) {
                $total += (int) $file->getSize();
                $name = $file->getClientOriginalName();

                if (! UploadInspector::allowsExtension($name)) {
                    $validator->errors()->add('files.'.$index, (string) __('domain.validation.mime_not_allowed', ['name' => $name]));

                    continue;
                }

                $bytes = (string) file_get_contents((string) $file->getRealPath());
                $result = $inspector->inspect($bytes, $name);

                if ($result['mime'] === null) {
                    $validator->errors()->add('files.'.$index, (string) __('domain.validation.'.$result['error'], ['name' => $name]));

                    continue;
                }

                $this->inspected[] = ['bytes' => $bytes, 'name' => $name, 'mime' => $result['mime']];
            }

            if ($total > (int) config('scampause.uploads.max_total_kb', 40960) * 1024) {
                $validator->errors()->add('files', (string) __('domain.validation.total_size', ['mb' => intdiv((int) config('scampause.uploads.max_total_kb', 40960), 1024)]));
            }
        }];
    }

    /** @return list<array{bytes: string, name: string, mime: string}> */
    public function inspectedFiles(): array
    {
        return $this->inspected;
    }

    public function kind(): ?PaymentRequestKind
    {
        $kind = $this->validated('kind');

        return is_string($kind) ? PaymentRequestKind::tryFrom($kind) : null;
    }

    public function vendorId(): ?int
    {
        $vendor = $this->validated('vendor_id');

        return is_numeric($vendor) ? (int) $vendor : null;
    }

    public function description(): ?string
    {
        $description = $this->validated('description');

        return is_string($description) ? $description : null;
    }
}
