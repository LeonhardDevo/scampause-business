<?php

declare(strict_types=1);

namespace Database\Seeders\Domain;

use App\Domain\Approvals\Enums\DecisionChannel;
use App\Domain\Intake\Data\ManualEntry;
use App\Domain\Intake\Enums\CancelReason;
use App\Domain\Intake\Enums\DecisionReason;
use App\Domain\Intake\Enums\EmployeeResponse;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Policy\PolicyService;
use App\Domain\Vendors\Enums\BankScheme;
use App\Domain\Vendors\Enums\ContactSource;
use App\Domain\Verification\Enums\VerificationMethod;
use App\Domain\Verification\Enums\VerificationOutcome;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Seeders\Domain\Support\DemoDocuments;
use Database\Seeders\Domain\Support\DemoWorkspace;
use Database\Seeders\Domain\Support\InvoiceFixture;
use Database\Seeders\Domain\Support\OutboxSuppression;
use Database\Seeders\Domain\Support\WorkspaceSetup;
use Illuminate\Database\Seeder;

final class DemoWorkspaceSeeder extends Seeder
{
    public string $slug = 'northwind';

    public string $name = 'Northwind Coffee Roasters';

    public ?CarbonImmutable $now = null;

    public ?Workspace $workspace = null;

    public function run(): void
    {
        $now = $this->now ?? CarbonImmutable::now()->startOfMinute();
        $configured = config('scampause.demo_workspace_slug');
        $slug = is_string($configured) && $configured !== '' ? $configured : $this->slug;
        $users = DemoUsersSeeder::ensure($now->subDays(130));
        $this->workspace = self::seed($slug, $this->name, $users, $now);
    }

    /**
     * @param  array<string, User>  $users
     */
    public static function seed(string $slug, string $name, array $users, CarbonImmutable $now): Workspace
    {
        $workspace = WorkspaceSetup::workspace($slug, ['name' => $name, 'timezone' => 'America/New_York', 'currency' => 'USD', 'locale' => 'en'], $users['alex'], $now->subDays(125));

        if (WorkspaceSetup::hasDomainData($workspace)) {
            return $workspace;
        }

        WorkspaceSetup::members($workspace, [
            'alex' => WorkspaceRole::Owner,
            'maria' => WorkspaceRole::Admin,
            'sam' => WorkspaceRole::Approver,
            'irina' => WorkspaceRole::Bookkeeper,
            'jordan' => WorkspaceRole::Member,
            'pat' => WorkspaceRole::Auditor,
        ], $users, [$users['admin']], $now->subDays(125));
        WorkspaceSetup::subscription($workspace, 'pro', $now->addDays(18));
        WorkspaceSetup::resetLedger($workspace);
        WorkspaceSetup::rates($workspace, NorthwindCatalog::rates(), $now->subDays(130), $now->subDay(), $users['maria']);

        $demo = new DemoWorkspace($workspace, $users, $now);
        self::masterData($demo);
        DemoHistorySeeder::schedule($demo);
        self::showcase($demo);
        OutboxSuppression::run(DomainSeeder::SUPPRESSED_TOPICS, fn () => $demo->run());
        DemoFinisher::finish($demo, [31, 42], [
            [40, 'risk_detected'],
            [41, 'employee_responded'],
            [38, 'approval_requested'],
            [48, 'request_expired'],
            [48, 'approval_escalation'],
        ], 'alex');

        return $workspace->refresh();
    }

    private static function masterData(DemoWorkspace $demo): void
    {
        $t0 = $demo->day(120, '10:00');

        $demo->at($t0, 'settings', function () use ($demo): void {
            app(PolicyService::class)->updateSettings($demo->workspace, $demo->user('alex'), [
                'own_domains' => ['northwindroasters.com'],
                'business_days' => [1, 2, 3, 4, 5],
                'business_hours_start' => '09:00',
                'business_hours_end' => '18:00',
                'quiet_hours_start' => '21:00',
                'quiet_hours_end' => '08:00',
                'default_expiry_hours' => 48,
                'reminder_offsets_hours' => [4, 24],
                'escalate_after_hours' => 24,
                'telegram_approvals_enabled' => true,
                'telegram_approve_max_risk' => 'medium',
                'verification_amount_threshold_minor' => 100000,
                'verification_valid_days' => 7,
                'country' => 'US',
                'callback_cooldown_days' => 7,
            ]);

            foreach (NorthwindCatalog::rules() as $rule) {
                app(PolicyService::class)->createRule($demo->workspace, $demo->user('alex'), self::rule($rule));
            }

            app(PolicyService::class)->setLimits($demo->workspace, $demo->user('alex'), $demo->user('sam'), 2500000, 6000000);
        });

        foreach (NorthwindCatalog::vendors() as $key => $spec) {
            $demo->at($t0->addMinutes(30), 'vendor '.$key, function () use ($demo, $key, $spec): void {
                $attributes = $spec['vendor'];

                if (isset($attributes['linked_user'])) {
                    $attributes['linked_user_id'] = $demo->user((string) $attributes['linked_user'])->id;
                    unset($attributes['linked_user']);
                }

                $demo->vendor($key, $spec['creator'], $attributes, $spec['domains']);
                $demo->contact($key, $key, $spec['creator'], $spec['contact']);

                foreach ($spec['accounts'] as $account) {
                    $data = $account;
                    unset($data['key']);
                    $demo->account((string) $account['key'], $key, 'irina', $data);
                }
            });

            $demo->at($t0->addDay(), 'contact verified '.$key, fn () => $demo->verifyContact($key, 'alex'));

            $demo->at($t0->addDay()->addHour(), 'account verified '.$key, function () use ($demo, $key, $spec): void {
                foreach ($spec['accounts'] as $account) {
                    $demo->verifyAccount((string) $account['key'], 'maria', $key, null, $key === 'jordan' ? VerificationMethod::InPerson : VerificationMethod::CallbackKnownNumber);
                }
            });
        }

        $demo->at($demo->day(20, '10:15'), 'vendor pixel', function () use ($demo): void {
            $demo->vendor('pixel', 'irina', ['kind' => 'contractor', 'name' => 'Pixel & Pour Design Studio', 'legal_name' => 'Pixel and Pour Design Studio LLC', 'country' => 'US', 'default_currency' => 'USD', 'payment_terms_days' => 14], ['pixelandpour.studio']);
            $demo->contact('pixel', 'pixel', 'irina', ['name' => 'Priya Nair', 'role_title' => 'Owner', 'phone' => '+1 646 555 0175', 'source' => ContactSource::OnboardingForm, 'source_ref' => 'Vendor onboarding form signed 2026-10-02']);
        });
        $demo->at($demo->day(9, '10:00'), 'contact verified pixel', fn () => $demo->verifyContact('pixel', 'alex'));
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>
     */
    public static function rule(array $rule): array
    {
        return [
            'name' => $rule['name'],
            'is_active' => true,
            'min_amount_base_minor' => $rule['min'] ?? null,
            'kinds' => $rule['kinds'] ?? null,
            'min_risk_level' => $rule['min_risk_level'] ?? null,
            'applies_to_bank_change' => $rule['applies_to_bank_change'] ?? null,
            'approvals_required' => $rule['approvals_required'],
            'required_roles' => $rule['required_roles'] ?? [],
            'approver_roles' => ['owner', 'admin', 'approver'],
            'verification_required' => $rule['verification_required'] ?? false,
        ];
    }

    private static function showcase(DemoWorkspace $demo): void
    {
        $catalog = NorthwindCatalog::vendors();

        $at = $demo->day(63, '11:20');
        $demo->at($at, 'SP-00012 intake', fn () => $demo->upload(12, 'irina', [self::eml('andesgreen-coffee-new-bank-details.eml', 'Lucia Ortega', 'cobros@andesgreen-coffee.com', 'New bank details / Nuevos datos bancarios', 'Please note our new bank details for all future invoices, effective immediately.', $at)], $demo->fixture('andes-bank-change-12', $at), PaymentRequestKind::BankDetailsChange, 'New bank details: Andes Green Coffee', [self::intel('andesgreen-coffee.com', $at->subDays(9)), self::intel('andesgreencoffee.com', CarbonImmutable::parse('2014-03-02'))]));
        $demo->at($at->addMinutes(15), 'SP-00012 submit', fn () => $demo->submit(12, 'irina'));
        $demo->at($at->addHours(2), 'SP-00012 callback denied', fn () => $demo->verify(12, 'maria', 'andes', VerificationOutcome::Denied, [], DecisionReason::FraudConfirmed));

        $at = $demo->day(40, '09:40');
        $demo->at($at, 'SP-00027 intake', fn () => $demo->upload(27, 'irina', [self::eml('harbor-updated-bank-details.eml', 'Tom Becker', 'accounts@harbor-lg.com', 'Updated bank details - action required', 'Due to an audit of our US account, please send all payments to our new bank details.', $at)], $demo->fixture('harbor-bank-change-27', $at), PaymentRequestKind::BankDetailsChange, 'Updated bank details: Harbor Logistics', [self::intel('harbor-lg.com', $at->subDays(6)), self::intel('harborlg.com', CarbonImmutable::parse('2011-07-19'))]));
        $demo->at($at->addMinutes(12), 'SP-00027 submit', fn () => $demo->submit(27, 'irina'));
        $demo->at($at->addHours(1)->addMinutes(30), 'SP-00027 callback denied', fn () => $demo->verify(27, 'maria', 'harbor', VerificationOutcome::Denied, [], DecisionReason::FraudConfirmed));

        $at = $demo->day(21, '10:05');
        $demo->at($at, 'SP-00036 intake', function () use ($demo, $catalog, $at): void {
            $local = $at->setTimezone($demo->timezone())->startOfDay();
            $fixture = InvoiceFixture::build('Summit Office Supply', null, 'SO-55120', 64218, 'USD', $local, $local->addDays(30), $catalog['summit']['accounts'][0], 'Office and packing supplies');
            $demo->upload(36, 'irina', $fixture['files'], $fixture['extraction']);
        });
        $demo->at($at->addMinutes(10), 'SP-00036 submit', fn () => $demo->submit(36, 'irina'));
        $demo->at($at->addHours(2), 'SP-00036 approve', fn () => $demo->approveAll(36));
        $demo->at($demo->day(20, '11:00'), 'SP-00036 paid', fn () => $demo->pay(36, 'irina', 'ACH 0936-SOS'));

        $at = $demo->day(16, '10:30');
        $demo->at($at, 'SP-00042 intake', fn () => $demo->upload(42, 'irina', [self::pdf('northstar-letter.pdf', 'Northstar Equipment Leasing - Notice of updated remittance details', ['Lease NEL-2023-118', 'Please update our records: lease payments are collected by our new bank.', 'Monthly lease installment: USD 3,960.00', 'Bank: BMO Bank N.A.', 'Routing: 322271627', 'Account: 9100458830', 'Greg Holloway, Controller'])], $demo->fixture('northstar-letter', $at), PaymentRequestKind::BankDetailsChange, 'Northstar: updated remittance details'));
        $demo->at($at->addMinutes(10), 'SP-00042 submit', fn () => $demo->submit(42, 'irina'));
        $demo->at($demo->day(15, '09:30'), 'SP-00042 callback confirmed', function () use ($demo): void {
            $demo->verify(42, 'maria', 'northstar', VerificationOutcome::Confirmed, replaces: 'northstar_5512');
            $demo->assignTo(42, 1, 'alex', 'maria');
            $demo->assignTo(42, 2, 'sam', 'maria');
        });
        $demo->at($demo->day(15, '11:00'), 'SP-00042 Sam approves', fn () => $demo->approve(42, 'sam'));
        $demo->at($demo->day(15, '14:00'), 'SP-00042 Alex approves', fn () => $demo->approve(42, 'alex'));
        $demo->at($demo->day(14, '10:00'), 'SP-00042 paid', fn () => $demo->pay(42, 'irina', 'ACH 0914-NSL'));

        $at = $demo->day(10, '09:15');
        $demo->at($at, 'SP-00044 manual', function () use ($demo, $at): void {
            $local = $at->setTimezone($demo->timezone())->startOfDay();
            $demo->manual(44, 'irina', new ManualEntry(
                provided: ['title', 'kind', 'vendor_id', 'amount', 'currency', 'invoice_number', 'invoice_date', 'due_date', 'payee'],
                title: 'Metro Power & Gas - utility bill',
                kind: PaymentRequestKind::Invoice,
                vendorId: (int) $demo->vendors['metro']->getKey(),
                amountMinor: 118240,
                currency: 'USD',
                invoiceNumber: 'MPG-779411',
                invoiceDate: $local->subDays(3),
                dueDate: $local->addDays(14),
                payeeScheme: BankScheme::UsAch,
                payeeAccountNumber: '6011033020',
                payeeRoutingCode: '121000248',
                payeeAccountHolder: 'Metro Power & Gas Company',
                payeeBankName: 'Wells Fargo Bank, N.A.',
                payeeBankCountry: 'US',
            ));
        });
        $demo->at($at->addMinutes(5), 'SP-00044 submit', fn () => $demo->submit(44, 'irina'));
        $demo->at($at->addHours(3), 'SP-00044 approve', fn () => $demo->approveAll(44));
        $demo->at($demo->day(9, '10:40'), 'SP-00044 paid', fn () => $demo->pay(44, 'irina', 'ACH 1009-MPG'));

        $at = $demo->day(10, '14:00');
        $demo->at($at, 'SP-00045 intake', function () use ($demo, $catalog, $at): void {
            $local = $at->setTimezone($demo->timezone())->startOfDay();
            $fixture = InvoiceFixture::build('Summit Office Supply', null, 'SO-55120', 64218, 'USD', $local->subDays(11), $local->addDays(19), $catalog['summit']['accounts'][0], 'Office and packing supplies');
            $demo->upload(45, 'irina', $fixture['files'], $fixture['extraction']);
        });
        $demo->at($demo->day(9, '10:00'), 'SP-00045 rejected duplicate', fn () => $demo->rejectFromReview(45, 'maria', DecisionReason::Duplicate));

        $at = $demo->day(6, '14:10');
        $demo->at($at, 'SP-00031 intake', fn () => $demo->upload(31, 'irina', [self::eml('blue-ridge-bank-change.eml', 'Karen Whitfield', 'billing@blueridge-pack.com', 'Urgent: new bank details for invoice BRP-88317', 'Please note that we have new bank details effective immediately.', $at, 'karen.whitfield.ar@gmail.com')], $demo->fixture('blue-ridge-bank-change', $at), PaymentRequestKind::BankDetailsChange, 'Blue Ridge: new bank details for invoice BRP-88317', [self::intel('blueridge-pack.com', $at->subDays(9)), self::intel('blueridgepack.com', CarbonImmutable::parse('2009-05-14'))]));
        $demo->at($at->addMinutes(10), 'SP-00031 submit', fn () => $demo->submit(31, 'irina'));
        $demo->at($at->addHours(2), 'SP-00031 callback denied', fn () => $demo->verify(31, 'maria', 'blue_ridge', VerificationOutcome::Denied, [], DecisionReason::FraudConfirmed));

        $at = $demo->day(4, '10:00');
        $demo->at($at, 'SP-00043 intake', function () use ($demo, $catalog, $at): void {
            $local = $at->setTimezone($demo->timezone())->startOfDay();
            $fixture = InvoiceFixture::build('CleanSweep Facility Services LLC', null, 'CS-2461', 145000, 'USD', $local->subDay(), $local->addDays(15), $catalog['cleansweep']['accounts'][0], 'Monthly roastery and cafe cleaning service');
            $demo->upload(43, 'irina', $fixture['files'], $fixture['extraction']);
        });
        $demo->at($at->addMinutes(8), 'SP-00043 submit', function () use ($demo): void {
            $demo->submit(43, 'irina');
            $demo->assignTo(43, 1, 'sam', 'maria');
        });
        $demo->at($demo->day(4, '15:00'), 'SP-00043 Sam approves in Telegram', fn () => $demo->approve(43, 'sam', DecisionChannel::Telegram));
        $demo->at($demo->day(3, '11:00'), 'SP-00043 paid', fn () => $demo->pay(43, 'irina', 'ACH 1020-CSF'));

        $at = $demo->day(3, '10:30');
        $demo->at($at, 'SP-00048 intake', function () use ($demo, $catalog, $at): void {
            $local = $at->setTimezone($demo->timezone())->startOfDay();
            $fixture = InvoiceFixture::build('Harbor Logistics Group', null, 'HL-7781', 187500, 'USD', $local->subDay(), $local->addDays(15), $catalog['harbor']['accounts'][0], 'Inbound freight, container delivery');
            $demo->upload(48, 'irina', $fixture['files'], $fixture['extraction']);
        });
        $demo->at($at->addMinutes(10), 'SP-00048 submit', function () use ($demo): void {
            $demo->submit(48, 'irina');
            $demo->assignTo(48, 1, 'sam', 'maria');
        });
        $demo->at($at->addMinutes(10)->addHours(4), 'SP-00048 reminder 1', fn () => $demo->remind(48, 1, 2));
        $demo->at($demo->day(2, '10:45'), 'SP-00048 reminder 2', fn () => $demo->remind(48, 2, 2));
        $demo->at($demo->day(2, '10:50'), 'SP-00048 escalation', fn () => $demo->escalate(48));
        $demo->at($demo->day(1, '10:40'), 'SP-00048 expired', fn () => $demo->expire(48));

        $at = $demo->day(2, '09:50');
        $demo->at($at, 'SP-00049 intake', function () use ($demo, $catalog, $at): void {
            $local = $at->setTimezone($demo->timezone())->startOfDay();
            $fixture = InvoiceFixture::build('CleanSweep Facility Services LLC', null, 'CS-2468', 145000, 'USD', $local, $local->addDays(15), $catalog['cleansweep']['accounts'][0], 'Monthly roastery and cafe cleaning service');
            $demo->upload(49, 'irina', $fixture['files'], $fixture['extraction']);
        });
        $demo->at($at->addMinutes(15), 'SP-00049 cancelled', fn () => $demo->cancel(49, 'irina', CancelReason::DuplicateUpload));

        $at = $demo->now->subHours(26);
        $demo->at($at, 'SP-00039 intake', fn () => $demo->upload(39, 'irina', [self::pdf('pixel-pour-pp-001.pdf', 'Pixel & Pour Design Studio - Invoice PP-001', ['Packaging redesign, seasonal label set (12 SKUs)', 'Total: USD 4,000.00', 'Bank: Citibank, N.A.', 'Routing: 021000089', 'Account: 7300112245'])], $demo->fixture('pixel-pour-pp-001', $at)));
        $demo->at($at->addMinutes(10), 'SP-00039 submit', fn () => $demo->submit(39, 'irina'));
        $demo->at($demo->now->subHours(22), 'SP-00039 callback confirmed', function () use ($demo): void {
            $demo->verify(39, 'maria', 'pixel', VerificationOutcome::Confirmed, ['account_last4' => true, 'amount' => true, 'account_holder' => true, 'invoice_number' => true]);
            $demo->assignTo(39, 1, 'sam', 'alex');
            $demo->assignTo(39, 2, 'maria', 'alex');
        });

        $at = $demo->now->subHours(6);
        $demo->at($at, 'SP-00038 intake', fn () => $demo->upload(38, 'irina', [self::pdf('andes-ag-2026-0917.pdf', 'Andes Green Coffee Importers S.L. - Invoice AG-2026-0917', ['CIF B12345678', 'Green coffee, Colombia Huila Excelso, 4 bags x 69 kg', 'Total due: EUR 13,250.00', 'IBAN: ES79 2100 0813 6101 2345 6789', 'BIC: CAIXESBBXXX'])], $demo->fixture('andes-ag-2026-0917', $at)));
        $demo->at($at->addMinutes(10), 'SP-00038 submit', function () use ($demo): void {
            $demo->submit(38, 'irina');
            $demo->assignTo(38, 2, 'maria', 'alex');
        });
        $demo->at($demo->now->subHours(5), 'SP-00038 Maria approves', fn () => $demo->approve(38, 'maria'));

        $at = $demo->now->subHours(3);
        $demo->at($at, 'SP-00041 intake', fn () => $demo->upload(41, 'maria', [self::eml('jordan-direct-deposit.eml', 'Jordan Lee', 'jordan.lee.payroll@gmail.com', 'Direct deposit change', 'Please update my direct deposit to the new account before the next payroll run.', $at)], $demo->fixture('jordan-direct-deposit', $at), PaymentRequestKind::PayrollChange, 'Direct deposit change: Jordan Lee'));
        $demo->at($at->addMinutes(10), 'SP-00041 review', fn () => $demo->review(41, 'maria', new ManualEntry(provided: ['amount', 'currency'], amountMinor: 215000, currency: 'USD')));
        $demo->at($at->addMinutes(15), 'SP-00041 submit', fn () => $demo->submit(41, 'maria'));
        $demo->at($demo->now->subMinutes(50), 'SP-00041 employee denies', fn () => $demo->employeeAnswer(41, EmployeeResponse::NotMe, 'jordan'));

        $at = $demo->now->subHours(2);
        $demo->at($at, 'SP-00040 intake', fn () => $demo->upload(40, 'jordan', [self::eml('ceo-urgent-wire.eml', 'Alex Morgan', 'alex.morgan.office@outlook.com', 'Urgent wire today', 'I need you to process a wire to Harbor Logistics today.', $at)], $demo->fixture('ceo-urgent-wire', $at), PaymentRequestKind::UrgentWire, 'Urgent wire: Harbor Logistics'));
        $demo->at($demo->now->subHour(), 'SP-00040 submit', fn () => $demo->submit(40, 'maria'));
        $demo->at($demo->now->subMinutes(30), 'SP-00040 callback unreachable', fn () => $demo->verify(40, 'alex', 'harbor', VerificationOutcome::Unreachable, [], minutesBefore: 1));

        $at = $demo->now->subMinutes(35);
        $demo->at($at, 'SP-00047 intake', fn () => $demo->upload(47, 'irina', [self::pdf('harbor-hl-7790-qr.pdf', 'Harbor Logistics Group - Invoice HL-7790', ['Freight, container NWRU 4471223', 'Total: USD 2,340.00', 'Pay by ACH: Valley National Bank, routing 031101279, account 5501206604', 'Or scan the payment QR code below.'])], $demo->fixture('harbor-hl-7790-qr', $at), PaymentRequestKind::QrPayment));

        $at = $demo->now->subMinutes(20);
        $demo->at($at, 'SP-00046 intake', fn () => $demo->upload(46, 'irina', [self::pdf('blue-ridge-inv-88390.pdf', 'Blue Ridge Packaging Company, Inc. - Invoice 88390', ['EIN 56-1234567', 'Kraft valve bags 1 lb, 6,000 units', 'Total: USD 7,215.00', 'Net 30', 'ACH: JPMorgan Chase Bank, N.A.', 'Routing: 021000021', 'Account: 000483924417'])], $demo->fixture('blue-ridge-inv-88390', $at)));
    }

    /**
     * @return array{bytes: string, name: string, mime: string}
     */
    public static function eml(string $file, string $fromName, string $fromEmail, string $subject, string $body, CarbonImmutable $at, ?string $replyTo = null, string $to = 'ap@northwindroasters.com'): array
    {
        return [
            'bytes' => DemoDocuments::eml([
                'from_name' => $fromName,
                'from_email' => $fromEmail,
                'to' => $to,
                'subject' => $subject,
                'body' => $body,
                'reply_to' => $replyTo,
                'sent_at' => $at->subMinutes(25),
                'message_id' => substr(hash('sha256', $file.$fromEmail), 0, 24).'@'.(explode('@', $fromEmail)[1] ?? 'mail.test'),
            ]),
            'name' => $file,
            'mime' => 'message/rfc822',
        ];
    }

    /**
     * @param  list<string>  $lines
     * @return array{bytes: string, name: string, mime: string}
     */
    public static function pdf(string $file, string $title, array $lines): array
    {
        return ['bytes' => DemoDocuments::pdf($title, $lines), 'name' => $file, 'mime' => 'application/pdf'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function intel(string $domain, CarbonImmutable $registered): array
    {
        return [
            'domain' => $domain,
            'has_mx' => true,
            'has_spf' => true,
            'dmarc_policy' => $registered->greaterThan(CarbonImmutable::now()->subYear()) ? 'absent' : 'reject',
            'registered_at' => $registered->toDateString(),
            'registrar' => $registered->greaterThan(CarbonImmutable::now()->subYear()) ? 'NameSilo, LLC' : 'GoDaddy.com, LLC',
            'rdap_status' => 'ok',
            'checked_at' => CarbonImmutable::now()->toIso8601ZuluString(),
        ];
    }
}
