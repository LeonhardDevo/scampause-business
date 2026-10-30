<?php

declare(strict_types=1);

namespace App\Http\Controllers\Domain;

use App\Billing\PlanLimits;
use App\Domain\Intake\Presenters\Refs;
use App\Domain\Money\Currencies;
use App\Domain\Vendors\Enums\BankAccountStatus;
use App\Domain\Vendors\Enums\ContactStatus;
use App\Domain\Vendors\Enums\VendorKind;
use App\Domain\Vendors\Enums\VendorStatus;
use App\Domain\Vendors\Models\Vendor;
use App\Domain\Vendors\VendorPresenter;
use App\Domain\Vendors\VendorService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Domain\Concerns\RunsWorkflow;
use App\Http\Requests\Domain\VendorRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Flash;
use App\Support\Paginated;
use App\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Psr\Clock\ClockInterface;

final class VendorController extends Controller
{
    use RunsWorkflow;

    public function index(Request $request, CurrentWorkspace $current, VendorPresenter $presenter, PlanLimits $limits, ClockInterface $clock): Response
    {
        $this->allow($current, 'vendors.view');
        $workspace = $this->workspace($current);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'kind' => ['nullable', Rule::enum(VendorKind::class)],
            'status' => ['nullable', Rule::enum(VendorStatus::class)],
            'missing_callback' => ['nullable', 'boolean'],
        ]);
        $missingCallback = fn (Builder $query) => $query->whereDoesntHave('contacts', fn (Builder $inner) => $inner->where('is_callback', true)->where('status', ContactStatus::Verified->value));
        $query = Vendor::query()->forWorkspace($workspace)
            ->when($filters['q'] ?? null, function (Builder $query, string $q): void {
                $like = '%'.addcslashes(mb_strtolower($q, 'UTF-8'), '%_\\').'%';
                $query->where(fn (Builder $inner) => $inner->whereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw("lower(coalesce(legal_name, '')) like ?", [$like])
                    ->orWhereRaw('lower(aliases::text) like ?', [$like])
                    ->orWhere('tax_id_normalized', strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $q)))
                    ->orWhereHas('domains', fn (Builder $domains) => $domains->where('domain', 'like', $like)));
            })
            ->when($filters['kind'] ?? null, fn (Builder $query, string $kind) => $query->where('kind', $kind))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when((bool) ($filters['missing_callback'] ?? false), $missingCallback)
            ->orderBy('name');
        $page = $query->paginate(25)->withQueryString();
        $rows = [];

        foreach ($presenter->rows($workspace, $page->getCollection()) as $row) {
            $rows[(int) $row['id']] = $row;
        }

        $all = Vendor::query()->forWorkspace($workspace);
        $recentRows = $presenter->rows($workspace, (clone $all)->whereHas('bankAccounts', fn (Builder $inner) => $inner->where('status', BankAccountStatus::Verified->value)
            ->where('valid_from', '>=', CarbonImmutable::instance($clock->now())->subDays(30)))->get());

        return Inertia::render('vendors/Index', [
            'vendors' => Paginated::from($page, fn (Model $vendor): array => $rows[(int) $vendor->getKey()]),
            'filters' => array_filter($filters, static fn (mixed $value): bool => $value !== null && $value !== ''),
            'stats' => [
                'total' => (clone $all)->count(),
                'missing_callback' => $missingCallback(clone $all)->count(),
                'recent_changes' => count(array_filter($recentRows, static fn (array $row): bool => (bool) $row['recent_change'])),
                'employees' => (clone $all)->where('kind', VendorKind::Employee->value)->count(),
            ],
            'limit' => ['used' => $limits->used($workspace, VendorService::LIMIT_KEY), 'limit' => $limits->limit($workspace, VendorService::LIMIT_KEY)],
        ]);
    }

    public function create(CurrentWorkspace $current): Response
    {
        $this->allow($current, 'vendors.manage');

        return Inertia::render('vendors/Form', $this->formProps($this->workspace($current), null));
    }

    public function store(VendorRequest $request, CurrentWorkspace $current, VendorService $vendors): RedirectResponse
    {
        $this->allow($current, 'vendors.manage');
        $vendor = $vendors->create($this->workspace($current), $this->user($request), $request->validated());
        Flash::success('domain.flash.vendor_created', ['name' => (string) $vendor->getAttribute('name')]);

        return redirect()->route('vendors.show', ['vendor' => $vendor->getKey()]);
    }

    public function show(Request $request, Vendor $vendor, CurrentWorkspace $current, VendorPresenter $presenter): Response
    {
        $this->allow($current, 'vendors.view');

        return Inertia::render('vendors/Show', $presenter->show(
            $this->workspace($current),
            $vendor,
            $this->user($request),
            $current->allows('bank_details.reveal'),
            $current->allows('vendors.manage'),
            $current->allows('vendors.verify_bank'),
        ));
    }

    public function edit(Vendor $vendor, CurrentWorkspace $current): Response
    {
        $this->allow($current, 'vendors.manage');

        return Inertia::render('vendors/Form', $this->formProps($this->workspace($current), $vendor));
    }

    public function update(VendorRequest $request, Vendor $vendor, CurrentWorkspace $current, VendorService $vendors): RedirectResponse
    {
        $this->allow($current, 'vendors.manage');
        $vendors->update($vendor, $this->user($request), $request->validated());
        Flash::success('domain.flash.vendor_saved');

        return redirect()->route('vendors.show', ['vendor' => $vendor->getKey()]);
    }

    public function destroy(Request $request, Vendor $vendor, CurrentWorkspace $current, VendorService $vendors): RedirectResponse
    {
        $this->allow($current, 'vendors.manage');
        $vendors->archive($vendor, $this->user($request));
        Flash::success('domain.flash.vendor_archived');

        return redirect()->route('vendors.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function formProps(Workspace $workspace, ?Vendor $vendor): array
    {
        return [
            'vendor' => $vendor === null ? null : [
                'id' => (int) $vendor->getKey(),
                'kind' => (string) Refs::enum($vendor->getAttribute('kind')),
                'name' => (string) $vendor->getAttribute('name'),
                'legal_name' => is_string($v = $vendor->getAttribute('legal_name')) ? $v : null,
                'aliases' => array_values(array_filter((array) $vendor->getAttribute('aliases'), 'is_string')),
                'tax_id' => is_string($v = $vendor->getAttribute('tax_id')) ? $v : null,
                'country' => is_string($v = $vendor->getAttribute('country')) ? $v : null,
                'default_currency' => is_string($v = $vendor->getAttribute('default_currency')) ? $v : null,
                'status' => (string) Refs::enum($vendor->getAttribute('status')),
                'payment_terms_days' => is_int($v = $vendor->getAttribute('payment_terms_days')) ? $v : null,
                'notes' => is_string($v = $vendor->getAttribute('notes')) ? $v : null,
                'linked_user_id' => is_int($v = $vendor->getAttribute('linked_user_id')) ? $v : null,
            ],
            'kinds' => VendorKind::options(),
            'countries' => array_map(static fn (string $code): array => ['code' => $code, 'name' => (string) __('domain.countries.'.$code)], (array) config('scampause.countries', [])),
            'currencies' => Currencies::codes(),
            'members' => array_values($workspace->members()->orderBy('users.name')->get(['users.id', 'users.name'])
                ->map(static fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'avatar' => null])->all()),
        ];
    }
}
