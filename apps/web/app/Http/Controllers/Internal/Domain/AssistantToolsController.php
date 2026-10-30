<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal\Domain;

use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\Presenters\Refs;
use App\Domain\Money\Currencies;
use App\Domain\Money\FxConverter;
use App\Domain\Money\Money;
use App\Domain\Notifications\DomainNotifier;
use App\Domain\Policy\RequestPolicy;
use App\Domain\Risk\Enums\RiskLevel;
use App\Domain\Support\Masking;
use App\Domain\Support\NameSimilarity;
use App\Domain\Vendors\Enums\ContactStatus;
use App\Domain\Vendors\Enums\VendorKind;
use App\Domain\Vendors\Models\BlockedBankAccount;
use App\Domain\Vendors\Models\Vendor;
use App\Domain\Vendors\Models\VendorBankAccount;
use App\Domain\Vendors\Models\VendorContact;
use App\Domain\Verification\Models\Verification;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Domain\PolicyController;
use App\Http\Controllers\Internal\Domain\Concerns\InternalResponses;
use App\Locale\WithLocale;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Psr\Clock\ClockInterface;

final class AssistantToolsController extends Controller
{
    use InternalResponses;

    public function evaluatePolicy(Request $request, RequestPolicy $policy, FxConverter $fx, ClockInterface $clock): JsonResponse
    {
        $validated = $request->validate([
            'workspace_id' => ['required', 'integer'],
            'user_id' => ['required', 'integer'],
            'amount' => ['required', 'string', 'max:20'],
            'currency' => ['nullable', 'string', 'size:3', Rule::in(Currencies::codes())],
            'kind' => ['nullable', Rule::enum(PaymentRequestKind::class)],
            'risk_level' => ['nullable', Rule::enum(RiskLevel::class)],
            'new_vendor' => ['nullable', 'boolean'],
            'bank_change' => ['nullable', 'boolean'],
        ]);

        return $this->authorized($request, 'policies.view', function (Workspace $workspace, User $user) use ($validated, $policy, $fx, $clock): JsonResponse {
            try {
                return response()->json(WithLocale::run(DomainNotifier::locale($user), static fn (): array => PolicyController::simulated($workspace, $validated, $policy, $fx, CarbonImmutable::instance($clock->now()))));
            } catch (ValidationException) {
                return self::error('invalid_amount', 422);
            }
        });
    }

    public function searchVendors(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'max:200'], 'limit' => ['nullable', 'integer', 'min:1', 'max:5']]);

        return $this->authorized($request, 'vendors.view', function (Workspace $workspace) use ($validated): JsonResponse {
            $query = (string) $validated['q'];
            $limit = (int) ($validated['limit'] ?? 3);
            $candidates = [];

            foreach (Vendor::query()->forWorkspace($workspace)->with('domains')->limit(2000)->get() as $vendor) {
                $names = array_values(array_filter([
                    (string) $vendor->getAttribute('name'),
                    is_string($l = $vendor->getAttribute('legal_name')) ? $l : null,
                    ...array_filter((array) $vendor->getAttribute('aliases'), 'is_string'),
                    ...$vendor->domains->pluck('domain')->map(static fn (mixed $d): string => (string) $d)->all(),
                ]));
                $score = 0;

                foreach ($names as $name) {
                    $score = max($score, NameSimilarity::score($query, $name), str_contains(mb_strtolower($name), mb_strtolower(trim($query))) ? 90 : 0);
                }

                if ($score >= 30) {
                    $candidates[] = [$score, $vendor];
                }
            }

            usort($candidates, static fn (array $a, array $b): int => $b[0] <=> $a[0] ?: $a[1]->getKey() <=> $b[1]->getKey());

            return response()->json(['vendors' => array_map(static fn (array $pair): array => [
                'id' => (int) $pair[1]->getKey(),
                'name' => (string) $pair[1]->getAttribute('name'),
                'legal_name' => $pair[1]->getAttribute('legal_name'),
                'kind' => (string) Refs::enum($pair[1]->getAttribute('kind')),
                'status' => (string) Refs::enum($pair[1]->getAttribute('status')),
                'domains' => $pair[1]->domains->pluck('domain')->values()->all(),
                'similarity' => (int) $pair[0],
            ], array_slice($candidates, 0, $limit))]);
        });
    }

    public function contacts(Request $request, int $vendor): JsonResponse
    {
        return $this->authorized($request, 'vendors.view', function (Workspace $workspace, User $user, WorkspaceRole $role) use ($vendor): JsonResponse {
            $model = Vendor::query()->forWorkspace($workspace)->find($vendor);

            if ($model === null) {
                return self::error('not_found', 404);
            }

            $fullPhone = $model->getAttribute('kind') !== VendorKind::Employee || $role->allows('vendors.manage');
            $all = VendorContact::query()->forWorkspace($workspace)->where('vendor_id', $vendor)->orderBy('id')->get();
            $callback = $all->filter(static fn (VendorContact $c): bool => (bool) $c->getAttribute('is_callback') && $c->getAttribute('status') === ContactStatus::Verified);

            return response()->json([
                'vendor' => ['id' => (int) $model->getKey(), 'name' => (string) $model->getAttribute('name'), 'kind' => (string) Refs::enum($model->getAttribute('kind'))],
                'callback_contacts' => array_values($callback->map(static fn (VendorContact $contact): array => [
                    'name' => (string) $contact->getAttribute('name'),
                    'role_title' => $contact->getAttribute('role_title'),
                    'phone_e164' => $fullPhone ? $contact->getAttribute('phone_e164') : null,
                    'phone_masked' => Masking::phone(is_string($l = $contact->getAttribute('phone_last4')) ? $l : null),
                    'email' => $contact->getAttribute('email'),
                    'source' => (string) Refs::enum($contact->getAttribute('source')),
                    'source_ref' => $contact->getAttribute('source_ref'),
                    'status' => (string) Refs::enum($contact->getAttribute('status')),
                    'verified_at' => Refs::iso($contact->getAttribute('verified_at')),
                ])->all()),
                'other_contacts_count' => $all->count() - $callback->count(),
            ]);
        });
    }

    public function bankHistory(Request $request, int $vendor): JsonResponse
    {
        return $this->authorized($request, 'vendors.view_bank', function (Workspace $workspace) use ($vendor): JsonResponse {
            $model = Vendor::query()->forWorkspace($workspace)->find($vendor);

            if ($model === null) {
                return self::error('not_found', 404);
            }

            $accounts = VendorBankAccount::query()->forWorkspace($workspace)->where('vendor_id', $vendor)->with(['verifier:id,name,deleted_at', 'verification'])->orderBy('created_at')->get();
            $blocked = BlockedBankAccount::query()->forWorkspace($workspace)->withTrashed()
                ->whereIn('source_payment_request_id', PaymentRequest::query()->forWorkspace($workspace)->where('vendor_id', $vendor)->select('id'))
                ->with('sourcePaymentRequest:id,reference')->orderBy('created_at')->get();

            return response()->json([
                'vendor' => ['id' => (int) $model->getKey(), 'name' => (string) $model->getAttribute('name')],
                'accounts' => array_values($accounts->map(static fn (VendorBankAccount $account): array => [
                    'id' => (int) $account->getKey(),
                    'scheme' => (string) Refs::enum($account->getAttribute('scheme')),
                    'bank_name' => $account->getAttribute('bank_name'),
                    'bank_country' => $account->getAttribute('bank_country'),
                    'last4' => (string) $account->getAttribute('last4'),
                    'status' => (string) Refs::enum($account->getAttribute('status')),
                    'valid_from' => Refs::iso($account->getAttribute('valid_from')),
                    'valid_to' => Refs::iso($account->getAttribute('valid_to')),
                    'verified_at' => Refs::iso($account->getAttribute('verified_at')),
                    'verified_by_name' => Refs::user($account->verifier)['name'] ?? null,
                    'verification_method' => Refs::enum($account->verification?->getAttribute('method')),
                    'retired_reason' => Refs::enum($account->getAttribute('retired_reason')),
                ])->all()),
                'blocked_attempts' => array_values($blocked->map(static fn (BlockedBankAccount $block): array => [
                    'last4' => (string) $block->getAttribute('last4'),
                    'bank_country' => $block->getAttribute('bank_country'),
                    'created_at' => Refs::iso($block->getAttribute('created_at')),
                    'reason' => (string) Refs::enum($block->getAttribute('reason')),
                    'reference' => $block->sourcePaymentRequest?->getAttribute('reference'),
                ])->all()),
            ]);
        });
    }

    public function byReference(Request $request, string $reference): JsonResponse
    {
        return $this->authorized($request, null, function (Workspace $workspace, User $user, WorkspaceRole $role) use ($reference): JsonResponse {
            $model = PaymentRequest::query()->forWorkspace($workspace)->where('reference', strtoupper($reference))->first();

            if ($model === null) {
                return self::error('not_found', 404);
            }

            if (! $role->allows('payment_requests.view_all') && (int) $model->getAttribute('requested_by') !== $user->id) {
                return self::error('forbidden', 403);
            }

            $locale = DomainNotifier::locale($user);

            return response()->json(WithLocale::run($locale, function () use ($model, $workspace, $locale): array {
                $vendor = is_int($v = $model->getAttribute('vendor_id')) ? Vendor::query()->forWorkspace($workspace)->withTrashed()->find($v) : null;
                $amount = $model->getAttribute('amount_minor');
                $currency = $model->getAttribute('currency');
                $level = $model->getAttribute('risk_level');
                $last = Verification::query()->forWorkspace($workspace)->where('payment_request_id', $model->getKey())->orderByDesc('occurred_at')->first();
                $codes = DomainNotifier::topSignals($model, $locale);

                return [
                    'reference' => (string) $model->getAttribute('reference'),
                    'title' => ['untrusted_text' => (string) $model->getAttribute('title')],
                    'status' => (string) Refs::enum($model->getAttribute('status')),
                    'status_label' => $model->getAttribute('status')?->label($locale),
                    'kind' => (string) Refs::enum($model->getAttribute('kind')),
                    'vendor_name' => $vendor?->getAttribute('name'),
                    'amount_formatted' => is_int($amount) && is_string($currency) ? Money::of($amount, $currency)->format($locale) : null,
                    'risk_level' => $level instanceof RiskLevel ? $level->value : null,
                    'risk_score' => $model->getAttribute('risk_score'),
                    'top_signals' => array_map(static fn (string $title): array => ['title' => $title], $codes),
                    'verification' => [
                        'required' => (bool) $model->getAttribute('verification_required'),
                        'satisfied' => $model->getAttribute('verification_satisfied_at') !== null,
                        'last_outcome' => Refs::enum($last?->getAttribute('outcome')),
                    ],
                    'approvals' => array_values(Approval::query()->forWorkspace($workspace)->where('payment_request_id', $model->getKey())
                        ->where('approval_route_id', $model->getAttribute('current_route_id'))->with('approver:id,name,deleted_at')->orderBy('slot')->get()
                        ->map(static fn (Approval $approval): array => ['approver_name' => Refs::user($approval->approver)['name'] ?? '', 'status' => (string) Refs::enum($approval->getAttribute('status'))])->all()),
                    'expires_at' => Refs::iso($model->getAttribute('expires_at')),
                    'url' => url('/payment-requests/'.$model->getKey()),
                ];
            }));
        });
    }

    public function pendingApprovals(Request $request): JsonResponse
    {
        return $this->authorized($request, 'payment_requests.approve', function (Workspace $workspace, User $user): JsonResponse {
            $locale = DomainNotifier::locale($user);
            $approvals = Approval::query()->forWorkspace($workspace)->where('approver_id', $user->id)->where('status', ApprovalStatus::Pending->value)
                ->whereHas('paymentRequest', fn ($query) => $query->where('status', PaymentRequestStatus::AwaitingApproval->value))
                ->orderBy('created_at')->limit(20)->get();

            return response()->json(['items' => array_values($approvals->map(static function (Approval $approval) use ($workspace, $locale): array {
                $request = PaymentRequest::query()->forWorkspace($workspace)->findOrFail((int) $approval->getAttribute('payment_request_id'));
                $amount = $request->getAttribute('amount_minor');
                $currency = $request->getAttribute('currency');

                return [
                    'approval_id' => (int) $approval->getKey(),
                    'reference' => (string) $request->getAttribute('reference'),
                    'title' => ['untrusted_text' => (string) $request->getAttribute('title')],
                    'amount_formatted' => is_int($amount) && is_string($currency) ? Money::of($amount, $currency)->format($locale) : null,
                    'risk_level' => Refs::enum($request->getAttribute('risk_level')),
                    'expires_at' => Refs::iso($request->getAttribute('expires_at')),
                    'url' => url('/payment-requests/'.$request->getKey().'?tab=approvals'),
                ];
            })->all())]);
        });
    }

    /**
     * @param  \Closure(Workspace, User, WorkspaceRole): JsonResponse  $callback
     */
    private function authorized(Request $request, ?string $ability, \Closure $callback): JsonResponse
    {
        $workspace = $this->workspace($request);

        if ($workspace === null) {
            return self::error('not_found', 404);
        }

        $member = $this->member($request, $workspace);

        if ($member === null || ($ability !== null && ! $member[1]->allows($ability))) {
            return self::error('forbidden', 403);
        }

        return $callback($workspace, $member[0], $member[1]);
    }
}
