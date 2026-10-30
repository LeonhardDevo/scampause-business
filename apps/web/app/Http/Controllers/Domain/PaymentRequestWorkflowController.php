<?php

declare(strict_types=1);

namespace App\Http\Controllers\Domain;

use App\Audit\Auditor;
use App\Domain\Intake\Enums\CancelReason;
use App\Domain\Intake\Models\BankComparison;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Reports\ExportService;
use App\Domain\Vendors\BankAccountService;
use App\Domain\Vendors\Enums\BankScheme;
use App\Domain\Vendors\Models\VendorBankAccount;
use App\Domain\Verification\VerificationService;
use App\Domain\Workflow\DecisionService;
use App\Domain\Workflow\GovernanceRecorder;
use App\Domain\Workflow\SubmissionService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Domain\Concerns\RunsWorkflow;
use App\Http\Requests\Domain\DecisionRequest;
use App\Http\Requests\Domain\RecordVerificationRequest;
use App\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PaymentRequestWorkflowController extends Controller
{
    use RunsWorkflow;

    public function submit(Request $request, PaymentRequest $paymentRequest, SubmissionService $submissions): RedirectResponse
    {
        $this->authorize('view', $paymentRequest);
        $this->authorize('review', $paymentRequest);
        $validated = $request->validate([
            'unknown_payee_acknowledged' => ['nullable', 'boolean'],
            'confirm_ai_fields' => ['nullable', 'boolean'],
        ]);
        $user = $this->user($request);

        return $this->run(fn () => $submissions->submit(
            $paymentRequest,
            $user,
            (bool) ($validated['unknown_payee_acknowledged'] ?? false),
            (bool) ($validated['confirm_ai_fields'] ?? false),
        ), 'domain.flash.submitted');
    }

    public function verify(RecordVerificationRequest $request, PaymentRequest $paymentRequest, CurrentWorkspace $current, VerificationService $verifications): RedirectResponse
    {
        $this->authorize('view', $paymentRequest);
        $this->allow($current, 'payment_requests.verify');
        $user = $this->user($request);

        return $this->run(fn () => $verifications->record($paymentRequest, $user, $request->verification()), 'domain.flash.verification_recorded');
    }

    public function reject(DecisionRequest $request, PaymentRequest $paymentRequest, DecisionService $decisions): RedirectResponse
    {
        $this->authorize('view', $paymentRequest);
        $this->authorize('review', $paymentRequest);
        $user = $this->user($request);

        return $this->run(fn () => $decisions->rejectFromReview($paymentRequest, $user, $request->reason(), $request->comment()), 'domain.flash.rejected');
    }

    public function cancel(Request $request, PaymentRequest $paymentRequest, CurrentWorkspace $current, DecisionService $decisions): RedirectResponse
    {
        $this->authorize('view', $paymentRequest);
        $validated = $request->validate([
            'reason' => ['required', Rule::enum(CancelReason::class)],
            'comment' => ['nullable', 'required_if:reason,other', 'string', 'max:2000'],
        ]);
        $user = $this->user($request);
        $mayCancelAny = $current->allows('payment_requests.cancel');
        abort_unless($mayCancelAny || (int) $paymentRequest->getAttribute('requested_by') === $user->id, 403);

        return $this->run(fn () => $decisions->cancel(
            $paymentRequest,
            $user,
            CancelReason::from((string) $validated['reason']),
            is_string($validated['comment'] ?? null) ? $validated['comment'] : null,
            $mayCancelAny,
        ), 'domain.flash.cancelled');
    }

    public function reopen(Request $request, PaymentRequest $paymentRequest, SubmissionService $submissions): RedirectResponse
    {
        $this->authorize('view', $paymentRequest);
        $this->authorize('review', $paymentRequest);
        $user = $this->user($request);

        return $this->run(fn () => $submissions->reopen($paymentRequest, $user), 'domain.flash.reopened');
    }

    public function revoke(DecisionRequest $request, PaymentRequest $paymentRequest, CurrentWorkspace $current, DecisionService $decisions): RedirectResponse
    {
        $this->authorize('view', $paymentRequest);
        $this->allow($current, 'payment_requests.revoke');
        $user = $this->user($request);

        return $this->run(fn () => $decisions->revoke($paymentRequest, $user, $request->reason(), (string) $request->comment()), 'domain.flash.revoked');
    }

    public function markPaid(Request $request, PaymentRequest $paymentRequest, CurrentWorkspace $current, DecisionService $decisions): RedirectResponse
    {
        $this->authorize('view', $paymentRequest);
        $this->allow($current, 'payment_requests.mark_paid');
        $validated = $request->validate([
            'paid_at' => ['required', 'date'],
            'payment_reference' => ['required', 'string', 'max:120'],
        ]);
        $user = $this->user($request);

        return $this->run(fn () => $decisions->markPaid(
            $paymentRequest,
            $user,
            CarbonImmutable::parse((string) $validated['paid_at'])->utc(),
            (string) $validated['payment_reference'],
        ), 'domain.flash.paid');
    }

    public function reveal(Request $request, PaymentRequest $paymentRequest, CurrentWorkspace $current, Auditor $auditor, GovernanceRecorder $governance): JsonResponse
    {
        $this->authorize('view', $paymentRequest);
        $this->allow($current, 'bank_details.reveal');
        $workspace = $this->workspace($current);
        $user = $this->user($request);
        $scheme = $paymentRequest->getAttribute('payee_scheme');
        $account = $paymentRequest->getAttribute('payee_account_number');
        $incoming = $scheme instanceof BankScheme && is_string($account)
            ? BankAccountService::revealed(self::str($paymentRequest->getAttribute('payee_account_holder')), $account, self::str($paymentRequest->getAttribute('payee_routing_code')), self::str($paymentRequest->getAttribute('payee_bic')), $scheme)
            : null;
        $comparison = BankComparison::query()->forWorkspace($workspace)->where('payment_request_id', $paymentRequest->getKey())->orderByDesc('revision')->first();
        $previousAccount = is_int($id = $comparison?->getAttribute('previous_account_id')) ? VendorBankAccount::query()->forWorkspace($workspace)->find($id) : null;
        $previous = null;

        if ($previousAccount !== null && $previousAccount->getAttribute('scheme') instanceof BankScheme) {
            $previous = BankAccountService::revealed(
                self::str($previousAccount->getAttribute('account_holder')),
                (string) $previousAccount->getAttribute('account_number'),
                self::str($previousAccount->getAttribute('routing_code')),
                self::str($previousAccount->getAttribute('bic')),
                $previousAccount->getAttribute('scheme'),
            );
        }

        $auditor->record('payment_request.bank_details_revealed', $paymentRequest, metadata: ['last4' => $paymentRequest->getAttribute('payee_last4')], actor: $user, workspace: $workspace, subjectLabel: (string) $paymentRequest->getAttribute('reference'));
        $governance->record($workspace, 'bank_account.revealed', 'payment_request', (int) $paymentRequest->getKey(), ['last4' => self::str($paymentRequest->getAttribute('payee_last4'))], $user, '/payment-requests/'.$paymentRequest->getKey());

        return response()->json([
            'incoming' => $incoming,
            'previous' => $previous,
            'char_diff' => $incoming !== null && $previous !== null ? BankAccountService::charDiff($previous['account'], $incoming['account']) : null,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function evidencePacket(Request $request, PaymentRequest $paymentRequest, CurrentWorkspace $current, ExportService $exports): RedirectResponse
    {
        $this->authorize('view', $paymentRequest);
        $this->allow($current, 'reports.export');
        $user = $this->user($request);

        return $this->run(fn () => $exports->requestPacket($paymentRequest, $user), 'domain.flash.packet_requested');
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
