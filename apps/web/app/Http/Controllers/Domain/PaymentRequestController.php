<?php

declare(strict_types=1);

namespace App\Http\Controllers\Domain;

use App\Billing\PlanLimits;
use App\Domain\Billing\PaymentRequestsMonthlyUsage;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Exceptions\InvalidTransition;
use App\Domain\Intake\Files\UploadInspector;
use App\Domain\Intake\IntakeService;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\Presenters\CaseFilePresenter;
use App\Domain\Intake\Presenters\PaymentRequestRows;
use App\Domain\Intake\Presenters\Refs;
use App\Domain\Intake\ReviewService;
use App\Domain\Money\Currencies;
use App\Domain\Risk\Enums\RiskLevel;
use App\Domain\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Domain\AssignVendorRequest;
use App\Http\Requests\Domain\ManualPaymentRequestRequest;
use App\Http\Requests\Domain\ReviewPaymentRequestRequest;
use App\Http\Requests\Domain\UploadPaymentRequestRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Flash;
use App\Support\Paginated;
use App\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class PaymentRequestController extends Controller
{
    public function index(Request $request, CurrentWorkspace $current): Response
    {
        $this->authorize('viewAny', PaymentRequest::class);
        $workspace = $this->workspace($current);
        /** @var User $user */
        $user = $request->user();
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in([...PaymentRequestStatus::values(), 'open', 'decided'])],
            'risk' => ['nullable', Rule::enum(RiskLevel::class)],
            'kind' => ['nullable', Rule::enum(PaymentRequestKind::class)],
            'vendor_id' => ['nullable', 'integer'],
            'mine' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $timezone = $workspace->timezone ?: 'UTC';
        $visible = $this->visible($current, $user);
        $query = (clone $visible)
            ->when($filters['q'] ?? null, function (Builder $query, string $q): void {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $query->where(fn (Builder $inner) => $inner->where('reference', 'ilike', $like)->orWhere('title', 'ilike', $like)->orWhere('invoice_number', 'ilike', $like));
            })
            ->when($filters['status'] ?? null, function (Builder $query, string $status): void {
                match ($status) {
                    'open' => $query->whereIn('status', array_map(fn (PaymentRequestStatus $s): string => $s->value, PaymentRequestStatus::open())),
                    'decided' => $query->whereIn('status', array_map(fn (PaymentRequestStatus $s): string => $s->value, PaymentRequestStatus::decided())),
                    default => $query->where('status', $status),
                };
            })
            ->when($filters['risk'] ?? null, fn (Builder $query, string $risk) => $query->where('risk_level', $risk))
            ->when($filters['kind'] ?? null, fn (Builder $query, string $kind) => $query->where('kind', $kind))
            ->when($filters['vendor_id'] ?? null, fn (Builder $query, int|string $vendor) => $query->where('vendor_id', (int) $vendor))
            ->when((bool) ($filters['mine'] ?? false), fn (Builder $query) => $query->where('requested_by', $user->id))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('created_at', '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('created_at', '<', CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay()->utc()))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $page = $query->paginate(25)->withQueryString();
        $rows = [];

        foreach (PaymentRequestRows::many($page->getCollection(), $user) as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return Inertia::render('payment-requests/Index', [
            'requests' => Paginated::from($page, fn (PaymentRequest $item): array => $rows[(int) $item->getKey()]),
            'filters' => array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== ''),
            'counts' => [
                'open' => (clone $visible)->whereIn('status', array_map(fn (PaymentRequestStatus $s): string => $s->value, PaymentRequestStatus::open()))->count(),
                'review' => (clone $visible)->where('status', PaymentRequestStatus::Review->value)->count(),
                'awaiting_verification' => (clone $visible)->where('status', PaymentRequestStatus::AwaitingVerification->value)->count(),
                'awaiting_approval' => (clone $visible)->where('status', PaymentRequestStatus::AwaitingApproval->value)->count(),
                'decided' => (clone $visible)->whereIn('status', array_map(fn (PaymentRequestStatus $s): string => $s->value, PaymentRequestStatus::decided()))->count(),
            ],
            'vendors' => $this->vendorRefs($workspace),
        ]);
    }

    public function create(CurrentWorkspace $current, PlanLimits $limits): Response
    {
        $this->authorize('create', PaymentRequest::class);
        $workspace = $this->workspace($current);

        return Inertia::render('payment-requests/Create', [
            'accept' => UploadInspector::acceptList(),
            'maxFiles' => (int) config('scampause.uploads.max_files', 5),
            'maxSizeMb' => intdiv((int) config('scampause.uploads.max_file_kb', 15360), 1024),
            'kinds' => PaymentRequestKind::options(),
            'currencies' => Currencies::codes(),
            'base_currency' => strtoupper($workspace->currency),
            'vendors' => $this->vendorRefs($workspace),
            'limit' => [
                'used' => $limits->used($workspace, PaymentRequestsMonthlyUsage::KEY),
                'limit' => $limits->limit($workspace, PaymentRequestsMonthlyUsage::KEY),
            ],
        ]);
    }

    public function store(UploadPaymentRequestRequest $request, CurrentWorkspace $current, IntakeService $intake): RedirectResponse
    {
        $this->authorize('create', PaymentRequest::class);
        /** @var User $user */
        $user = $request->user();
        $created = $intake->createFromUpload(
            $this->workspace($current),
            $user,
            $request->inspectedFiles(),
            $request->kind(),
            $request->description(),
            $request->vendorId(),
        );

        Flash::success('domain.flash.request_created', ['reference' => (string) $created->getAttribute('reference')]);

        return redirect()->route('payment-requests.show', ['paymentRequest' => $created->getKey()]);
    }

    public function manual(ManualPaymentRequestRequest $request, CurrentWorkspace $current, IntakeService $intake): RedirectResponse
    {
        $this->authorize('create', PaymentRequest::class);
        /** @var User $user */
        $user = $request->user();
        $created = $intake->createManual($this->workspace($current), $user, $request->entry());

        Flash::success('domain.flash.request_created', ['reference' => (string) $created->getAttribute('reference')]);

        return redirect()->route('payment-requests.show', ['paymentRequest' => $created->getKey()]);
    }

    public function show(Request $request, PaymentRequest $paymentRequest, CurrentWorkspace $current, CaseFilePresenter $presenter): Response
    {
        $this->authorize('view', $paymentRequest);
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('payment-requests/Show', $presenter->show($paymentRequest, $user, $this->workspace($current)));
    }

    public function status(PaymentRequest $paymentRequest): JsonResponse
    {
        $this->authorize('view', $paymentRequest);

        return response()->json(CaseFilePresenter::pipelineStatus($paymentRequest->refresh()))
            ->header('Cache-Control', 'no-store');
    }

    public function update(ReviewPaymentRequestRequest $request, PaymentRequest $paymentRequest, ReviewService $review): RedirectResponse
    {
        $this->authorize('view', $paymentRequest);
        $this->authorize('review', $paymentRequest);
        /** @var User $user */
        $user = $request->user();

        return $this->guard(function () use ($review, $paymentRequest, $user, $request): void {
            $review->update($paymentRequest, $user, $request->entry());
            Flash::success('domain.flash.request_updated');
        });
    }

    public function vendor(AssignVendorRequest $request, PaymentRequest $paymentRequest, ReviewService $review): RedirectResponse
    {
        $this->authorize('view', $paymentRequest);
        $this->authorize('review', $paymentRequest);
        /** @var User $user */
        $user = $request->user();

        return $this->guard(function () use ($review, $paymentRequest, $user, $request): void {
            $review->setVendor($paymentRequest, $user, $request->vendorId());
            Flash::success('domain.flash.vendor_updated');
        });
    }

    public function reassess(Request $request, PaymentRequest $paymentRequest, ReviewService $review): RedirectResponse
    {
        $this->authorize('view', $paymentRequest);
        $this->authorize('review', $paymentRequest);
        $validated = $request->validate(['reextract' => ['nullable', 'boolean']]);
        /** @var User $user */
        $user = $request->user();

        return $this->guard(function () use ($review, $paymentRequest, $user, $validated): void {
            $review->reassess($paymentRequest, $user, (bool) ($validated['reextract'] ?? false));
            Flash::success('domain.flash.reassessed');
        });
    }

    /**
     * @param  \Closure(): void  $action
     */
    private function guard(\Closure $action): RedirectResponse
    {
        try {
            $action();
        } catch (InvalidTransition) {
            Flash::error('domain.flash.invalid_transition');
        }

        return back();
    }

    /**
     * @return Builder<PaymentRequest>
     */
    private function visible(CurrentWorkspace $current, User $user): Builder
    {
        $query = PaymentRequest::query();

        if (! $current->allows('payment_requests.view_all')) {
            $query->where('requested_by', $user->id);
        }

        return $query;
    }

    /**
     * @return list<array{id: int, name: string, kind: string, status: string}>
     */
    private function vendorRefs(Workspace $workspace): array
    {
        return array_values(Vendor::query()->forWorkspace($workspace)->orderBy('name')->limit(500)->get()
            ->map(fn (Vendor $vendor): array => [
                'id' => (int) $vendor->getKey(),
                'name' => (string) $vendor->getAttribute('name'),
                'kind' => (string) Refs::enum($vendor->getAttribute('kind')),
                'status' => (string) Refs::enum($vendor->getAttribute('status')),
            ])
            ->all());
    }

    private function workspace(CurrentWorkspace $current): Workspace
    {
        $workspace = $current->get();
        abort_if($workspace === null, 404);

        return $workspace;
    }
}
