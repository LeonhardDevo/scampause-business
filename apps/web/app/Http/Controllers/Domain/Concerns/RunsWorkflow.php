<?php

declare(strict_types=1);

namespace App\Http\Controllers\Domain\Concerns;

use App\Domain\Intake\Exceptions\InvalidTransition;
use App\Domain\Workflow\WorkflowException;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Flash;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait RunsWorkflow
{
    /**
     * @param  \Closure(): mixed  $action
     * @param  array<string, mixed>  $replace
     */
    protected function run(\Closure $action, string $success, array $replace = []): RedirectResponse
    {
        try {
            $action();
        } catch (InvalidTransition $exception) {
            if ($exception->errorCode !== 'invalid_transition' && $exception->errorCode !== 'stale_revision') {
                throw ValidationException::withMessages(['workflow' => (string) __('domain.errors.'.$exception->errorCode)]);
            }

            Flash::error('domain.flash.invalid_transition');

            return back(303);
        } catch (WorkflowException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->localized()]);
        }

        Flash::success($success, $replace);

        return back(303);
    }

    protected function workspace(CurrentWorkspace $current): Workspace
    {
        $workspace = $current->get();
        abort_if($workspace === null, 404);

        return $workspace;
    }

    protected function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    protected function allow(CurrentWorkspace $current, string $ability): void
    {
        abort_unless($current->allows($ability), 403);
    }
}
