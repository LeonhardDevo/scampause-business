<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal\Domain\Concerns;

use App\Domain\Intake\Exceptions\InvalidTransition;
use App\Domain\Workflow\WorkflowException;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait InternalResponses
{
    protected function workspace(Request $request): ?Workspace
    {
        $id = $request->input('workspace_id', $request->query('workspace_id'));

        return is_numeric($id) ? Workspace::query()->find((int) $id) : null;
    }

    /**
     * @return array{0: User, 1: WorkspaceRole}|null
     */
    protected function member(Request $request, Workspace $workspace): ?array
    {
        $id = $request->input('user_id', $request->query('user_id'));
        $user = is_numeric($id) ? User::query()->whereNull('deleted_at')->find((int) $id) : null;
        $role = $user?->roleIn($workspace);

        return $user !== null && $role !== null ? [$user, $role] : null;
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    protected function as(Workspace $workspace, ?WorkspaceRole $role, \Closure $callback): mixed
    {
        return app(CurrentWorkspace::class)->runAs($workspace, $callback, $role);
    }

    /**
     * @param  \Closure(): JsonResponse  $action
     */
    protected function guard(\Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (InvalidTransition $exception) {
            return self::error($exception->errorCode, 409);
        } catch (WorkflowException $exception) {
            return response()->json(['error' => $exception->errorCode, 'message' => $exception->localized()], 409);
        } catch (ModelNotFoundException) {
            return self::error('not_found', 404);
        }
    }

    protected static function error(string $code, int $status): JsonResponse
    {
        return response()->json(['error' => $code], $status);
    }
}
