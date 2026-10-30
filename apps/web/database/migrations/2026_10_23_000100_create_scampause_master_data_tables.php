<?php

declare(strict_types=1);

use App\Domain\Risk\Enums\RiskLevel;
use App\Domain\Support\Database\DomainSchema;
use App\Domain\Vendors\Enums\BankAccountSource;
use App\Domain\Vendors\Enums\BankAccountStatus;
use App\Domain\Vendors\Enums\BankScheme;
use App\Domain\Vendors\Enums\BlockReason;
use App\Domain\Vendors\Enums\ContactSource;
use App\Domain\Vendors\Enums\ContactStatus;
use App\Domain\Vendors\Enums\DomainSource;
use App\Domain\Vendors\Enums\RetiredReason;
use App\Domain\Vendors\Enums\VendorKind;
use App\Domain\Vendors\Enums\VendorStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('workspace_payment_settings', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->primary()->constrained('workspaces')->cascadeOnDelete();
            $table->jsonb('own_domains')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('business_days')->default(DB::raw("'[1,2,3,4,5]'::jsonb"));
            $table->time('business_hours_start')->default('09:00');
            $table->time('business_hours_end')->default('18:00');
            $table->time('quiet_hours_start')->default('21:00');
            $table->time('quiet_hours_end')->default('08:00');
            $table->smallInteger('default_expiry_hours')->default(48);
            $table->jsonb('reminder_offsets_hours')->default(DB::raw("'[4,24]'::jsonb"));
            $table->smallInteger('escalate_after_hours')->default(24);
            $table->boolean('telegram_approvals_enabled')->default(true);
            $table->string('telegram_approve_max_risk', 16)->default('medium');
            $table->bigInteger('verification_amount_threshold_minor')->default(100000);
            $table->smallInteger('verification_valid_days')->default(7);
            $table->timestampTz('solo_mode_acknowledged_at')->nullable();
            $table->char('country', 2);
            $table->smallInteger('callback_cooldown_days')->default(7);
            $table->boolean('ai_extract_assist')->default(false);
            $table->smallInteger('new_approver_grace_hours')->default(24);
            $table->timestampsTz();
        });
        DomainSchema::check('workspace_payment_settings', 'wps_default_expiry_hours_check', 'default_expiry_hours BETWEEN 4 AND 168');
        DomainSchema::check('workspace_payment_settings', 'wps_escalate_after_hours_check', 'escalate_after_hours BETWEEN 1 AND 168');
        DomainSchema::check('workspace_payment_settings', 'wps_callback_cooldown_days_check', 'callback_cooldown_days BETWEEN 0 AND 30');
        DomainSchema::check('workspace_payment_settings', 'wps_new_approver_grace_hours_check', 'new_approver_grace_hours BETWEEN 0 AND 168');
        DomainSchema::check('workspace_payment_settings', 'wps_verification_valid_days_check', 'verification_valid_days BETWEEN 1 AND 30');
        DomainSchema::check('workspace_payment_settings', 'wps_threshold_check', 'verification_amount_threshold_minor >= 0');
        DomainSchema::enumCheck('workspace_payment_settings', 'telegram_approve_max_risk', RiskLevel::class);

        Schema::create('vendors', function (Blueprint $table): void {
            DomainSchema::identity($table);
            DomainSchema::workspace($table);
            $table->string('kind', 32)->default(VendorKind::Supplier->value);
            $table->string('name', 200);
            $table->string('legal_name', 255)->nullable();
            $table->jsonb('aliases')->default(DB::raw("'[]'::jsonb"));
            $table->string('tax_id', 32)->nullable();
            $table->string('tax_id_normalized', 32)->nullable();
            $table->char('country', 2)->nullable();
            $table->char('default_currency', 3)->nullable();
            $table->string('status', 32)->default(VendorStatus::Active->value);
            $table->foreignId('linked_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->smallInteger('payment_terms_days')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('first_paid_at')->nullable();
            $table->timestampTz('last_paid_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['workspace_id', 'status']);
        });
        DomainSchema::enumCheck('vendors', 'kind', VendorKind::class);
        DomainSchema::enumCheck('vendors', 'status', VendorStatus::class);
        DomainSchema::check('vendors', 'vendors_payment_terms_days_check', 'payment_terms_days IS NULL OR payment_terms_days BETWEEN 0 AND 365');
        DB::statement('CREATE UNIQUE INDEX vendors_workspace_name_unique ON vendors (workspace_id, lower(name)) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX vendors_workspace_tax_id_index ON vendors (workspace_id, tax_id_normalized) WHERE tax_id_normalized IS NOT NULL');
        DB::statement("CREATE INDEX vendors_search_trgm ON vendors USING gin (lower(name || ' ' || coalesce(legal_name, '') || ' ' || aliases::text) gin_trgm_ops)");

        Schema::create('vendor_domains', function (Blueprint $table): void {
            DomainSchema::identity($table);
            DomainSchema::workspace($table);
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('domain', 253);
            $table->string('source', 32);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->unique(['workspace_id', 'vendor_id', 'domain']);
            $table->index(['workspace_id', 'domain']);
        });
        DomainSchema::enumCheck('vendor_domains', 'source', DomainSource::class);

        Schema::create('vendor_contacts', function (Blueprint $table): void {
            DomainSchema::identity($table);
            DomainSchema::workspace($table);
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('role_title', 120)->nullable();
            $table->string('email', 254)->nullable();
            $table->text('phone_e164')->nullable();
            $table->char('phone_hash', 64)->nullable();
            $table->string('phone_last4', 4)->nullable();
            $table->boolean('is_callback')->default(false);
            $table->string('status', 24)->default(ContactStatus::PendingVerification->value);
            $table->foreignId('created_by')->constrained('users');
            $table->unsignedBigInteger('verification_note_id')->nullable();
            $table->timestampTz('phone_changed_at')->nullable();
            $table->string('source', 32);
            $table->string('source_ref', 255)->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['workspace_id', 'vendor_id']);
            $table->index(['workspace_id', 'phone_hash']);
        });
        DomainSchema::enumCheck('vendor_contacts', 'status', ContactStatus::class);
        DomainSchema::enumCheck('vendor_contacts', 'source', ContactSource::class);
        DomainSchema::check('vendor_contacts', 'vendor_contacts_four_eyes_check', 'verified_by IS NULL OR verified_by <> created_by');
        DomainSchema::check('vendor_contacts', 'vendor_contacts_callback_source_check', "NOT is_callback OR source <> 'invoice_email'");
        DomainSchema::check('vendor_contacts', 'vendor_contacts_callback_complete_check', 'NOT is_callback OR (phone_hash IS NOT NULL AND source_ref IS NOT NULL)');

        Schema::create('vendor_bank_accounts', function (Blueprint $table): void {
            DomainSchema::identity($table);
            DomainSchema::workspace($table);
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('scheme', 32);
            $table->text('account_holder')->nullable();
            $table->text('account_number');
            $table->text('routing_code')->nullable();
            $table->text('correspondent_account')->nullable();
            $table->string('bic', 11)->nullable();
            $table->string('bank_name', 160)->nullable();
            $table->char('bank_country', 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->char('fingerprint', 64);
            $table->string('last4', 4);
            $table->string('status', 32)->default(BankAccountStatus::PendingVerification->value);
            $table->string('source', 32);
            $table->unsignedBigInteger('source_payment_request_id')->nullable();
            $table->unsignedBigInteger('verification_id')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_to')->nullable();
            $table->string('retired_reason', 32)->nullable();
            $table->boolean('sod_waived')->default(false);
            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz();

            $table->unique(['workspace_id', 'vendor_id', 'fingerprint']);
            $table->index(['workspace_id', 'fingerprint']);
            $table->index(['workspace_id', 'vendor_id', 'status']);
        });
        DomainSchema::enumCheck('vendor_bank_accounts', 'scheme', BankScheme::class);
        DomainSchema::enumCheck('vendor_bank_accounts', 'status', BankAccountStatus::class);
        DomainSchema::enumCheck('vendor_bank_accounts', 'source', BankAccountSource::class);
        DomainSchema::enumCheck('vendor_bank_accounts', 'retired_reason', RetiredReason::class, nullable: true);
        DomainSchema::check('vendor_bank_accounts', 'vendor_bank_accounts_verified_check', "status <> 'verified' OR (verification_id IS NOT NULL AND verified_by IS NOT NULL AND valid_from IS NOT NULL)");
        DomainSchema::check('vendor_bank_accounts', 'vendor_bank_accounts_retired_check', "status <> 'retired' OR valid_to IS NOT NULL");

        Schema::create('blocked_bank_accounts', function (Blueprint $table): void {
            DomainSchema::identity($table);
            DomainSchema::workspace($table);
            $table->char('fingerprint', 64);
            $table->string('scheme', 32);
            $table->string('last4', 4);
            $table->string('bank_name', 160)->nullable();
            $table->char('bank_country', 2)->nullable();
            $table->string('reason', 32);
            $table->unsignedBigInteger('source_payment_request_id')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('unblocked_by')->nullable()->constrained('users');
            $table->string('unblock_reason', 255)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });
        DomainSchema::enumCheck('blocked_bank_accounts', 'scheme', BankScheme::class);
        DomainSchema::enumCheck('blocked_bank_accounts', 'reason', BlockReason::class);
        DB::statement('CREATE UNIQUE INDEX blocked_bank_accounts_active_unique ON blocked_bank_accounts (workspace_id, fingerprint) WHERE deleted_at IS NULL');

        Schema::create('exchange_rates', function (Blueprint $table): void {
            DomainSchema::identity($table);
            DomainSchema::workspace($table);
            $table->char('quote_currency', 3);
            $table->char('base_currency', 3);
            $table->decimal('rate', 18, 8);
            $table->date('effective_date');
            $table->string('source', 16)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->unique(['workspace_id', 'quote_currency', 'effective_date']);
        });
        DomainSchema::check('exchange_rates', 'exchange_rates_rate_check', 'rate > 0');
        DomainSchema::valuesCheck('exchange_rates', 'source', ['manual', 'seed']);

        Schema::create('workspace_sequences', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name', 32);
            $table->bigInteger('value')->default(0);
            $table->primary(['workspace_id', 'name']);
        });

        Schema::create('member_limits', function (Blueprint $table): void {
            DomainSchema::identity($table);
            DomainSchema::workspace($table);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->bigInteger('approval_limit_base_minor')->nullable();
            $table->bigInteger('monthly_limit_base_minor')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->unique(['workspace_id', 'user_id']);
        });

        Schema::create('approval_rules', function (Blueprint $table): void {
            DomainSchema::identity($table);
            DomainSchema::workspace($table);
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->smallInteger('position');
            $table->boolean('is_active')->default(true);
            $table->integer('version')->default(1);
            $table->bigInteger('min_amount_base_minor')->nullable();
            $table->bigInteger('max_amount_base_minor')->nullable();
            $table->jsonb('kinds')->nullable();
            $table->string('min_risk_level', 16)->nullable();
            $table->boolean('applies_to_new_vendor')->nullable();
            $table->boolean('applies_to_bank_change')->nullable();
            $table->smallInteger('approvals_required')->default(1);
            $table->jsonb('required_roles')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('approver_roles')->default(DB::raw('\'["owner","admin","approver"]\'::jsonb'));
            $table->boolean('verification_required')->default(false);
            $table->smallInteger('expiry_hours')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['workspace_id', 'is_active', 'position']);
        });
        DomainSchema::enumCheck('approval_rules', 'min_risk_level', RiskLevel::class, nullable: true);
        DomainSchema::check('approval_rules', 'approval_rules_amount_range_check', 'max_amount_base_minor IS NULL OR min_amount_base_minor IS NULL OR max_amount_base_minor > min_amount_base_minor');
        DomainSchema::check('approval_rules', 'approval_rules_approvals_required_check', 'approvals_required BETWEEN 1 AND 5');
        DomainSchema::check('approval_rules', 'approval_rules_expiry_hours_check', 'expiry_hours IS NULL OR expiry_hours BETWEEN 4 AND 168');
        DomainSchema::check('approval_rules', 'approval_rules_kinds_check', "kinds IS NULL OR jsonb_typeof(kinds) = 'array'");
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_rules');
        Schema::dropIfExists('member_limits');
        Schema::dropIfExists('workspace_sequences');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('blocked_bank_accounts');
        Schema::dropIfExists('vendor_bank_accounts');
        Schema::dropIfExists('vendor_contacts');
        Schema::dropIfExists('vendor_domains');
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('workspace_payment_settings');
    }
};
