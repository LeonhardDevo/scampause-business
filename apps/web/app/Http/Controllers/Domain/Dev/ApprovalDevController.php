<?php

declare(strict_types=1);

namespace App\Http\Controllers\Domain\Dev;

use App\Domain\Approvals\ApprovalService;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Approvals\Models\ApprovalRoute;
use App\Domain\Notifications\DomainNotifier;
use App\Domain\Workflow\DecisionService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Outbox\Outbox;
use App\Outbox\Topics;
use Illuminate\Http\JsonResponse;

final class ApprovalDevController extends Controller
{
    public function expireNow(int $route, DecisionService $decisions): JsonResponse
    {
        $model = ApprovalRoute::query()->whereKey($route)->first();
        abort_if($model === null, 404);
        $model->forceFill(['expires_at' => now()->subMinute()])->save();

        return response()->json(['expired' => $decisions->expireRoute($model)]);
    }

    public function remindNow(int $approval, ApprovalService $approvals, DomainNotifier $notifier, Outbox $outbox): JsonResponse
    {
        $model = Approval::query()->whereKey($approval)->first();
        abort_if($model === null, 404);
        $sequence = (int) $model->getAttribute('reminders_sent') + 1;
        $result = $approvals->reminder($model, $sequence, max(2, $sequence));
        $user = User::query()->find((int) $model->getAttribute('approver_id'));
        $workspaceId = (int) $model->getAttribute('workspace_id');
        $ids = is_array($result['sp_notification_ids'] ?? null) ? $result['sp_notification_ids'] : [];

        if ($user !== null && is_array($result['telegram'] ?? null)) {
            $notifier->publishTelegram($user, $workspaceId, [
                'template' => $result['telegram']['template'],
                'params' => $result['telegram']['params'],
                'actions' => $result['telegram']['actions'],
                'buttons' => [],
                'reference' => ['type' => 'sp_notification', 'id' => (string) ($ids['telegram'] ?? '')],
            ]);
        }

        if ($user !== null && is_array($result['email'] ?? null)) {
            $outbox->publish(Topics::EMAIL_SEND, [
                'to' => $user->email,
                'to_name' => $user->name,
                'subject' => $result['email']['subject'],
                'html' => $result['email']['html'],
                'text' => $result['email']['text'],
                'locale' => DomainNotifier::locale($user),
                'reference' => ['type' => 'sp_notification', 'id' => (string) ($ids['email'] ?? '')],
                'workspace_id' => $workspaceId,
            ], $workspaceId);
        }

        return response()->json($result);
    }
}
