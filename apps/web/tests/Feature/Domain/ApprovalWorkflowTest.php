<?php

declare(strict_types=1);

use App\Domain\Approvals\ApprovalService;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Enums\RouteStatus;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Approvals\Models\ApprovalRoute;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Evidence\Models\EvidenceEntry;
use App\Domain\Intake\Enums\CancelReason;
use App\Domain\Intake\Enums\DecisionReason;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Policy\Models\MemberLimit;
use App\Domain\Policy\PolicyService;
use App\Domain\Workflow\DecisionService;
use App\Domain\Workflow\SubmissionService;
use App\Domain\Workflow\WorkflowException;
use App\Enums\WorkspaceRole;
use App\Models\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Domain\Scenario;

beforeEach(function (): void {
    Storage::fake('local');
});

function knownVendorRequest(Scenario $s, string $user = 'bookkeeper', array $overrides = []): PaymentRequest
{
    $vendor = $s->vendor();
    $s->verifiedAccount($vendor);

    return $s->request($user, ['vendorId' => (int) $vendor->getKey(), ...$overrides]);
}

it('routes a low-risk request to one approver by the fallback rule and completes on approval', function (): void {
    $s = Scenario::make();
    $request = knownVendorRequest($s);
    expect($request->getAttribute('status'))->toBe(PaymentRequestStatus::Review)
        ->and($request->getAttribute('verification_required'))->toBeFalse();

    $submitted = app(SubmissionService::class)->submit($request, $s->user('bookkeeper'));
    $route = ApprovalRoute::query()->withoutGlobalScopes()->findOrFail($submitted->getAttribute('current_route_id'));
    $approvals = Approval::query()->withoutGlobalScopes()->where('approval_route_id', $route->getKey())->get();

    expect($submitted->getAttribute('status'))->toBe(PaymentRequestStatus::AwaitingApproval)
        ->and($route->getAttribute('approvals_required'))->toBe(1)
        ->and($approvals)->toHaveCount(1)
        ->and($approvals[0]->getAttribute('approver_id'))->toBe($s->user('owner')->id)
        ->and($route->getAttribute('expires_at'))->not->toBeNull()
        ->and(OutboxMessage::query()->where('topic', 'approval.requested')->count())->toBe(1)
        ->and(OutboxMessage::query()->where('topic', 'approval.expiry.due')->count())->toBe(1)
        ->and(OutboxMessage::query()->where('topic', 'approval.reminder.due')->count())->toBeGreaterThanOrEqual(1);

    app(ApprovalService::class)->approve($approvals[0], $s->user('owner'));
    $request->refresh();

    expect($request->getAttribute('status'))->toBe(PaymentRequestStatus::Approved)
        ->and($request->getAttribute('decided_by'))->toBe($s->user('owner')->id)
        ->and($route->refresh()->getAttribute('status'))->toBe(RouteStatus::Completed)
        ->and(OutboxMessage::query()->where('topic', 'evidence.packet.requested')->count())->toBe(1)
        ->and(app(EvidenceLedger::class)->verify($s->workspace->id)['ok'])->toBeTrue()
        ->and(EvidenceEntry::query()->withoutGlobalScopes()->where('payment_request_id', $request->getKey())->pluck('event')->all())
        ->toContain('request.submitted', 'approval.route_created', 'approval.assigned', 'approval.approved', 'request.approved');
});

it('never assigns the requester or the submitter and rejects decisions by others', function (): void {
    $s = Scenario::make();
    app(PolicyService::class)->createRule($s->workspace, $s->user('owner'), [
        'name' => 'Dual control', 'is_active' => true, 'approvals_required' => 2, 'required_roles' => [], 'approver_roles' => ['owner', 'admin', 'approver'], 'verification_required' => false,
    ]);
    $request = knownVendorRequest($s, 'admin');
    app(SubmissionService::class)->submit($request, $s->user('approver'));
    $assigned = Approval::query()->withoutGlobalScopes()->where('payment_request_id', $request->getKey())->pluck('approver_id')->all();

    expect($assigned)->toHaveCount(1)
        ->and($assigned)->not->toContain($s->user('admin')->id)
        ->and($assigned)->not->toContain($s->user('approver')->id)
        ->and($assigned)->toContain($s->user('owner')->id);

    $route = ApprovalRoute::query()->withoutGlobalScopes()->where('payment_request_id', $request->getKey())->firstOrFail();
    expect($route->getAttribute('approvals_required'))->toBe(2)
        ->and(EvidenceEntry::query()->withoutGlobalScopes()->where('event', 'route.incomplete')->exists())->toBeTrue();

    $approval = Approval::query()->withoutGlobalScopes()->where('payment_request_id', $request->getKey())->firstOrFail();
    expect(fn () => app(ApprovalService::class)->approve($approval, $s->user('admin')))->toThrow(WorkflowException::class);

    app(ApprovalService::class)->approve($approval, $s->user('owner'));
    expect($request->refresh()->getAttribute('status'))->toBe(PaymentRequestStatus::AwaitingApproval);
});

it('requires the owner role when the rule asks for it', function (): void {
    $s = Scenario::make();
    app(PolicyService::class)->createRule($s->workspace, $s->user('owner'), [
        'name' => 'Owner sign-off', 'is_active' => true, 'min_amount_base_minor' => 100000, 'approvals_required' => 2, 'required_roles' => ['owner'], 'approver_roles' => ['owner', 'admin', 'approver'], 'verification_required' => false,
    ]);
    $request = knownVendorRequest($s);
    app(SubmissionService::class)->submit($request, $s->user('bookkeeper'));
    $approvers = Approval::query()->withoutGlobalScopes()->where('payment_request_id', $request->getKey())->orderBy('slot')->pluck('approver_id')->all();

    expect($approvers)->toHaveCount(2)
        ->and($approvers[0])->toBe($s->user('owner')->id);
});

it('excludes approvers whose per-payment or monthly limit is too low and uses only unlimited members without FX', function (): void {
    $s = Scenario::make([WorkspaceRole::Owner, WorkspaceRole::Approver, WorkspaceRole::Bookkeeper, WorkspaceRole::Admin]);
    MemberLimit::query()->create(['workspace_id' => $s->workspace->id, 'user_id' => $s->user('owner')->id, 'approval_limit_base_minor' => 1000, 'updated_by' => $s->user('owner')->id]);
    MemberLimit::query()->create(['workspace_id' => $s->workspace->id, 'user_id' => $s->user('approver')->id, 'monthly_limit_base_minor' => 100000, 'updated_by' => $s->user('owner')->id]);
    $request = knownVendorRequest($s);
    app(SubmissionService::class)->submit($request, $s->user('bookkeeper'));

    expect(Approval::query()->withoutGlobalScopes()->where('payment_request_id', $request->getKey())->pluck('approver_id')->all())->toBe([$s->user('admin')->id]);

    $euro = $s->request('bookkeeper', ['vendorId' => (int) $request->getAttribute('vendor_id'), 'currency' => 'EUR', 'amountMinor' => 50000]);
    expect($euro->getAttribute('amount_base_minor'))->toBeNull();
    app(SubmissionService::class)->submit($euro, $s->user('bookkeeper'));

    expect(Approval::query()->withoutGlobalScopes()->where('payment_request_id', $euro->getKey())->pluck('approver_id')->all())->toBe([$s->user('admin')->id]);
});

it('re-checks eligibility at decision time', function (): void {
    $s = Scenario::make();
    $request = knownVendorRequest($s);
    app(SubmissionService::class)->submit($request, $s->user('bookkeeper'));
    $approval = Approval::query()->withoutGlobalScopes()->where('payment_request_id', $request->getKey())->firstOrFail();
    MemberLimit::query()->create(['workspace_id' => $s->workspace->id, 'user_id' => $s->user('owner')->id, 'approval_limit_base_minor' => 100, 'updated_by' => $s->user('owner')->id]);

    expect(fn () => app(ApprovalService::class)->approve($approval, $s->user('owner')))
        ->toThrow(WorkflowException::class, 'approver_no_longer_eligible');
});

it('builds a solo route with sod waived when only one member can approve', function (): void {
    $s = Scenario::make([WorkspaceRole::Owner, WorkspaceRole::Admin, WorkspaceRole::Bookkeeper]);
    $vendor = $s->vendor();
    $s->verifiedAccount($vendor);
    $s->workspace->members()->updateExistingPivot($s->user('admin')->id, ['role' => WorkspaceRole::Bookkeeper->value]);
    $request = $s->request('owner', ['vendorId' => (int) $vendor->getKey()]);
    app(SubmissionService::class)->submit($request, $s->user('owner'));
    $request->refresh();

    expect($request->getAttribute('status'))->toBe(PaymentRequestStatus::AwaitingApproval);

    $route = ApprovalRoute::query()->withoutGlobalScopes()->findOrFail($request->getAttribute('current_route_id'));
    $approval = Approval::query()->withoutGlobalScopes()->where('approval_route_id', $route->getKey())->firstOrFail();

    expect($route->getAttribute('solo_mode'))->toBeTrue()
        ->and($approval->getAttribute('approver_id'))->toBe($s->user('owner')->id);

    app(ApprovalService::class)->approve($approval, $s->user('owner'));
    expect($request->refresh()->getAttribute('status'))->toBe(PaymentRequestStatus::Approved);
});

it('expires an overdue route, notifies and reopens with a new attempt', function (): void {
    $s = Scenario::make();
    $request = knownVendorRequest($s);
    app(SubmissionService::class)->submit($request, $s->user('bookkeeper'));
    $route = ApprovalRoute::query()->withoutGlobalScopes()->where('payment_request_id', $request->getKey())->firstOrFail();

    expect(app(DecisionService::class)->expireRoute($route))->toBeFalse();

    $route->forceFill(['expires_at' => now()->subMinutes(5)])->save();
    expect(app(DecisionService::class)->sweepExpired())->toBe([(int) $route->getKey()])
        ->and($request->refresh()->getAttribute('status'))->toBe(PaymentRequestStatus::Expired)
        ->and(Approval::query()->withoutGlobalScopes()->where('approval_route_id', $route->getKey())->value('status'))->toBe(ApprovalStatus::Expired);

    app(SubmissionService::class)->reopen($request, $s->user('bookkeeper'));
    $request->refresh();
    $current = ApprovalRoute::query()->withoutGlobalScopes()->findOrFail($request->getAttribute('current_route_id'));

    expect($request->getAttribute('status'))->toBe(PaymentRequestStatus::AwaitingApproval)
        ->and($current->getAttribute('attempt'))->toBe(2)
        ->and(EvidenceEntry::query()->withoutGlobalScopes()->where('event', 'request.reopened')->value('payload'))->toBe(['attempt' => 2]);
});

it('rejects with fraud_confirmed, keeps a confirmed vendor account off the block list and accounts prevented risk', function (): void {
    $s = Scenario::make();
    $request = knownVendorRequest($s);
    app(SubmissionService::class)->submit($request, $s->user('bookkeeper'));
    $approval = Approval::query()->withoutGlobalScopes()->where('payment_request_id', $request->getKey())->firstOrFail();

    app(ApprovalService::class)->reject($approval, $s->user('owner'), DecisionReason::FraudConfirmed, null);
    $request->refresh();

    expect($request->getAttribute('status'))->toBe(PaymentRequestStatus::Rejected)
        ->and($request->getAttribute('prevented_amount_base_minor'))->toBe(125000)
        ->and($request->getAttribute('prevented_category')?->value)->toBe('fraud')
        ->and(DB::table('blocked_bank_accounts')->count())->toBe(0)
        ->and(EvidenceEntry::query()->withoutGlobalScopes()->where('event', 'block.skipped_verified_account')->exists())->toBeTrue();
});

it('requires a comment for the other reason and lets only the creator cancel before decisions', function (): void {
    $s = Scenario::make();
    $request = knownVendorRequest($s);

    expect(fn () => app(DecisionService::class)->rejectFromReview($request, $s->user('admin'), DecisionReason::Other, null))->toThrow(WorkflowException::class)
        ->and(fn () => app(DecisionService::class)->cancel($request, $s->user('approver'), CancelReason::Withdrawn, null, false))->toThrow(WorkflowException::class);

    app(DecisionService::class)->cancel($request, $s->user('bookkeeper'), CancelReason::Withdrawn, null, false);
    expect($request->refresh()->getAttribute('status'))->toBe(PaymentRequestStatus::Cancelled);
});

it('marks an approved request as paid within the allowed window and updates the vendor', function (): void {
    $s = Scenario::make();
    $request = knownVendorRequest($s);
    app(SubmissionService::class)->submit($request, $s->user('bookkeeper'));
    $approval = Approval::query()->withoutGlobalScopes()->where('payment_request_id', $request->getKey())->firstOrFail();
    app(ApprovalService::class)->approve($approval, $s->user('owner'));

    expect(fn () => app(DecisionService::class)->markPaid($request, $s->user('bookkeeper'), CarbonImmutable::now()->addDay(), 'ACH 1'))->toThrow(WorkflowException::class);

    app(DecisionService::class)->markPaid($request, $s->user('bookkeeper'), CarbonImmutable::now(), 'ACH 0914-NSL');
    $request->refresh();

    expect($request->getAttribute('status'))->toBe(PaymentRequestStatus::Paid)
        ->and($request->getAttribute('payment_reference'))->toBe('ACH 0914-NSL')
        ->and(DB::table('vendors')->where('id', $request->getAttribute('vendor_id'))->value('last_paid_at'))->not->toBeNull()
        ->and(EvidenceEntry::query()->withoutGlobalScopes()->where('event', 'request.paid')->value('payload'))->toBe(['payment_reference_last4' => '-NSL']);
});

it('detects a tampered evidence entry', function (): void {
    $s = Scenario::make();
    knownVendorRequest($s);
    $ledger = app(EvidenceLedger::class);
    expect($ledger->verify($s->workspace->id)['ok'])->toBeTrue();

    $target = EvidenceEntry::query()->withoutGlobalScopes()->where('workspace_id', $s->workspace->id)->orderBy('seq')->skip(1)->firstOrFail();
    DB::transaction(function () use ($target): void {
        DB::statement("SET LOCAL scampause.allow_evidence_purge = 'on'");
        DB::table('evidence_entries')->where('id', $target->getKey())->update(['payload' => json_encode(['tampered' => true])]);
    });
    $result = $ledger->verify($s->workspace->id);

    expect($result['ok'])->toBeFalse()
        ->and($result['first_broken_seq'])->toBe((int) $target->getAttribute('seq'))
        ->and($result['checked'])->toBe((int) $target->getAttribute('seq') - 1);
});
