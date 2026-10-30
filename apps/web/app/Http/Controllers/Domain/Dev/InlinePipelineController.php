<?php

declare(strict_types=1);

namespace App\Http\Controllers\Domain\Dev;

use App\Domain\Intake\Data\ExtractionResult;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Enums\PipelineStage;
use App\Domain\Intake\Enums\PipelineState;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\Pipeline;
use App\Domain\Intake\PipelineService;
use App\Domain\Intake\Presenters\CaseFilePresenter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InlinePipelineController extends Controller
{
    public function __invoke(Request $request, PaymentRequest $paymentRequest, PipelineService $pipeline): JsonResponse
    {
        $this->authorize('view', $paymentRequest);
        $this->authorize('review', $paymentRequest);
        $validated = $request->validate(['extraction' => ['nullable', 'array']]);
        $workspaceId = (int) $paymentRequest->getAttribute('workspace_id');
        $requestId = (int) $paymentRequest->getKey();
        $revision = (int) $paymentRequest->getAttribute('revision');

        if (! $paymentRequest->getAttribute('status') instanceof PaymentRequestStatus || ! $paymentRequest->getAttribute('status')->isPipelineRunning()) {
            return response()->json(['error' => 'invalid_transition'], 409);
        }

        if (Pipeline::of($paymentRequest)->state(PipelineStage::Extract) !== PipelineState::Skipped) {
            $pipeline->markStage($workspaceId, $requestId, $revision, PipelineStage::Extract, PipelineState::Running);
            /** @var array<string, mixed> $extraction */
            $extraction = is_array($validated['extraction'] ?? null) ? $validated['extraction'] : ['status' => 'failed', 'fields' => [], 'text' => ''];
            $pipeline->ingest($paymentRequest, ExtractionResult::fromArray([
                'extractor_version' => 'dev-inline',
                'ocr_used' => false,
                'pages' => 0,
                'warnings' => [],
                'files' => [],
                ...$extraction,
                'workspace_id' => $workspaceId,
                'payment_request_id' => $requestId,
                'revision' => $revision,
            ]));
        }

        $fresh = $paymentRequest->refresh();

        if ($fresh->getAttribute('status') === PaymentRequestStatus::Processing) {
            $pipeline->compareBank($workspaceId, $requestId, $revision);
            $pipeline->assess($workspaceId, $requestId, $revision);
        }

        return response()->json(CaseFilePresenter::pipelineStatus($paymentRequest->refresh()));
    }
}
