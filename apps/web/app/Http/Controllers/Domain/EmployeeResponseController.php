<?php

declare(strict_types=1);

namespace App\Http\Controllers\Domain;

use App\Domain\Evidence\Enums\EvidenceChannel;
use App\Domain\Intake\Enums\EmployeeResponse;
use App\Domain\Verification\EmployeeAlertService;
use App\Domain\Workflow\WorkflowException;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class EmployeeResponseController extends Controller
{
    public function show(Request $request, string $token, EmployeeAlertService $alerts): Response
    {
        $alert = $alerts->findByToken($token);
        abort_if($alert === null, 404);

        return Inertia::render('employee-response/Show', [
            'summary' => $alerts->summary($alert, app()->getLocale()),
            'token' => $token,
            'options' => EmployeeResponse::options(),
        ]);
    }

    public function store(Request $request, string $token, EmployeeAlertService $alerts): Response
    {
        $validated = $request->validate(['response' => ['required', Rule::enum(EmployeeResponse::class)]]);
        $alert = $alerts->findByToken($token);
        abort_if($alert === null, 404);
        $response = EmployeeResponse::from((string) $validated['response']);
        $error = null;

        try {
            $alerts->answer($alert, $response, EvidenceChannel::Web, $request->ip(), (string) $request->userAgent());
        } catch (WorkflowException $exception) {
            $error = $exception->localized();
        }

        return Inertia::render('employee-response/Done', [
            'response' => $response->value,
            'message' => $error ?? (string) __('domain.employee_response.'.($response === EmployeeResponse::NotMe ? 'thanks_not_me' : 'thanks_me')),
            'error' => $error !== null,
        ]);
    }
}
