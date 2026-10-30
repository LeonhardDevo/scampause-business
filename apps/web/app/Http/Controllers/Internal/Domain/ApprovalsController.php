<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal\Domain;

use App\Domain\Approvals\ApprovalService;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Approvals\Models\ApprovalRoute;
use App\Domain\Notifications\Models\SpNotification;
use App\Domain\Workflow\DecisionService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Internal\Domain\Concerns\InternalResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApprovalsController extends Controller
{
    use InternalResponses;

    public function storeTelegramMessage(Request $request, int $approval): JsonResponse
    {
        $validated = $request->validate([
            'workspace_id' => ['required', 'integer'],
            'chat_id' => ['required', 'integer'],
            'message_id' => ['required', 'integer'],
            'sp_notification_id' => ['nullable', 'integer'],
        ]);
        $model = $this->approval($request, $approval);

        if ($model === null) {
            return self::error('not_found', 404);
        }

        $model->forceFill(['telegram_message' => ['chat_id' => (int) $validated['chat_id'], 'message_id' => (int) $validated['message_id']]])->save();

        if (isset($validated['sp_notification_id'])) {
            SpNotification::query()->forWorkspace((int) $validated['workspace_id'])->whereKey((int) $validated['sp_notification_id'])
                ->update(['provider_message_id' => (string) $validated['message_id'], 'updated_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    public function showTelegramMessage(Request $request, int $approval): JsonResponse
    {
        $model = $this->approval($request, $approval);

        if ($model === null) {
            return self::error('not_found', 404);
        }

        $message = $model->getAttribute('telegram_message');

        return response()->json(['message' => is_array($message) && isset($message['chat_id'], $message['message_id'])
            ? ['chat_id' => (int) $message['chat_id'], 'message_id' => (int) $message['message_id']]
            : null]);
    }

    public function reminders(Request $request, int $approval, ApprovalService $approvals): JsonResponse
    {
        $validated = $request->validate(['workspace_id' => ['required', 'integer'], 'sequence' => ['required', 'integer', 'min:1', 'max:20'], 'total' => ['nullable', 'integer', 'min:1', 'max:20']]);
        $model = $this->approval($request, $approval);

        if ($model === null) {
            return self::error('not_found', 404);
        }

        return $this->guard(fn (): JsonResponse => response()->json($approvals->reminder($model, (int) $validated['sequence'], (int) ($validated['total'] ?? $validated['sequence']))));
    }

    public function escalate(Request $request, int $route, ApprovalService $approvals): JsonResponse
    {
        $model = $this->route($request, $route);

        if ($model === null) {
            return self::error('not_found', 404);
        }

        return $this->guard(fn (): JsonResponse => response()->json(['escalated' => $approvals->escalate($model)]));
    }

    public function expire(Request $request, int $route, DecisionService $decisions): JsonResponse
    {
        $model = $this->route($request, $route);

        if ($model === null) {
            return self::error('not_found', 404);
        }

        return $this->guard(fn (): JsonResponse => response()->json(['expired' => $decisions->expireRoute($model)]));
    }

    public function sweep(DecisionService $decisions): JsonResponse
    {
        return response()->json(['expired_route_ids' => $decisions->sweepExpired()]);
    }

    private function approval(Request $request, int $approval): ?Approval
    {
        $workspace = $this->workspace($request);

        return $workspace === null ? null : Approval::query()->forWorkspace($workspace)->find($approval);
    }

    private function route(Request $request, int $route): ?ApprovalRoute
    {
        $workspace = $this->workspace($request);

        return $workspace === null ? null : ApprovalRoute::query()->forWorkspace($workspace)->find($route);
    }
}
