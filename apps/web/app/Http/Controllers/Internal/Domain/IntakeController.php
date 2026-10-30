<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal\Domain;

use App\Domain\Intake\Data\ExtractionResult;
use App\Domain\Intake\DomainIntelService;
use App\Domain\Intake\Enums\FileRole;
use App\Domain\Intake\Enums\PipelineStage;
use App\Domain\Intake\Enums\PipelineState;
use App\Domain\Intake\Exceptions\InvalidTransition;
use App\Domain\Intake\Files\FileVault;
use App\Domain\Intake\Files\UploadInspector;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\Models\PaymentRequestFile;
use App\Domain\Intake\PipelineService;
use App\Domain\Intake\Presenters\Refs;
use App\Domain\Policy\PaymentSettings;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class IntakeController extends Controller
{
    public function __construct(private readonly PipelineService $pipeline) {}

    public function intake(Request $request, int $paymentRequest): JsonResponse
    {
        [$workspace, $model] = $this->resolve($request, $paymentRequest);

        if ($model === null) {
            return self::notFound();
        }

        $files = PaymentRequestFile::query()->forWorkspace($workspace)
            ->where('payment_request_id', $model->getKey())
            ->whereIn('role', [FileRole::Original->value, FileRole::Attachment->value])
            ->whereNull('purged_at')
            ->orderBy('id')
            ->get()
            ->map(fn (PaymentRequestFile $file): array => [
                'id' => (int) $file->getKey(),
                'role' => (string) Refs::enum($file->getAttribute('role')),
                'mime' => (string) $file->getAttribute('mime'),
                'original_name' => (string) $file->getAttribute('original_name'),
                'size_bytes' => (int) $file->getAttribute('size_bytes'),
                'sha256' => (string) $file->getAttribute('sha256'),
                'parent_file_id' => is_int($p = $file->getAttribute('parent_file_id')) ? $p : null,
            ])->values()->all();

        return response()->json([
            'payment_request_id' => (int) $model->getKey(),
            'revision' => (int) $model->getAttribute('revision'),
            'locale' => $workspace->locale === 'ru' ? 'ru' : 'en',
            'country' => PaymentSettings::country($workspace),
            'files' => $files,
        ]);
    }

    public function file(Request $request, int $file, FileVault $vault): Response
    {
        $workspace = $this->workspace($request);

        if ($workspace === null) {
            return self::notFound();
        }

        $model = PaymentRequestFile::query()->forWorkspace($workspace)->find($file);
        $bytes = $model !== null ? $vault->read($model) : null;

        if ($model === null || $bytes === null) {
            return self::notFound();
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/octet-stream',
            'X-Sha256' => (string) $model->getAttribute('sha256'),
            'X-File-Mime' => (string) $model->getAttribute('mime'),
            'Cache-Control' => 'no-store',
        ]);
    }

    public function storeFile(Request $request, int $paymentRequest, FileVault $vault): JsonResponse
    {
        $request->validate([
            'workspace_id' => ['required', 'integer'],
            'revision' => ['required', 'integer'],
            'role' => ['required', Rule::in([FileRole::Attachment->value])],
            'parent_file_id' => ['required', 'integer'],
            'file' => ['required_without:content_base64', 'file', 'max:'.(int) config('scampause.uploads.max_file_kb', 15360)],
            'content_base64' => ['required_without:file', 'string'],
            'name' => ['required_with:content_base64', 'string', 'max:255'],
        ]);
        [$workspace, $model] = $this->resolve($request, $paymentRequest);

        if ($model === null) {
            return self::notFound();
        }

        if ((int) $request->input('revision') !== (int) $model->getAttribute('revision')) {
            return self::conflict('stale_revision');
        }

        $parent = $this->ownFile($model, (int) $request->input('parent_file_id'));
        [$bytes, $name] = self::payload($request, 'file', (int) config('scampause.uploads.max_file_kb', 15360));

        if ($bytes === null) {
            return response()->json(['error' => 'invalid_file'], 422);
        }

        $inspection = (new UploadInspector)->inspect($bytes, $name);

        if ($parent === null || $inspection['mime'] === null) {
            return response()->json(['error' => $parent === null ? 'parent_not_found' : (string) $inspection['error']], 422);
        }

        $sha = hash('sha256', $bytes);
        $existing = PaymentRequestFile::query()->forWorkspace($workspace)
            ->where('payment_request_id', $model->getKey())
            ->where('sha256', $sha)
            ->where('role', FileRole::Attachment->value)
            ->value('id');

        if (is_numeric($existing)) {
            return response()->json(['file_id' => (int) $existing]);
        }

        $count = PaymentRequestFile::query()->forWorkspace($workspace)
            ->where('payment_request_id', $model->getKey())
            ->where('role', FileRole::Attachment->value)
            ->count();

        if ($count >= (int) config('scampause.uploads.attachment_max_count', 10)) {
            return response()->json(['error' => 'too_many_attachments'], 422);
        }

        $meta = $vault->store($workspace->id, (int) $model->getKey(), $bytes, UploadInspector::extensionFor((string) $inspection['mime']));
        $created = new PaymentRequestFile;
        $created->forceFill([
            'workspace_id' => $workspace->id,
            'payment_request_id' => $model->getKey(),
            'role' => FileRole::Attachment,
            'parent_file_id' => $parent->getKey(),
            'disk' => $meta['disk'],
            'path' => $meta['path'],
            'original_name' => FileVault::sanitizeName($name),
            'mime' => $inspection['mime'],
            'size_bytes' => $meta['size_bytes'],
            'sha256' => $meta['sha256'],
        ])->save();

        return response()->json(['file_id' => (int) $created->getKey()], 201);
    }

    public function storePreview(Request $request, int $paymentRequest, FileVault $vault): JsonResponse
    {
        $request->validate([
            'workspace_id' => ['required', 'integer'],
            'revision' => ['required', 'integer'],
            'file_id' => ['required', 'integer'],
            'page' => ['required', 'integer', 'min:1', 'max:'.(int) config('scampause.uploads.preview_max_pages', 10)],
            'png' => ['required_without:content_base64', 'file', 'max:'.(int) config('scampause.uploads.preview_max_kb', 4096)],
            'content_base64' => ['required_without:png', 'string'],
        ]);
        [$workspace, $model] = $this->resolve($request, $paymentRequest);

        if ($model === null) {
            return self::notFound();
        }

        if ((int) $request->input('revision') !== (int) $model->getAttribute('revision')) {
            return self::conflict('stale_revision');
        }

        $parent = $this->ownFile($model, (int) $request->input('file_id'));
        [$bytes] = self::payload($request, 'png', (int) config('scampause.uploads.preview_max_kb', 4096));

        if ($parent === null || $bytes === null || (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== 'image/png') {
            return response()->json(['error' => $parent === null ? 'parent_not_found' : 'mime_not_allowed'], 422);
        }

        $page = (int) $request->input('page');
        $existing = PaymentRequestFile::query()->forWorkspace($workspace)
            ->where('payment_request_id', $model->getKey())
            ->where('role', FileRole::Preview->value)
            ->where('parent_file_id', $parent->getKey())
            ->where('page', $page)
            ->value('id');

        if (is_numeric($existing)) {
            return response()->json(['file_id' => (int) $existing]);
        }

        $meta = $vault->store($workspace->id, (int) $model->getKey(), $bytes, 'png');
        $created = new PaymentRequestFile;
        $created->forceFill([
            'workspace_id' => $workspace->id,
            'payment_request_id' => $model->getKey(),
            'role' => FileRole::Preview,
            'parent_file_id' => $parent->getKey(),
            'page' => $page,
            'disk' => $meta['disk'],
            'path' => $meta['path'],
            'original_name' => 'preview-'.$parent->getKey().'-'.$page.'.png',
            'mime' => 'image/png',
            'size_bytes' => $meta['size_bytes'],
            'sha256' => $meta['sha256'],
        ])->save();

        return response()->json(['file_id' => (int) $created->getKey()], 201);
    }

    public function pipeline(Request $request, int $paymentRequest): JsonResponse
    {
        $validated = $request->validate([
            'workspace_id' => ['required', 'integer'],
            'revision' => ['required', 'integer'],
            'stage' => ['required', Rule::in(PipelineStage::wireValues())],
            'state' => ['required', Rule::enum(PipelineState::class)],
            'error' => ['nullable', 'string', 'max:500'],
        ]);
        [$workspace, $model] = $this->resolve($request, $paymentRequest);

        if ($model === null) {
            return self::notFound();
        }

        return $this->guard(function () use ($workspace, $model, $validated): JsonResponse {
            $this->pipeline->markStage(
                $workspace->id,
                (int) $model->getKey(),
                (int) $validated['revision'],
                PipelineStage::fromWire((string) $validated['stage']) ?? PipelineStage::Extract,
                PipelineState::from((string) $validated['state']),
                isset($validated['error']) ? (string) $validated['error'] : null,
            );

            return response()->json(['ok' => true]);
        });
    }

    public function extraction(Request $request, int $paymentRequest): JsonResponse
    {
        $request->validate([
            'workspace_id' => ['required', 'integer'],
            'payment_request_id' => ['required', 'integer', Rule::in([$paymentRequest])],
            'revision' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::in(['succeeded', 'partial', 'failed'])],
            'extractor_version' => ['required', 'string', 'max:32'],
            'ocr_used' => ['required', 'boolean'],
            'pages' => ['required', 'integer', 'min:0'],
            'text' => ['present', 'nullable', 'string'],
            'fields' => ['present', 'array'],
            'warnings' => ['present', 'array'],
            'error_code' => ['nullable', 'string', 'max:64'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
            'llm_assisted' => ['nullable', 'boolean'],
            'files' => ['nullable', 'array'],
        ]);
        [, $model] = $this->resolve($request, $paymentRequest);

        if ($model === null) {
            return self::notFound();
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();
        $result = ExtractionResult::fromArray($payload);

        return $this->guard(fn (): JsonResponse => response()->json($this->pipeline->ingest($model, $result)));
    }

    public function domainIntel(Request $request, DomainIntelService $intel): JsonResponse
    {
        $request->validate(['workspace_id' => ['required', 'integer'], 'domains' => ['required', 'string', 'max:5000']]);
        $workspace = $this->workspace($request);

        if ($workspace === null) {
            return self::notFound();
        }

        $domains = array_values(array_filter(array_map('trim', explode(',', (string) $request->query('domains')))));

        return response()->json($intel->lookup($workspace, $domains));
    }

    public function storeDomainIntel(Request $request, DomainIntelService $intel): JsonResponse
    {
        $request->validate([
            'workspace_id' => ['required', 'integer'],
            'facts' => ['present', 'array', 'max:50'],
            'facts.*.domain' => ['required', 'string', 'max:253'],
            'payment_request_id' => ['nullable', 'integer'],
            'revision' => ['nullable', 'integer'],
        ]);
        $workspace = $this->workspace($request);

        if ($workspace === null) {
            return self::notFound();
        }

        /** @var list<array<string, mixed>> $facts */
        $facts = array_values(array_filter((array) $request->input('facts', []), 'is_array'));

        return response()->json(['stored' => $intel->store($workspace, $facts)]);
    }

    public function bankComparison(Request $request, int $paymentRequest): JsonResponse
    {
        $validated = $request->validate(['workspace_id' => ['required', 'integer'], 'revision' => ['required', 'integer']]);
        [$workspace, $model] = $this->resolve($request, $paymentRequest);

        if ($model === null) {
            return self::notFound();
        }

        return $this->guard(fn (): JsonResponse => response()->json([
            'outcome' => $this->pipeline->compareBank($workspace->id, (int) $model->getKey(), (int) $validated['revision']),
        ]));
    }

    public function assess(Request $request, int $paymentRequest): JsonResponse
    {
        $validated = $request->validate(['workspace_id' => ['required', 'integer'], 'revision' => ['required', 'integer']]);
        [$workspace, $model] = $this->resolve($request, $paymentRequest);

        if ($model === null) {
            return self::notFound();
        }

        return $this->guard(fn (): JsonResponse => response()->json(
            $this->pipeline->assess($workspace->id, (int) $model->getKey(), (int) $validated['revision']),
        ));
    }

    public function sweep(): JsonResponse
    {
        return response()->json($this->pipeline->sweep());
    }

    /**
     * @param  \Closure(): JsonResponse  $action
     */
    private function guard(\Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (InvalidTransition $exception) {
            return self::conflict($exception->errorCode);
        } catch (ModelNotFoundException) {
            return self::notFound();
        }
    }

    /**
     * @return array{0: Workspace, 1: PaymentRequest|null}|array{0: null, 1: null}
     */
    private function resolve(Request $request, int $paymentRequest): array
    {
        $workspace = $this->workspace($request);

        if ($workspace === null) {
            return [null, null];
        }

        return [$workspace, PaymentRequest::query()->forWorkspace($workspace)->find($paymentRequest)];
    }

    private function workspace(Request $request): ?Workspace
    {
        $id = $request->input('workspace_id', $request->query('workspace_id'));

        return is_numeric($id) ? Workspace::query()->find((int) $id) : null;
    }

    private function ownFile(PaymentRequest $model, int $fileId): ?PaymentRequestFile
    {
        return PaymentRequestFile::query()->forWorkspace((int) $model->getAttribute('workspace_id'))
            ->where('payment_request_id', $model->getKey())
            ->whereIn('role', [FileRole::Original->value, FileRole::Attachment->value])
            ->find($fileId);
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private static function payload(Request $request, string $field, int $maxKb): array
    {
        $upload = $request->file($field);

        if ($upload instanceof UploadedFile) {
            $bytes = (string) file_get_contents((string) $upload->getRealPath());
            $declared = $request->headers->get('X-Content-Sha256');

            if ($declared !== null && ! hash_equals(strtolower($declared), hash('sha256', $bytes))) {
                return [null, $upload->getClientOriginalName()];
            }

            return [$bytes, $upload->getClientOriginalName()];
        }

        $encoded = $request->input('content_base64');
        $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
        $name = is_string($request->input('name')) ? (string) $request->input('name') : $field;

        if ($bytes === false || $bytes === '' || strlen($bytes) > $maxKb * 1024) {
            return [null, $name];
        }

        return [$bytes, $name];
    }

    private static function notFound(): JsonResponse
    {
        return response()->json(['error' => 'not_found'], 404);
    }

    private static function conflict(string $code): JsonResponse
    {
        return response()->json(['error' => $code], 409);
    }
}
