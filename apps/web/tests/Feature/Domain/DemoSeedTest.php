<?php

declare(strict_types=1);

use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Enums\PreventedCategory;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Policy\Models\ApprovalRule;
use App\Domain\Policy\PaymentSettings;
use App\Domain\Vendors\Enums\BankAccountStatus;
use App\Domain\Vendors\Models\VendorBankAccount;
use App\Enums\WorkspaceRole;
use App\Legal\LegalAcceptances;
use App\Models\Document;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Seeders\Domain\DemoWorkspaceSeeder;
use Database\Seeders\Domain\DomainSeeder;
use Database\Seeders\Domain\KnowledgeSeeder;
use Database\Seeders\Domain\NorthwindCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake((string) config('filesystems.documents_disk', 'documents'));
    config(['scampause.demo_seed' => true]);
});

function seededRequest(Workspace $workspace, string $reference): PaymentRequest
{
    return PaymentRequest::query()->forWorkspace($workspace)->where('reference', $reference)->firstOrFail();
}

/** @return list<string> */
function seededSignals(PaymentRequest $request): array
{
    return DB::table('risk_signals')->where('risk_assessment_id', $request->getAttribute('risk_assessment_id'))->pluck('code')->map(fn (mixed $code): string => (string) $code)->all();
}

it('seeds the bilingual demo through the domain services with a consistent story', function (): void {
    $this->seed(DomainSeeder::class);

    $northwind = Workspace::query()->where('slug', 'northwind')->sole();
    $kedr = Workspace::query()->where('slug', 'kedr')->sole();
    $user = fn (string $email): User => User::query()->where('email', $email.'@scampause-business.test')->sole();

    expect($user('demo')->roleIn($northwind))->toBe(WorkspaceRole::Owner)
        ->and($user('demo')->roleIn($kedr))->toBe(WorkspaceRole::Owner)
        ->and($user('maria')->roleIn($northwind))->toBe(WorkspaceRole::Admin)
        ->and($user('sam')->roleIn($northwind))->toBe(WorkspaceRole::Approver)
        ->and($user('irina')->roleIn($northwind))->toBe(WorkspaceRole::Bookkeeper)
        ->and($user('irina')->roleIn($kedr))->toBe(WorkspaceRole::Bookkeeper)
        ->and($user('jordan')->roleIn($northwind))->toBe(WorkspaceRole::Member)
        ->and($user('auditor')->roleIn($northwind))->toBe(WorkspaceRole::Auditor)
        ->and($user('olga')->roleIn($kedr))->toBe(WorkspaceRole::Admin)
        ->and($user('admin')->roleIn($northwind))->toBeNull()
        ->and($user('jordan')->telegramAccount?->telegram_user_id)->toBe(900005)
        ->and($kedr->currency)->toBe('RUB')
        ->and(PaymentSettings::country($kedr))->toBe('RU')
        ->and($kedr->country)->toBe('RU')
        ->and($northwind->country)->toBe('US')
        ->and($kedr->subscription?->currency)->toBe('RUB')
        ->and($northwind->subscription?->currency)->toBe('USD');

    $acceptances = app(LegalAcceptances::class);

    foreach (['demo', 'maria', 'sam', 'irina', 'jordan', 'auditor', 'olga', 'admin'] as $key) {
        $member = $user($key);

        foreach ([$northwind, $kedr] as $workspace) {
            expect($acceptances->pending($member, $workspace, $member->locale))->toBe([], "{$key} has pending legal documents");
        }
    }

    $expected = [
        'SP-00012' => ['rejected', 'critical'], 'SP-00027' => ['rejected', 'critical'], 'SP-00031' => ['rejected', 'critical'],
        'SP-00036' => ['paid', 'low'], 'SP-00038' => ['awaiting_approval', 'low'], 'SP-00039' => ['awaiting_approval', 'medium'],
        'SP-00040' => ['awaiting_verification', 'critical'], 'SP-00041' => ['awaiting_verification', 'critical'], 'SP-00042' => ['paid', 'high'],
        'SP-00043' => ['paid', 'low'], 'SP-00044' => ['paid', 'low'], 'SP-00045' => ['rejected', 'medium'], 'SP-00046' => ['review', 'low'],
        'SP-00047' => ['review', 'high'], 'SP-00048' => ['expired', 'low'], 'SP-00049' => ['cancelled', 'low'],
    ];

    foreach ($expected as $reference => [$status, $level]) {
        $request = seededRequest($northwind, $reference);
        expect([$reference, $request->getAttribute('status')->value, $request->getAttribute('risk_level')->value])->toBe([$reference, $status, $level]);
    }

    expect(seededSignals(seededRequest($northwind, 'SP-00031')))->toContain(
        'BANK_DETAILS_CHANGED', 'BANK_COUNTRY_CHANGED', 'SENDER_DOMAIN_LOOKALIKE', 'REPLY_TO_MISMATCH', 'FREE_EMAIL_PROVIDER',
        'DOMAIN_RECENTLY_REGISTERED', 'URGENCY_LANGUAGE', 'CHANGE_REQUEST_LANGUAGE', 'CALLBACK_DISCOURAGED', 'PHONE_NOT_IN_MASTER', 'DUE_DATE_PRESSURE',
    )
        ->and(seededSignals(seededRequest($northwind, 'SP-00040')))->toContain('DISPLAY_NAME_IMPERSONATION', 'FREE_EMAIL_PROVIDER', 'SENDER_DOMAIN_UNKNOWN_FOR_VENDOR', 'BANK_DETAILS_CHANGED', 'SECRECY_LANGUAGE', 'AUTHORITY_PRESSURE')
        ->and(seededSignals(seededRequest($northwind, 'SP-00041')))->toContain('EMPLOYEE_DENIED_CHANGE', 'PAYROLL_ACCOUNT_CHANGE')
        ->and(seededSignals(seededRequest($northwind, 'SP-00047')))->toContain('QR_PAYEE_MISMATCH')
        ->and(seededSignals(seededRequest($northwind, 'SP-00039')))->toEqualCanonicalizing(['NEW_VENDOR', 'BANK_ACCOUNT_FIRST_USE'])
        ->and(seededSignals(seededRequest($northwind, 'SP-00045')))->toContain('DUPLICATE_INVOICE')
        ->and(seededRequest($northwind, 'SP-00039')->getAttribute('risk_score'))->toBe(30)
        ->and(seededRequest($northwind, 'SP-00038')->getAttribute('amount_base_minor'))->toBe(1437625);

    $fraud = seededRequest($northwind, 'SP-00031');
    expect($fraud->getAttribute('prevented_category'))->toBe(PreventedCategory::Fraud)
        ->and($fraud->getAttribute('prevented_amount_base_minor'))->toBe(1840000)
        ->and(seededRequest($northwind, 'SP-00045')->getAttribute('prevented_category'))->toBe(PreventedCategory::Duplicate)
        ->and(DB::table('blocked_bank_accounts')->where('workspace_id', $northwind->id)->pluck('last4')->all())->toContain('8839', '2211');

    $northstar = VendorBankAccount::query()->forWorkspace($northwind)->get()->keyBy('last4');
    expect($northstar['8830']->getAttribute('status'))->toBe(BankAccountStatus::Verified)
        ->and($northstar['5512']->getAttribute('status'))->toBe(BankAccountStatus::Retired);

    $pending38 = DB::table('approvals')->where('payment_request_id', seededRequest($northwind, 'SP-00038')->getKey())->where('status', 'pending')->pluck('approver_id')->all();
    expect($pending38)->toBe([$user('demo')->id])
        ->and(DB::table('approvals')->where('payment_request_id', seededRequest($northwind, 'SP-00043')->getKey())->where('status', 'approved')->value('channel'))->toBe('telegram');

    foreach ([$northwind, $kedr] as $workspace) {
        expect(app(EvidenceLedger::class)->verify($workspace->id)['ok'])->toBeTrue();
        $decreasing = DB::selectOne('SELECT count(*) AS c FROM (SELECT occurred_at < lag(occurred_at) OVER (ORDER BY seq) AS d FROM evidence_entries WHERE workspace_id = ?) x WHERE d', [$workspace->id]);
        expect((int) $decreasing->c)->toBe(0);
    }

    $sod = DB::table('verifications as v')
        ->join('payment_requests as p', 'p.id', '=', 'v.payment_request_id')
        ->where(fn ($query) => $query->whereColumn('v.performed_by', 'p.requested_by')->orWhereColumn('v.performed_by', 'p.submitted_by'))
        ->count();
    expect($sod)->toBe(0);

    $topics = OutboxMessage::query()->pluck('topic')->unique()->values()->all();
    expect($topics)->toEqualCanonicalizing(['document.uploaded', 'evidence.packet.requested'])
        ->and(OutboxMessage::query()->where('topic', 'evidence.packet.requested')->count())->toBe(2)
        ->and(DB::table('sp_notifications')->where('status', 'queued')->count())->toBe(0);

    $now = CarbonImmutable::now()->setTimezone('America/New_York');

    for ($week = 1; $week <= 11; $week++) {
        $start = $now->startOfWeek()->subWeeks($week);
        $count = PaymentRequest::query()->forWorkspace($northwind)->whereBetween('created_at', [$start->utc(), $start->addWeek()->utc()])->count();
        expect($count)->toBeGreaterThanOrEqual(2);
    }

    expect(DB::table('notifications')->where('notifiable_id', $user('demo')->id)->whereNull('read_at')->whereRaw("(data->>'workspace_id')::bigint = ?", [$northwind->id])->count())->toBe(6)
        ->and(Document::query()->where('workspace_id', $northwind->id)->count())->toBe(12)
        ->and(Document::query()->where('workspace_id', $kedr->id)->count())->toBe(4)
        ->and(PaymentRequest::query()->forWorkspace($northwind)->count())->toBe(49)
        ->and(PaymentRequest::query()->forWorkspace($kedr)->count())->toBe(16)
        ->and(seededRequest($kedr, 'SP-00011')->getAttribute('status'))->toBe(PaymentRequestStatus::Review)
        ->and(seededSignals(seededRequest($kedr, 'SP-00011')))->toContain('QR_PAYEE_MISMATCH');

    $titles = PaymentRequest::query()->pluck('title')->implode(' ');
    expect(preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $titles))->toBe(0);

    $this->seed(DomainSeeder::class);
    expect(PaymentRequest::query()->count())->toBe(65)
        ->and(Document::query()->count())->toBe(16);
});

it('keeps the seed policy document in line with the seeded approval rules', function (): void {
    $policy = collect(KnowledgeSeeder::documents())->firstWhere('file', 'payment-approval-policy.en.md');
    $rules = NorthwindCatalog::rules();

    expect($policy)->not->toBeNull()
        ->and($policy['body'])->toContain('$3,000', '$10,000', '48 hours')
        ->and($rules[1]['min'] ?? null)->toBe(300000)
        ->and($rules[2]['min'] ?? null)->toBe(1000000)
        ->and($rules[2]['required_roles'] ?? [])->toBe(['owner'])
        ->and(collect(KnowledgeSeeder::documents())->filter(fn (array $doc): bool => in_array('kedr', $doc['workspaces'], true))->count())->toBe(4)
        ->and(count(KnowledgeSeeder::documents()))->toBe(12);
});

it('maps seed document front matter to knowledge metadata', function (): void {
    [$front, $body] = KnowledgeSeeder::split("---\ntitle: \"Policy\"\nroles: [\"owner\", \"admin\"]\nversion: \"2.1\"\neffective_date: 2026-09-01\n---\n\n# Body\n");

    expect($front)->toBe(['title' => 'Policy', 'roles' => ['owner', 'admin'], 'version' => '2.1', 'effective_date' => '2026-09-01'])
        ->and(trim($body))->toBe('# Body');
});

it('skips the demo seed when it is switched off', function (): void {
    config(['scampause.demo_seed' => false]);
    $this->seed(DomainSeeder::class);

    expect(Workspace::query()->where('slug', 'kedr')->exists())->toBeFalse()
        ->and(ApprovalRule::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses invalid demo workspace slugs', function (): void {
    $this->artisan('scampause:demo-workspace', ['slug' => 'Bad Slug'])->assertExitCode(2);

    expect(Workspace::query()->count())->toBe(0)
        ->and(class_exists(DemoWorkspaceSeeder::class))->toBeTrue();
});
