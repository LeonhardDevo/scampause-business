<?php

declare(strict_types=1);

use App\Domain\Approvals\ApprovalService;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Evidence\EvidenceRetention;
use App\Domain\Evidence\Models\EvidenceEntry;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Privacy\RetentionPurger as DomainRetentionPurger;
use App\Domain\Workflow\SubmissionService;
use App\Privacy\PrivacyPurge;
use App\Privacy\RetentionPolicy;
use App\Privacy\RetentionPurger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Domain\Scenario;

beforeEach(function (): void {
    Storage::fake('local');
});

function approvedRequest(Scenario $s): PaymentRequest
{
    $vendor = $s->vendor();
    $s->verifiedAccount($vendor);
    $request = $s->request('bookkeeper', ['vendorId' => (int) $vendor->getKey()]);
    $submitted = app(SubmissionService::class)->submit($request, $s->user('bookkeeper'));
    $approval = Approval::query()->withoutGlobalScopes()->where('payment_request_id', $submitted->getKey())->firstOrFail();
    app(ApprovalService::class)->approve($approval, $s->user('owner'));

    return $submitted->refresh();
}

function ageEvidence(int $workspaceId, CarbonImmutable $at): void
{
    DB::transaction(function () use ($workspaceId, $at): void {
        DB::statement("SET LOCAL scampause.allow_evidence_purge = 'on'");
        DB::table('evidence_entries')->where('workspace_id', $workspaceId)->update(['occurred_at' => $at]);
    });
}

it('registers the domain retention categories with plan caps that match the billing entitlements', function (): void {
    $policy = app(RetentionPolicy::class);

    foreach (['free', 'starter', 'pro', 'business'] as $plan) {
        expect(config("retention.plan_max.{$plan}.scampause_files"))->toBe(config("billing.plans.{$plan}.entitlements.file_retention_days"));
    }

    expect(array_keys($policy->overridable()))->toContain('scampause_files', 'scampause_evidence', 'scampause_exports', 'scampause_notifications')
        ->and($policy->default('scampause_evidence'))->toBe(2555)
        ->and($policy->bounds('scampause_evidence', null))->toBe(['min' => 1825, 'max' => 3650])
        ->and($policy->default('scampause_domain_intel'))->toBe(90)
        ->and(config('retention.contributors'))->toBe([DomainRetentionPurger::class]);

    $s = Scenario::make();
    expect($policy->days('scampause_files', $s->workspace))->toBe(90);
    $s->workspace->forceFill(['retention' => ['scampause_files' => 5000, 'scampause_evidence' => 400]])->save();
    expect($policy->days('scampause_files', $s->workspace->refresh()))->toBe(90)
        ->and($policy->days('scampause_evidence', $s->workspace))->toBe(1825);
});

it('purges the ledger prefix after the retention period, appends a checkpoint and keeps the chain verifiable', function (): void {
    $s = Scenario::make();
    $request = approvedRequest($s);
    $ledger = app(EvidenceLedger::class);
    $workspaceId = $s->workspace->id;
    $before = EvidenceEntry::query()->withoutGlobalScopes()->where('workspace_id', $workspaceId)->orderBy('seq')->get();
    $lastOld = $before->last();

    ageEvidence($workspaceId, CarbonImmutable::now()->subYears(8));
    DB::table('payment_requests')->where('id', $request->getKey())->update(['decided_at' => now()->subYears(8), 'updated_at' => now()->subYears(8)]);
    $ledger->append($workspaceId, 'policy.settings_changed', 'workspace', $workspaceId, ['keys' => ['quiet_hours_start']], $s->user('owner')->id);

    $counts = app(RetentionPurger::class)->run();
    $remaining = EvidenceEntry::query()->withoutGlobalScopes()->where('workspace_id', $workspaceId)->orderBy('seq')->get();
    $checkpoint = $remaining->firstWhere('event', EvidenceRetention::CHECKPOINT_EVENT);

    expect($counts['scampause_evidence'])->toBeGreaterThanOrEqual($before->count())
        ->and($remaining->pluck('event')->all())->toBe(['policy.settings_changed', EvidenceRetention::CHECKPOINT_EVENT])
        ->and($checkpoint?->getAttribute('payload'))->toEqual(['last_purged_hash' => $lastOld?->getAttribute('hash'), 'purged_to_seq' => (int) $lastOld?->getAttribute('seq')])
        ->and($remaining->first()?->getAttribute('prev_hash'))->toBe($lastOld?->getAttribute('hash'))
        ->and($ledger->verify($workspaceId))->toMatchArray(['ok' => true, 'checked' => 2]);

    expect(DB::table('verifications')->where('payment_request_id', $request->getKey())->count())->toBe(0)
        ->and(DB::table('approvals')->where('payment_request_id', $request->getKey())->count())->toBe(0)
        ->and(DB::table('risk_assessments')->where('payment_request_id', $request->getKey())->count())->toBe(0)
        ->and($request->refresh()->getAttribute('status'))->toBe(PaymentRequestStatus::Approved);

    DB::transaction(function () use ($checkpoint): void {
        DB::statement("SET LOCAL scampause.allow_evidence_purge = 'on'");
        DB::table('evidence_entries')->where('id', $checkpoint?->getKey())->update(['payload' => json_encode(['last_purged_hash' => str_repeat('a', 64), 'purged_to_seq' => 999])]);
    });

    expect($ledger->verify($workspaceId))->toMatchArray(['ok' => false, 'first_broken_seq' => (int) $remaining->first()?->getAttribute('seq')]);
});

it('keeps evidence inside the retention period and is idempotent', function (): void {
    $s = Scenario::make();
    $request = approvedRequest($s);
    $entries = EvidenceEntry::query()->withoutGlobalScopes()->where('workspace_id', $s->workspace->id)->count();

    $first = app(RetentionPurger::class)->run();
    $second = app(RetentionPurger::class)->run();

    expect($first['scampause_evidence'])->toBe(0)
        ->and($second['scampause_evidence'])->toBe(0)
        ->and(EvidenceEntry::query()->withoutGlobalScopes()->where('workspace_id', $s->workspace->id)->count())->toBe($entries)
        ->and(DB::table('approvals')->where('payment_request_id', $request->getKey())->count())->toBe(1)
        ->and(app(EvidenceLedger::class)->verify($s->workspace->id)['ok'])->toBeTrue();
});

it('reports the domain categories through the scheduled privacy purge', function (): void {
    $s = Scenario::make();
    DB::table('domain_intel')->insert([
        'domain' => 'expired-lookup.test',
        'rdap_status' => 'ok',
        'source' => 'live',
        'checked_at' => now()->subDays(120),
        'fresh_until' => now()->subDays(90),
        'created_at' => now()->subDays(120),
        'updated_at' => now()->subDays(120),
    ]);
    DB::table('sp_notifications')->insert([
        'workspace_id' => $s->workspace->id,
        'template' => 'sp.approval_requested',
        'recipient_user_id' => $s->user('owner')->id,
        'channel' => 'telegram',
        'status' => 'sent',
        'created_at' => now()->subDays(400),
        'updated_at' => now()->subDays(400),
    ]);

    $result = app(PrivacyPurge::class)->run();

    expect($result['retention'])->toHaveKeys(['ai_queries', 'audit_logs', 'scampause_files', 'scampause_evidence', 'scampause_exports', 'scampause_notifications', 'scampause_domain_intel'])
        ->and($result['retention']['scampause_domain_intel'])->toBe(1)
        ->and($result['retention']['scampause_notifications'])->toBe(1);
});
