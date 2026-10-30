<?php

declare(strict_types=1);

namespace Database\Seeders\Domain;

use App\Domain\Vendors\Enums\BankScheme;
use App\Domain\Vendors\Enums\ContactSource;
use App\Domain\Vendors\Enums\VendorKind;

final class NorthwindCatalog
{
    /**
     * @return array<string, array{vendor: array<string, mixed>, creator: string, domains: list<string>, contact: array{name: string, role_title: string|null, phone: string, source: ContactSource, source_ref: string|null}, accounts: list<array{key: string, scheme: BankScheme, account_number: string, routing_code?: string, bic?: string, bank_name: string, bank_country: string, account_holder: string, currency: string}>}>
     */
    public static function vendors(): array
    {
        return [
            'blue_ridge' => [
                'vendor' => ['kind' => VendorKind::Supplier, 'name' => 'Blue Ridge Packaging Co.', 'legal_name' => 'Blue Ridge Packaging Company, Inc.', 'tax_id' => '56-1234567', 'country' => 'US', 'default_currency' => 'USD', 'payment_terms_days' => 30],
                'creator' => 'irina',
                'domains' => ['blueridgepack.com'],
                'contact' => ['name' => 'Karen Whitfield', 'role_title' => 'AR Manager', 'phone' => '+1 828 555 0142', 'source' => ContactSource::Contract, 'source_ref' => 'Supply Agreement BRP-2025-014, §9.2'],
                'accounts' => [['key' => 'blue_ridge_4417', 'scheme' => BankScheme::UsAch, 'routing_code' => '021000021', 'account_number' => '000483924417', 'bank_name' => 'JPMorgan Chase Bank, N.A.', 'bank_country' => 'US', 'account_holder' => 'Blue Ridge Packaging Company, Inc.', 'currency' => 'USD']],
            ],
            'andes' => [
                'vendor' => ['kind' => VendorKind::Supplier, 'name' => 'Andes Green Coffee Importers S.L.', 'legal_name' => 'Andes Green Coffee Importers S.L.', 'tax_id' => 'B12345678', 'country' => 'ES', 'default_currency' => 'EUR', 'payment_terms_days' => 30],
                'creator' => 'maria',
                'domains' => ['andesgreencoffee.com'],
                'contact' => ['name' => 'Lucía Ortega', 'role_title' => 'Export Accounts', 'phone' => '+34 915 550 187', 'source' => ContactSource::Contract, 'source_ref' => 'Green Coffee Supply Contract AG-2024-07, cl. 12'],
                'accounts' => [['key' => 'andes_iban', 'scheme' => BankScheme::Iban, 'account_number' => 'ES7921000813610123456789', 'bic' => 'CAIXESBBXXX', 'bank_name' => 'CaixaBank', 'bank_country' => 'ES', 'account_holder' => 'Andes Green Coffee Importers S.L.', 'currency' => 'EUR']],
            ],
            'cleansweep' => [
                'vendor' => ['kind' => VendorKind::Contractor, 'name' => 'CleanSweep Facility Services LLC', 'legal_name' => 'CleanSweep Facility Services LLC', 'country' => 'US', 'default_currency' => 'USD', 'payment_terms_days' => 15],
                'creator' => 'irina',
                'domains' => ['cleansweepfs.com'],
                'contact' => ['name' => 'Dana Ruiz', 'role_title' => 'Billing', 'phone' => '+1 917 555 0110', 'source' => ContactSource::WebsiteVerified, 'source_ref' => 'cleansweepfs.com/contact, checked 2026-02-11'],
                'accounts' => [['key' => 'cleansweep_7781', 'scheme' => BankScheme::UsAch, 'routing_code' => '026009593', 'account_number' => '3300417781', 'bank_name' => 'Bank of America, N.A.', 'bank_country' => 'US', 'account_holder' => 'CleanSweep Facility Services LLC', 'currency' => 'USD']],
            ],
            'metro' => [
                'vendor' => ['kind' => VendorKind::Supplier, 'name' => 'Metro Power & Gas', 'legal_name' => 'Metro Power & Gas Company', 'country' => 'US', 'default_currency' => 'USD', 'payment_terms_days' => 21],
                'creator' => 'irina',
                'domains' => ['metropowergas.com'],
                'contact' => ['name' => 'Customer Service', 'role_title' => null, 'phone' => '+1 800 555 0199', 'source' => ContactSource::WebsiteVerified, 'source_ref' => 'Bill back page'],
                'accounts' => [['key' => 'metro_3020', 'scheme' => BankScheme::UsAch, 'routing_code' => '121000248', 'account_number' => '6011033020', 'bank_name' => 'Wells Fargo Bank, N.A.', 'bank_country' => 'US', 'account_holder' => 'Metro Power & Gas Company', 'currency' => 'USD']],
            ],
            'northstar' => [
                'vendor' => ['kind' => VendorKind::Supplier, 'name' => 'Northstar Equipment Leasing', 'legal_name' => 'Northstar Equipment Leasing LLC', 'country' => 'US', 'default_currency' => 'USD', 'payment_terms_days' => 10],
                'creator' => 'maria',
                'domains' => ['northstarleasing.com'],
                'contact' => ['name' => 'Greg Holloway', 'role_title' => 'Controller', 'phone' => '+1 312 555 0133', 'source' => ContactSource::Contract, 'source_ref' => 'Equipment Lease NEL-2023-118, §14.1'],
                'accounts' => [['key' => 'northstar_5512', 'scheme' => BankScheme::UsAch, 'routing_code' => '011000138', 'account_number' => '2200915512', 'bank_name' => 'Bank of America, N.A.', 'bank_country' => 'US', 'account_holder' => 'Northstar Equipment Leasing', 'currency' => 'USD']],
            ],
            'harbor' => [
                'vendor' => ['kind' => VendorKind::Supplier, 'name' => 'Harbor Logistics Group', 'legal_name' => 'Harbor Logistics Group LLC', 'country' => 'US', 'default_currency' => 'USD', 'payment_terms_days' => 15],
                'creator' => 'irina',
                'domains' => ['harborlg.com'],
                'contact' => ['name' => 'Tom Becker', 'role_title' => 'Accounts Receivable', 'phone' => '+1 201 555 0164', 'source' => ContactSource::Contract, 'source_ref' => 'Freight Services Agreement HLG-2025-033, §7'],
                'accounts' => [['key' => 'harbor_6604', 'scheme' => BankScheme::UsAch, 'routing_code' => '031101279', 'account_number' => '5501206604', 'bank_name' => 'Valley National Bank', 'bank_country' => 'US', 'account_holder' => 'Harbor Logistics Group', 'currency' => 'USD']],
            ],
            'summit' => [
                'vendor' => ['kind' => VendorKind::Supplier, 'name' => 'Summit Office Supply', 'legal_name' => 'Summit Office Supply Inc.', 'country' => 'US', 'default_currency' => 'USD', 'payment_terms_days' => 30],
                'creator' => 'irina',
                'domains' => ['summitoffice.com'],
                'contact' => ['name' => 'Orders desk', 'role_title' => null, 'phone' => '+1 855 555 0108', 'source' => ContactSource::WebsiteVerified, 'source_ref' => 'summitoffice.com/support'],
                'accounts' => [['key' => 'summit_9145', 'scheme' => BankScheme::UsAch, 'routing_code' => '121000248', 'account_number' => '4100779145', 'bank_name' => 'Wells Fargo Bank, N.A.', 'bank_country' => 'US', 'account_holder' => 'Summit Office Supply Inc.', 'currency' => 'USD']],
            ],
            'jordan' => [
                'vendor' => ['kind' => VendorKind::Employee, 'name' => 'Jordan Lee', 'legal_name' => 'Jordan Lee', 'country' => 'US', 'default_currency' => 'USD', 'linked_user' => 'jordan'],
                'creator' => 'maria',
                'domains' => [],
                'contact' => ['name' => 'Jordan Lee', 'role_title' => 'Roastery lead', 'phone' => '+1 917 555 0123', 'source' => ContactSource::InPerson, 'source_ref' => 'HR file, confirmed in person 2025-06-02'],
                'accounts' => [['key' => 'jordan_2291', 'scheme' => BankScheme::UsAch, 'routing_code' => '061000104', 'account_number' => '8800342291', 'bank_name' => 'Truist Bank', 'bank_country' => 'US', 'account_holder' => 'Jordan Lee', 'currency' => 'USD']],
            ],
        ];
    }

    /**
     * @return list<array{name: string, min?: int, kinds?: list<string>, min_risk_level?: string, applies_to_bank_change?: bool, approvals_required: int, required_roles?: list<string>, verification_required?: bool}>
     */
    public static function rules(): array
    {
        return [
            ['name' => 'Every payment: one approver', 'approvals_required' => 1],
            ['name' => 'Dual control from $3,000', 'min' => 300000, 'approvals_required' => 2],
            ['name' => 'Owner sign-off from $10,000', 'min' => 1000000, 'approvals_required' => 2, 'required_roles' => ['owner']],
            ['name' => 'Bank detail changes: callback + two approvers', 'applies_to_bank_change' => true, 'verification_required' => true, 'approvals_required' => 2, 'required_roles' => ['admin']],
            ['name' => 'Payroll changes: callback + owner', 'kinds' => ['payroll_change'], 'verification_required' => true, 'approvals_required' => 2, 'required_roles' => ['owner']],
            ['name' => 'High risk: callback required', 'min_risk_level' => 'high', 'verification_required' => true, 'approvals_required' => 2],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function rates(): array
    {
        return ['EUR' => '1.08500000', 'GBP' => '1.27000000', 'RUB' => '0.01100000'];
    }
}
