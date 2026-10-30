<?php

declare(strict_types=1);

namespace App\Http\Controllers\Domain;

use App\Domain\Privacy\DataSubjectService;
use App\Domain\Vendors\Models\Vendor;
use App\Domain\Workflow\WorkflowException;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Domain\Concerns\RunsWorkflow;
use App\Support\Flash;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class DataSubjectController extends Controller
{
    use RunsWorkflow;

    public function __invoke(Request $request, Vendor $vendor, CurrentWorkspace $current, DataSubjectService $service): RedirectResponse|JsonResponse
    {
        abort_unless($current->role() === WorkspaceRole::Owner, 403);
        $validated = $request->validate([
            'action' => ['required', Rule::in(['find', 'export', 'redact'])],
            'subject' => ['required', 'array'],
            'subject.type' => ['required', Rule::in(['contact', 'employee', 'sender'])],
            'subject.id' => ['nullable', 'integer'],
            'subject.email' => ['nullable', 'email:rfc', 'max:254'],
            'subject.phone' => ['nullable', 'string', 'max:40'],
        ]);
        $workspace = $this->workspace($current);
        $subject = [
            'type' => (string) $validated['subject']['type'],
            'id' => isset($validated['subject']['id']) ? (int) $validated['subject']['id'] : null,
            'email' => is_string($validated['subject']['email'] ?? null) ? $validated['subject']['email'] : null,
            'phone' => is_string($validated['subject']['phone'] ?? null) ? $validated['subject']['phone'] : null,
        ];

        try {
            if ($validated['action'] === 'redact') {
                $counts = $service->redact($workspace, $vendor, $this->user($request), $subject);
                Flash::success('domain.flash.subject_redacted', $counts);

                return back(303);
            }

            $found = $service->find($workspace, $vendor, $subject);

            if ($validated['action'] === 'export') {
                return response()->json($service->export($found))
                    ->header('Content-Disposition', 'attachment; filename="data-subject-export.json"')
                    ->header('Cache-Control', 'no-store, private');
            }

            Flash::success('domain.flash.subject_found', [
                'contacts' => $found['contacts']->count(),
                'verifications' => $found['verifications']->count(),
                'messages' => $found['messages']->count(),
            ]);

            return back(303);
        } catch (WorkflowException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->localized()]);
        }
    }
}
