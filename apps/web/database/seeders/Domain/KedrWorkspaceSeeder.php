<?php

declare(strict_types=1);

namespace Database\Seeders\Domain;

use App\Domain\Intake\Data\ManualEntry;
use App\Domain\Intake\Enums\DecisionReason;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Policy\PolicyService;
use App\Domain\Vendors\Enums\BankScheme;
use App\Domain\Vendors\Enums\ContactSource;
use App\Domain\Vendors\Enums\VendorKind;
use App\Domain\Verification\Enums\VerificationMethod;
use App\Domain\Verification\Enums\VerificationOutcome;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Seeders\Domain\Support\DemoWorkspace;
use Database\Seeders\Domain\Support\OutboxSuppression;
use Database\Seeders\Domain\Support\WorkspaceSetup;
use Illuminate\Database\Seeder;

final class KedrWorkspaceSeeder extends Seeder
{
    public const array VENDORS = [
        'stroysever' => [
            'vendor' => ['kind' => VendorKind::Supplier, 'name' => 'ООО «Стройматериалы Север»', 'legal_name' => 'Общество с ограниченной ответственностью «Стройматериалы Север»', 'tax_id' => '7701543216', 'country' => 'RU', 'default_currency' => 'RUB', 'payment_terms_days' => 10],
            'domains' => ['stroysever.ru'],
            'contact' => ['name' => 'Анна Лебедева', 'role_title' => 'Главный бухгалтер', 'phone' => '+7 495 555-01-42', 'source' => ContactSource::Contract, 'source_ref' => 'Договор поставки № 14/2025, п. 9.2'],
            'account' => ['scheme' => BankScheme::RuAccount, 'routing_code' => '044525225', 'account_number' => '40702810938000012345', 'bank_name' => 'ПАО Сбербанк', 'bank_country' => 'RU', 'account_holder' => 'ООО «Стройматериалы Север»', 'currency' => 'RUB'],
        ],
        'teplo' => [
            'vendor' => ['kind' => VendorKind::Contractor, 'name' => 'ООО «ТеплоСервис»', 'legal_name' => 'Общество с ограниченной ответственностью «ТеплоСервис»', 'tax_id' => '7714987650', 'country' => 'RU', 'default_currency' => 'RUB', 'payment_terms_days' => 10],
            'domains' => ['teploservis.ru'],
            'contact' => ['name' => 'Игорь Петров', 'role_title' => 'Финансовый менеджер', 'phone' => '+7 495 555-02-17', 'source' => ContactSource::Contract, 'source_ref' => 'Договор обслуживания № ТС-08/2024, п. 6.3'],
            'account' => ['scheme' => BankScheme::RuAccount, 'routing_code' => '044525974', 'account_number' => '40702810610000654321', 'bank_name' => 'АО «Тинькофф Банк»', 'bank_country' => 'RU', 'account_holder' => 'ООО «ТеплоСервис»', 'currency' => 'RUB'],
        ],
        'gorseti' => [
            'vendor' => ['kind' => VendorKind::Supplier, 'name' => 'АО «Городские сети»', 'legal_name' => 'Акционерное общество «Городские сети»', 'tax_id' => '7805123456', 'country' => 'RU', 'default_currency' => 'RUB', 'payment_terms_days' => 15],
            'domains' => ['gorseti.ru'],
            'contact' => ['name' => 'Контакт-центр', 'role_title' => null, 'phone' => '+7 812 555-03-30', 'source' => ContactSource::WebsiteVerified, 'source_ref' => 'gorseti.ru, раздел «Контакты»'],
            'account' => ['scheme' => BankScheme::RuAccount, 'routing_code' => '044030653', 'account_number' => '40702810155000098765', 'bank_name' => 'ПАО Сбербанк, Северо-Западный банк', 'bank_country' => 'RU', 'account_holder' => 'АО «Городские сети»', 'currency' => 'RUB'],
        ],
        'kovalev' => [
            'vendor' => ['kind' => VendorKind::Contractor, 'name' => 'ИП Ковалев Андрей Сергеевич', 'legal_name' => 'Индивидуальный предприниматель Ковалев Андрей Сергеевич', 'tax_id' => '771234567859', 'country' => 'RU', 'default_currency' => 'RUB', 'payment_terms_days' => 5],
            'domains' => [],
            'contact' => ['name' => 'Андрей Ковалев', 'role_title' => null, 'phone' => '+7 916 555-04-48', 'source' => ContactSource::InPerson, 'source_ref' => 'Встреча в офисе, подтверждено лично'],
            'account' => ['scheme' => BankScheme::RuAccount, 'routing_code' => '044525593', 'account_number' => '40702810801300017777', 'bank_name' => 'АО «Альфа-Банк»', 'bank_country' => 'RU', 'account_holder' => 'ИП Ковалев Андрей Сергеевич', 'currency' => 'RUB'],
        ],
        'vasiliev' => [
            'vendor' => ['kind' => VendorKind::Employee, 'name' => 'Сергей Васильев', 'legal_name' => 'Васильев Сергей Николаевич', 'country' => 'RU', 'default_currency' => 'RUB'],
            'domains' => [],
            'contact' => ['name' => 'Сергей Васильев', 'role_title' => 'Прораб', 'phone' => '+7 916 555-05-12', 'source' => ContactSource::InPerson, 'source_ref' => 'Личное дело'],
            'account' => ['scheme' => BankScheme::RuAccount, 'routing_code' => '044525225', 'account_number' => '40817810938001234567', 'bank_name' => 'ПАО Сбербанк', 'bank_country' => 'RU', 'account_holder' => 'Васильев Сергей Николаевич', 'currency' => 'RUB'],
        ],
    ];

    public const array RULES = [
        ['name' => 'Один согласующий по умолчанию', 'approvals_required' => 1],
        ['name' => 'Двойной контроль от 300 000 ₽', 'min' => 30000000, 'approvals_required' => 2],
        ['name' => 'Подпись владельца от 1 000 000 ₽', 'min' => 100000000, 'approvals_required' => 2, 'required_roles' => ['owner']],
        ['name' => 'Смена реквизитов: звонок + два согласующих', 'applies_to_bank_change' => true, 'verification_required' => true, 'approvals_required' => 2, 'required_roles' => ['admin']],
        ['name' => 'Зарплатные изменения: звонок + владелец', 'kinds' => ['payroll_change'], 'verification_required' => true, 'approvals_required' => 2, 'required_roles' => ['owner']],
    ];

    public const array HISTORY = [
        1 => ['stroysever', 60, 21450000, 'Счёт № 431 — цемент и сухие смеси'],
        2 => ['gorseti', 56, 1795320, 'Городские сети — июль'],
        3 => ['teplo', 52, 5800000, 'ТеплоСервис — обслуживание котельной'],
        4 => ['kovalev', 47, 6000000, 'ИП Ковалев — акт № 31'],
        5 => ['stroysever', 42, 18630000, 'Счёт № 458 — арматура'],
        6 => ['gorseti', 33, 1911847, 'Городские сети — август'],
        7 => ['teplo', 29, 6250000, 'ТеплоСервис — промывка системы'],
        8 => ['stroysever', 24, 26575000, 'Счёт № 487 — кирпич облицовочный'],
        9 => ['kovalev', 19, 6000000, 'ИП Ковалев — акт № 34'],
        10 => ['gorseti', 15, 1702415, 'Городские сети — авансовый платёж'],
    ];

    public const array PROVIDED = ['title', 'kind', 'vendor_id', 'amount', 'currency', 'invoice_number', 'invoice_date', 'due_date', 'payee'];

    public ?CarbonImmutable $now = null;

    public function run(): void
    {
        $now = $this->now ?? CarbonImmutable::now()->startOfMinute();
        self::seed(DemoUsersSeeder::ensure($now->subDays(130)), $now);
    }

    /**
     * @param  array<string, User>  $users
     */
    public static function seed(array $users, CarbonImmutable $now): Workspace
    {
        $workspace = WorkspaceSetup::workspace('kedr', ['name' => 'Кедр и Партнёры', 'timezone' => 'Europe/Moscow', 'currency' => 'RUB', 'locale' => 'ru'], $users['alex'], $now->subDays(105));

        if (WorkspaceSetup::hasDomainData($workspace)) {
            return $workspace;
        }

        WorkspaceSetup::members($workspace, ['alex' => WorkspaceRole::Owner, 'olga' => WorkspaceRole::Admin, 'irina' => WorkspaceRole::Bookkeeper], $users, [], $now->subDays(105));
        WorkspaceSetup::subscription($workspace, 'starter', $now->addDays(11));
        WorkspaceSetup::resetLedger($workspace);

        $demo = new DemoWorkspace($workspace, $users, $now);
        self::masterData($demo);
        self::requests($demo);
        OutboxSuppression::run(DomainSeeder::SUPPRESSED_TOPICS, fn () => $demo->run());
        DemoFinisher::finish($demo, [], [], null);

        return $workspace->refresh();
    }

    private static function masterData(DemoWorkspace $demo): void
    {
        $t0 = $demo->day(100, '10:00');

        $demo->at($t0, 'kedr settings', function () use ($demo): void {
            app(PolicyService::class)->updateSettings($demo->workspace, $demo->user('alex'), [
                'own_domains' => ['kedr-partners.ru'],
                'country' => 'RU',
                'verification_amount_threshold_minor' => 5000000,
                'telegram_approve_max_risk' => 'medium',
            ]);

            foreach (self::RULES as $rule) {
                app(PolicyService::class)->createRule($demo->workspace, $demo->user('alex'), DemoWorkspaceSeeder::rule($rule));
            }
        });

        foreach (self::VENDORS as $key => $spec) {
            $demo->at($t0->addMinutes(40), 'kedr vendor '.$key, function () use ($demo, $key, $spec): void {
                $demo->vendor($key, 'irina', $spec['vendor'], $spec['domains']);
                $demo->contact($key, $key, 'irina', $spec['contact']);
                $demo->account($key, $key, 'irina', $spec['account']);
            });
            $demo->at($t0->addDay(), 'kedr contact verified '.$key, fn () => $demo->verifyContact($key, 'alex'));
            $demo->at($t0->addDay()->addHour(), 'kedr account verified '.$key, fn () => $demo->verifyAccount($key, 'olga', $key, null, in_array($key, ['kovalev', 'vasiliev'], true) ? VerificationMethod::InPerson : VerificationMethod::CallbackKnownNumber));
        }
    }

    private static function requests(DemoWorkspace $demo): void
    {
        foreach (self::HISTORY as $ref => [$vendor, $daysAgo, $amount, $title]) {
            $at = $demo->day($daysAgo, '11:'.str_pad((string) (10 + $ref * 3), 2, '0', STR_PAD_LEFT));
            $demo->at($at, 'kedr SP-'.$ref, fn () => $demo->manual($ref, 'irina', self::entry($demo, $vendor, $title, $amount, 'Б-'.(300 + $ref * 7), $at)));
            $demo->at($at->addMinutes(10), 'kedr SP-'.$ref.' submit', fn () => $demo->submit($ref, 'irina'));
            $demo->at($at->addHours(3), 'kedr SP-'.$ref.' approve', fn () => $demo->approveAll($ref));
            $demo->at($demo->day($daysAgo - 1, '12:30'), 'kedr SP-'.$ref.' paid', fn () => $demo->pay($ref, 'irina', 'П/п № '.(1200 + $ref)));
        }

        $at = $demo->day(12, '11:00');
        $demo->at($at, 'kedr SP-00012', fn () => $demo->upload(12, 'irina', [DemoWorkspaceSeeder::eml('teplo-servis-smena-rekvizitov.eml', 'Бухгалтерия ТеплоСервис', 'buh@teplo-servis.ru', 'Смена реквизитов, срочно', 'Сообщаем, что у нас изменились реквизиты.', $at, null, 'buh@kedr-partners.ru')], $demo->fixture('kedr-teplo-change', $at), PaymentRequestKind::BankDetailsChange, 'Смена реквизитов ТеплоСервис', [DemoWorkspaceSeeder::intel('teplo-servis.ru', $at->subDays(9)), DemoWorkspaceSeeder::intel('teploservis.ru', CarbonImmutable::parse('2012-02-07'))]));
        $demo->at($at->addMinutes(15), 'kedr SP-00012 submit', fn () => $demo->submit(12, 'irina'));
        $demo->at($at->addHours(2), 'kedr SP-00012 denied', fn () => $demo->verify(12, 'olga', 'teplo', VerificationOutcome::Denied, [], DecisionReason::FraudConfirmed));

        $at = $demo->day(8, '10:20');
        $demo->at($at, 'kedr SP-00014', fn () => $demo->manual(14, 'irina', self::entry($demo, 'gorseti', 'Городские сети — сентябрь', 1874256, 'ГС-0926', $at)));
        $demo->at($at->addMinutes(10), 'kedr SP-00014 submit', fn () => $demo->submit(14, 'irina'));
        $demo->at($at->addHours(4), 'kedr SP-00014 approve', fn () => $demo->approveAll(14));
        $demo->at($demo->day(7, '12:00'), 'kedr SP-00014 paid', fn () => $demo->pay(14, 'irina', 'П/п № 1241'));

        $at = $demo->day(5, '12:00');
        $demo->at($at, 'kedr SP-00015', fn () => $demo->manual(15, 'irina', self::entry($demo, 'kovalev', 'ИП Ковалев — акт № 37', 6000000, 'Акт № 37', $at, 'kovalev.as@yandex.ru')));
        $demo->at($at->addMinutes(10), 'kedr SP-00015 submit', fn () => $demo->submit(15, 'irina'));
        $demo->at($at->addHours(3), 'kedr SP-00015 approve', fn () => $demo->approveAll(15));

        $at = $demo->day(1, '10:00');
        $demo->at($at, 'kedr SP-00016', fn () => $demo->upload(16, 'irina', [DemoWorkspaceSeeder::eml('vasiliev-zarplatnyj-schet.eml', 'Сергей Васильев', 's.vasiliev.zp@mail.ru', 'Смена зарплатного счёта', 'Прошу перечислять мою зарплату на новый счёт.', $at, null, 'buh@kedr-partners.ru')], $demo->fixture('kedr-vasiliev-payroll', $at), PaymentRequestKind::PayrollChange, 'Смена зарплатного счёта — Сергей Васильев'));
        $demo->at($at->addMinutes(20), 'kedr SP-00016 submit', fn () => $demo->submit(16, 'irina'));

        $at = $demo->day(1, '15:30');
        $demo->at($at, 'kedr SP-00013', fn () => $demo->manual(13, 'irina', self::entry($demo, 'stroysever', 'Счёт № 512 — Стройматериалы Север', 24830000, '512', $at)));
        $demo->at($at->addMinutes(10), 'kedr SP-00013 submit', function () use ($demo): void {
            $demo->submit(13, 'irina');
            $demo->assignTo(13, 1, 'olga', 'alex');
        });

        $at = $demo->now->subHours(3);
        $demo->at($at, 'kedr SP-00011', fn () => $demo->upload(11, 'irina', [DemoWorkspaceSeeder::pdf('schet-0917-qr.pdf', 'Schet na oplatu 0917', ['Stroymaterialy Sever, INN 7701543216', 'Arenda sklada po novomu dogovoru', 'Itogo: 185 000,00 RUB', 'BIK 044525225, r/s 40702810938000012345', 'QR: ST00012'])], $demo->fixture('kedr-qr-sklad', $at), PaymentRequestKind::QrPayment, 'Оплата по QR: новый договор аренды склада'));
    }

    private static function entry(DemoWorkspace $demo, string $vendor, string $title, int $amount, string $invoice, CarbonImmutable $at, ?string $sender = null): ManualEntry
    {
        $spec = self::VENDORS[$vendor];
        $local = $at->setTimezone($demo->timezone())->startOfDay();
        $account = $spec['account'];

        return new ManualEntry(
            provided: $sender !== null ? [...self::PROVIDED, 'sender_email'] : self::PROVIDED,
            title: $title,
            kind: PaymentRequestKind::Invoice,
            vendorId: (int) $demo->vendors[$vendor]->getKey(),
            amountMinor: $amount,
            currency: 'RUB',
            invoiceNumber: $invoice,
            invoiceDate: $local->subDay(),
            dueDate: $local->addDays(10),
            payeeScheme: $account['scheme'],
            payeeAccountNumber: $account['account_number'],
            payeeRoutingCode: $account['routing_code'],
            payeeAccountHolder: $account['account_holder'],
            payeeBankName: $account['bank_name'],
            payeeBankCountry: 'RU',
            senderEmail: $sender,
        );
    }
}
