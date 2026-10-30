<?php

declare(strict_types=1);

namespace App\Http\Controllers\Domain;

use App\Domain\Approvals\ApprovalGate;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Approvals\Models\ApprovalRoute;
use App\Domain\Approvals\RouteBuilder;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\Presenters\PaymentRequestRows;
use App\Domain\Intake\Presenters\Refs;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Domain\Concerns\RunsWorkflow;
use App\Http\Requests\Domain\DecisionRequest;
use App\Models\User;
use App\Support\Paginated;
use App\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Psr\Clock\ClockInterface;

final class ApprovalController extends Controller
{
    use RunsWorkflow;

    public function index(Request $request, CurrentWorkspace $current, ApprovalGate $gate, ClockInterface $clock): Response
    {
        $this->allow($current, 'payment_requests.approve');
        $workspace = $this->workspace($current);
        $user = $this->user($request);
        $tab = $request->query('tab') === 'decided' ? 'decided' : 'pending';
        $query = Approval::query()->forWorkspace($workspace)->where('approver_id', $user->id);

        if ($tab === 'pending') {
            $query->where('status', ApprovalStatus::Pending->value)
                ->whereHas('paymentRequest', fn ($inner) => $inner->where('status', PaymentRequestStatus::AwaitingApproval->value))
                ->orderBy('created_at');
        } else {
            $query->whereIn('status', [ApprovalStatus::Approved->value, ApprovalStatus::Rejected->value, ApprovalStatus::Expired->value, ApprovalStatus::Revoked->value])
                ->orderByDesc('decided_at');
        }

        $page = $query->orderBy('id')->paginate(20)->withQueryString();
        $approvals = $page->getCollection();
        $requests = PaymentRequest::query()->forWorkspace($workspace)->whereIn('id', $approvals->pluck('payment_request_id')->all())->get()->keyBy('id');
        $rows = [];

        foreach (PaymentRequestRows::many($requests->values(), $user) as $row) {
            $rows[(int) $row['id']] = $row;
        }

        $routes = ApprovalRoute::query()->forWorkspace($workspace)->whereIn('id', $approvals->pluck('approval_route_id')->all())->get()->keyBy('id');
        $now = CarbonImmutable::instance($clock->now());
        $role = $current->role();
        $pending = Approval::query()->forWorkspace($workspace)->where('approver_id', $user->id)->where('status', ApprovalStatus::Pending->value)
            ->whereHas('paymentRequest', fn ($inner) => $inner->where('status', PaymentRequestStatus::AwaitingApproval->value));

        return Inertia::render('approvals/Index', [
            'tab' => $tab,
            'items' => Paginated::from($page, function (Approval $approval) use ($rows, $requests, $routes, $workspace, $gate, $role, $user): array {
                $request = $requests->get($approval->getAttribute('payment_request_id'));
                $route = $routes->get($approval->getAttribute('approval_route_id'));
                $status = $approval->getAttribute('status');
                $live = $status === ApprovalStatus::Pending && $request?->getAttribute('status') === PaymentRequestStatus::AwaitingApproval;

                return [
                    ...($rows[(int) $approval->getAttribute('payment_request_id')] ?? []),
                    'approval' => [
                        'id' => (int) $approval->getKey(),
                        'slot' => (int) $approval->getAttribute('slot'),
                        'approver' => [...(Refs::user($user) ?? []), 'role' => $role !== null ? $role->value : 'member'],
                        'status' => (string) Refs::enum($status),
                        'channel' => Refs::enum($approval->getAttribute('channel')),
                        'decided_at' => Refs::iso($approval->getAttribute('decided_at')),
                        'reject_reason' => Refs::enum($approval->getAttribute('reject_reason')),
                        'comment' => is_string($c = $approval->getAttribute('comment')) ? $c : null,
                        'reminders_sent' => (int) $approval->getAttribute('reminders_sent'),
                        'can_decide' => $live,
                        'can_reassign' => false,
                    ],
                    'route_expires_at' => (string) Refs::iso($route?->getAttribute('expires_at')),
                    'telegram_allowed' => $request !== null && $gate->telegramAllowed($request, $workspace),
                    'inline_approve' => $request !== null && $live && ApprovalGate::inlineApproveAllowed($request),
                ];
            }),
            'counts' => [
                'pending' => (clone $pending)->count(),
                'due_soon' => (clone $pending)->whereHas('route', fn ($inner) => $inner->where('expires_at', '<', $now->addHours(12)))->count(),
            ],
        ]);
    }

    public function approve(Request $request, PaymentRequest $paymentRequest, int $approval, ApprovalService $service): RedirectResponse
    {
        $model = $this->find($paymentRequest, $approval);
        $validated = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);
        $user = $this->user($request);
        abort_unless((int) $model->getAttribute('approver_id') === $user->id, 403);

        return $this->run(fn () => $service->approve($model, $user, comment: is_string($validated['comment'] ?? null) ? $validated['comment'] : null), 'domain.flash.approved');
    }

    public function reject(DecisionRequest $request, PaymentRequest $paymentRequest, int $approval, ApprovalService $service): RedirectResponse
    {
        $model = $this->find($paymentRequest, $approval);
        $user = $this->user($request);
        abort_unless((int) $model->getAttribute('approver_id') === $user->id, 403);

        return $this->run(fn () => $service->reject($model, $user, $request->reason(), $request->comment()), 'domain.flash.rejected');
    }

    public function reassign(Request $request, PaymentRequest $paymentRequest, int $approval, CurrentWorkspace $current, RouteBuilder $routes): RedirectResponse
    {
        $this->allow($current, 'payment_requests.reassign');
        $model = $this->find($paymentRequest, $approval);
        $validated = $request->validate(['user_id' => ['required', 'integer']]);
        $workspace = $this->workspace($current);
        $target = $workspace->members()->where('users.id', (int) $validated['user_id'])->first();
        abort_unless($target instanceof User, 422);
        $actor = $this->user($request);

        return $this->run(fn () => DB::transaction(fn () => $routes->reassign($model, $target, $actor)), 'domain.flash.reassigned');
    }

    private function find(PaymentRequest $paymentRequest, int $approval): Approval
    {
        $this->authorize('view', $paymentRequest);
        $model = Approval::query()->where('payment_request_id', $paymentRequest->getKey())->whereKey($approval)->first();
        abort_if($model === null, 404);

        return $model;
    }
}
