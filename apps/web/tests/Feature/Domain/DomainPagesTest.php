<?php

declare(strict_types=1);

use App\Domain\Approvals\Models\Approval;
use App\Domain\Evidence\Models\EvidenceEntry;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Policy\Models\ApprovalRule;
use App\Domain\Policy\Models\MemberLimit;
use App\Domain\Vendors\Models\Vendor;
use App\Domain\Workflow\SubmissionService;
use App\Enums\WorkspaceRole;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Domain\Scenario;

beforeEach(function (): void {
    Storage::fake('local');
    config(['inertia.testing.ensure_pages_exist' => false]);
});

function pagesScenario(): array
{
    $s = Scenario::make([WorkspaceRole::Owner, WorkspaceRole::Admin, WorkspaceRole::Approver, WorkspaceRole::Bookkeeper, WorkspaceRole::Member, WorkspaceRole::Auditor]);
    $vendor = $s->vendor();
    $s->verifiedAccount($vendor);
    $request = $s->request('bookkeeper', ['vendorId' => (int) $vendor->getKey()]);
    app(SubmissionService::class)->submit($request, $s->user('bookkeeper'));

    return [$s, $vendor, $request->refresh()];
}

it('renders the dashboard with every contract key', function (): void {
    [$s] = pagesScenario();
    $this->actingAs($s->user('owner'));

    $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->hasAll(['period.from', 'period.to'])
        ->hasAll(['kpis.open_checks', 'kpis.awaiting_my_approval', 'kpis.awaiting_verification', 'kpis.high_or_critical_30d', 'kpis.prevented_amount_30d', 'kpis.prevented_cases_30d', 'kpis.median_decision_hours_30d', 'kpis.verification_compliance_30d'])
        ->where('kpis.awaiting_my_approval', 1)
        ->has('attention', 1)
        ->has('recent_bank_changes')
        ->has('charts.risk_by_week', 12)
        ->has('charts.prevented_vs_approved', 6)
        ->has('charts.top_signals')
        ->hasAll(['charts.decision_sla.on_time', 'charts.decision_sla.expired', 'charts.decision_sla.pending'])
        ->where('onboarding.has_vendors', true)
        ->where('onboarding.has_callback_contacts', true)
        ->where('onboarding.has_second_approver', true)
        ->where('onboarding.telegram_linked', false)
        ->etc());
});

it('lists my approvals with inline approve only for low risk', function (): void {
    [$s, , $request] = pagesScenario();
    $this->actingAs($s->user('owner'));

    $this->get(route('approvals.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('approvals/Index')
        ->where('tab', 'pending')
        ->has('items.data', 1)
        ->where('items.data.0.id', $request->id)
        ->hasAll(['items.data.0.approval.id', 'items.data.0.route_expires_at', 'items.data.0.telegram_allowed'])
        ->where('items.data.0.approval.can_decide', true)
        ->where('items.data.0.inline_approve', true)
        ->where('counts.pending', 1)
        ->etc());

    $this->actingAs($s->user('bookkeeper'))->get(route('approvals.index'))->assertForbidden();
});

it('approves and rejects through the web routes with SoD and assignment checks', function (): void {
    [$s, , $request] = pagesScenario();
    $approval = Approval::query()->withoutGlobalScopes()->where('payment_request_id', $request->id)->firstOrFail();
    $url = route('payment-requests.approvals.approve', ['paymentRequest' => $request->id, 'approval' => $approval->id]);

    $this->actingAs($s->user('admin'))->post($url)->assertForbidden();
    $this->actingAs($s->user('owner'))->post($url)->assertRedirect();

    expect($request->refresh()->getAttribute('status'))->toBe(PaymentRequestStatus::Approved);
    $this->actingAs($s->user('owner'))->post($url)->assertSessionHas('error');
});

it('renders vendor pages and enforces permissions', function (): void {
    [$s, $vendor] = pagesScenario();
    $this->actingAs($s->user('bookkeeper'));

    $this->get(route('vendors.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('vendors/Index')
        ->has('vendors.data', 1)
        ->hasAll(['vendors.data.0.callback_contact', 'vendors.data.0.verified_account', 'vendors.data.0.domains', 'vendors.data.0.accounts_count', 'vendors.data.0.open_requests', 'vendors.data.0.recent_change'])
        ->where('stats.total', 1)
        ->where('stats.missing_callback', 0)
        ->has('limit')
        ->etc());

    $this->get(route('vendors.show', $vendor->id))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('vendors/Show')
        ->has('contacts', 1)
        ->has('accounts', 1)
        ->where('accounts.0.status', 'verified')
        ->where('accounts.0.masked', '•••• 4417')
        ->has('domains', 1)
        ->has('requests', 1)
        ->has('amount_history', 12)
        ->where('abilities', ['manage' => true, 'verify_bank' => false, 'reveal' => true])
        ->etc());

    $this->get(route('vendors.create'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('vendors/Form')->where('vendor', null)->has('kinds')->has('countries')->has('members')->etc());

    $this->actingAs($s->user('member'))->get(route('vendors.index'))->assertForbidden();
    $this->actingAs($s->user('approver'))->post(route('vendors.store'), ['kind' => 'supplier', 'name' => 'New'])->assertForbidden();
});

it('creates vendors, contacts, domains and accounts through the web', function (): void {
    [$s] = pagesScenario();
    $this->actingAs($s->user('bookkeeper'));

    $this->post(route('vendors.store'), ['kind' => 'supplier', 'name' => 'Harbor Logistics Group', 'country' => 'US', 'tax_id' => '12-3456789'])->assertRedirect();
    $vendor = Vendor::query()->withoutGlobalScopes()->where('name', 'Harbor Logistics Group')->firstOrFail();

    $this->post(route('vendors.store'), ['kind' => 'supplier', 'name' => 'harbor logistics group'])->assertSessionHasErrors('name');
    $this->post(route('vendors.domains.store', $vendor->id), ['domain' => 'gmail.com', 'source' => 'manual'])->assertSessionHasErrors('domain');
    $this->post(route('vendors.domains.store', $vendor->id), ['domain' => 'WWW.HarborLG.com', 'source' => 'contract'])->assertSessionHasNoErrors();
    $this->post(route('vendors.contacts.store', $vendor->id), ['name' => 'Tom', 'is_callback' => true, 'source' => 'invoice_email', 'phone' => '+12015550164', 'source_ref' => 'mail'])->assertSessionHasErrors('source');
    $this->post(route('vendors.contacts.store', $vendor->id), ['name' => 'Tom Becker', 'is_callback' => true, 'source' => 'contract', 'phone' => '+1 201 555 0164', 'source_ref' => 'Freight Services Agreement §7'])->assertSessionHasNoErrors();
    $this->post(route('vendors.bank-accounts.store', $vendor->id), ['scheme' => 'us_ach', 'account_number' => '5501206604', 'routing_code' => '123456789'])->assertSessionHasErrors('routing_code');
    $this->post(route('vendors.bank-accounts.store', $vendor->id), ['scheme' => 'us_ach', 'account_number' => '5501206604', 'routing_code' => '031101279'])->assertSessionHasNoErrors();
    $this->post(route('vendors.bank-accounts.store', $vendor->id), ['scheme' => 'us_ach', 'account_number' => '5501206604', 'routing_code' => '031101279'])->assertSessionHasErrors('account_number');

    $vendor->refresh();
    expect($vendor->domains()->withoutGlobalScopes()->pluck('domain')->all())->toBe(['harborlg.com'])
        ->and($vendor->contacts()->withoutGlobalScopes()->value('status')->value)->toBe('pending_verification')
        ->and($vendor->bankAccounts()->withoutGlobalScopes()->value('last4'))->toBe('6604');

    $contact = $vendor->contacts()->withoutGlobalScopes()->firstOrFail();
    $this->actingAs($s->user('admin'))->post(route('vendors.contacts.verify', ['vendor' => $vendor->id, 'contact' => $contact->id]))->assertSessionHasNoErrors();
    expect($contact->refresh()->getAttribute('status')->value)->toBe('verified');
});

it('keeps vendors of other workspaces invisible', function (): void {
    [$s, $vendor] = pagesScenario();
    $other = Scenario::make([WorkspaceRole::Owner]);
    $this->actingAs($other->user('owner'));

    $this->get(route('vendors.show', $vendor->id))->assertNotFound();
    $this->post(route('vendors.contacts.store', $vendor->id), ['name' => 'X', 'is_callback' => false, 'source' => 'contract'])->assertNotFound();
});

it('renders blocked accounts, policies and reports', function (): void {
    [$s] = pagesScenario();
    $this->actingAs($s->user('auditor'));

    $this->get(route('blocked-accounts.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('blocked-accounts/Index')->has('items.data', 0)->where('abilities.manage', false)->etc());
    $this->get(route('policies.index', ['tab' => 'limits']))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('policies/Index')
        ->where('tab', 'limits')
        ->has('rules', 0)
        ->has('members', 6)
        ->hasAll(['settings.own_domains', 'settings.business_days', 'settings.verification_amount_threshold', 'settings.telegram_approve_max_risk'])
        ->has('exchange_rates')
        ->where('base_currency', 'USD')
        ->hasAll(['options.kinds', 'options.roles', 'options.risk_levels', 'limits.rules', 'limits.telegram_approvals'])
        ->where('abilities.manage', false)
        ->etc());
    $this->get(route('reports.index', ['preset' => '90d']))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('reports/Index')
        ->where('period.preset', '90d')
        ->hasAll(['kpis.checks', 'kpis.prevented_amount', 'kpis.prevented_cases', 'kpis.duplicates_prevented', 'kpis.bank_changes_caught', 'kpis.legit_changes_verified', 'kpis.median_decision_hours', 'kpis.verification_compliance', 'kpis.expired_rate'])
        ->hasAll(['series.checks_by_week', 'series.prevented_by_month', 'series.signals', 'series.decision_time_histogram', 'series.by_kind'])
        ->has('series.decision_time_histogram', 5)
        ->has('prevented_cases')
        ->has('exports')
        ->hasAll(['chain.entries', 'abilities.export', 'abilities.verify_chain', 'abilities.exports_allowed_by_plan'])
        ->where('abilities.verify_chain', true)
        ->etc());

    $this->postJson(route('evidence.verify'))->assertOk()->assertJson(['ok' => true, 'first_broken_seq' => null])->assertJsonStructure(['ok', 'checked', 'first_broken_seq', 'head_hash', 'verified_at']);
    $this->put(route('policies.settings.update'), [])->assertForbidden();
    $this->actingAs($s->user('member'))->get(route('reports.index'))->assertForbidden();
});

it('manages rules, limits, settings and rates with governance evidence', function (): void {
    [$s] = pagesScenario();
    $this->actingAs($s->user('admin'));

    $this->post(route('policies.rules.store'), [
        'name' => 'Dual control from $3,000', 'is_active' => true, 'min_amount' => '3000', 'approvals_required' => 2,
        'required_roles' => [], 'approver_roles' => ['owner', 'admin', 'approver'], 'verification_required' => false,
    ])->assertSessionHasNoErrors();
    $this->post(route('policies.rules.store'), [
        'name' => 'Bad', 'is_active' => true, 'min_amount' => '5000', 'max_amount' => '100', 'approvals_required' => 1,
        'required_roles' => ['owner', 'admin'], 'approver_roles' => ['owner'], 'verification_required' => false,
    ])->assertSessionHasErrors(['max_amount', 'approvals_required']);
    $rule = ApprovalRule::query()->withoutGlobalScopes()->firstOrFail();
    $this->patch(route('policies.rules.update', $rule->id), [
        'name' => 'Dual control from $3,000', 'is_active' => false, 'min_amount' => '3000', 'approvals_required' => 2,
        'required_roles' => [], 'approver_roles' => ['owner', 'admin', 'approver'], 'verification_required' => false,
    ])->assertSessionHasNoErrors();
    $this->put(route('policies.limits.update', $s->user('approver')->id), ['approval_limit' => '25000', 'monthly_limit' => '60000'])->assertSessionHasNoErrors();
    $this->post(route('policies.exchange-rates.store'), ['quote_currency' => 'EUR', 'rate' => '1.085', 'effective_date' => now()->toDateString()])->assertSessionHasNoErrors();

    expect($rule->refresh()->getAttribute('version'))->toBe(2)
        ->and(MemberLimit::query()->withoutGlobalScopes()->value('approval_limit_base_minor'))->toBe(2500000)
        ->and(EvidenceEntry::query()->withoutGlobalScopes()->whereIn('event', ['policy.rule_created', 'policy.rule_deactivated', 'policy.limit_changed'])->whereNull('payment_request_id')->count())->toBe(3);

    $this->postJson(route('policies.simulate'), ['amount' => '4000', 'currency' => 'EUR'])
        ->assertOk()
        ->assertJsonPath('approvals_required', 1)
        ->assertJsonPath('amount_base.minor', 434000)
        ->assertJsonStructure(['approvals_required', 'required_roles', 'approver_roles', 'verification_required', 'expiry_hours', 'matched_rules', 'notes', 'amount_base', 'eligible_approvers']);
});
