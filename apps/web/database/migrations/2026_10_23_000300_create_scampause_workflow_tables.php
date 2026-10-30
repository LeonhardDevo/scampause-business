<?php

declare(strict_types=1);

use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Enums\DecisionChannel;
use App\Domain\Approvals\Enums\RouteStatus;
use App\Domain\Evidence\Enums\EvidenceChannel;
use App\Domain\Intake\Enums\DecisionReason;
use App\Domain\Intake\Enums\EmployeeResponse;
use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Enums\ExportKind;
use App\Domain\Reports\Enums\ExportStatus;
use App\Domain\Support\Database\DomainSchema;
use App\Domain\Verification\Enums\VerificationMethod;
use App\Domain\Verification\Enums\VerificationOutcome;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_routes', function (Blueprint $table): void {
            DomainSchema::identity($table);
            DomainSchema::workspace($table);
            $table->foreignId('payment_request_id')->constrained('payment_requests')->cascadeOnDelete();
            $table->smallInteger('attempt');
            $table->string('status', 16)->default(RouteStatus::Active->value);
            $table->smallInteger('approvals_required');
            $table->jsonb('required_roles')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('approver_roles')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('rule_snapshot')->default(DB::raw("'[]'::jsonb"));
            $table->boolean('solo_mode')->default(false);
            $table->timestampTz('started_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('escalated_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->unique(['payment_request_id', 'attempt']);
            $table->index(['workspace_id', 'payment_request_id']);
        });
        DomainSchema::enumCheck('approval_routes', 'status', RouteStatus::class);
        DB::statement("CREATE INDEX approval_routes_active_expiry_index ON approval_routes (status, expires_at) WHERE status = 'active'");

        Schema::create('approvals', function (Blueprint $table): void {
            DomainSchema::identity($table);
            DomainSchema::workspace($table);
            $table->foreignId('approval_route_id')->constrained('approval_routes')->cascadeOnDelete();
            $table->foreignId('payment_request_id')->constrained('payment_requests')->cascadeOnDelete();
            $table->foreignId('approver_id')->constrained('users');
            $table->smallInteger('slot');
            $table->string('status', 16)->default(ApprovalStatus::Pending->value);
            $table->string('channel', 16)->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('reject_reason', 32)->nullable();
            $table->text('comment')->nullable();
            $table->smallInteger('reminders_sent')->default(0);
            $table->timestampTz('last_reminded_at')->nullable();
            $table->char('telegram_nonce_hash', 64)->nullable();
            $table->jsonb('telegram_message')->nullable();
            $table->unsignedBigInteger('replaced_approval_id')->nullable();
            $table->timestampsTz();

            $table->index(['workspace_id', 'approver_id', 'status']);
            $table->index(['workspace_id', 'payment_request_id']);
            $table->foreign('replaced_approval_id')->references('id')->on('approvals');
        });
        DomainSchema::enumCheck('approvals', 'status', ApprovalStatus::class);
        DomainSchema::enumCheck('approvals', 'channel', DecisionChannel::class, nullable: true);
        DomainSchema::enumCheck('approvals', 'reject_reason', DecisionReason::class, nullable: true);
        DB::statement("CREATE UNIQUE INDEX approvals_route_approver_unique ON approvals (approval_route_id, approver_id) WHERE status <> 'reassigned'");

        Schema::create('evidence_heads', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->primary()->constrained('workspaces')->cascadeOnDelete();
            $table->bigInteger('last_seq')->default(0);
            $table->char('last_hash', 64)->default(str_repeat('0', 64));
            $table->timestampTz('updated_at')->nullable();
        });

        Schema::create('evidence_entries', function (Blueprint $table): void {
            DomainSchema::identity($table);
            DomainSchema::workspace($table);
            $table->bigInteger('seq');
            $table->unsignedBigInteger('payment_request_id')->nullable();
            $table->string('subject_type', 48);
            $table->bigInteger('subject_id');
            $table->string('event', 48);
            $table->foreignId('actor_user_id')->nullable()->constrained('users');
            $table->string('channel', 16);
            $table->timestampTz('occurred_at', 6);
            $table->jsonb('payload');
            $table->char('prev_hash', 64);
            $table->char('hash', 64);
            $table->timestampTz('created_at')->nullable();

            $table->unique(['workspace_id', 'seq']);
            $table->index(['workspace_id', 'payment_request_id', 'seq']);
            $table->foreign('payment_request_id')->references('id')->on('payment_requests');
        });
        DomainSchema::enumCheck('evidence_entries', 'channel', EvidenceChannel::class);

        Schema::create('verifications', function (Blueprint $table): void {
            DomainSchema::identity($table);
            DomainSchema::workspace($table);
            $table->unsignedBigInteger('payment_request_id')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->unsignedBigInteger('vendor_bank_account_id')->nullable();
            $table->string('method', 32);
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->text('phone_dialed')->nullable();
            $table->char('phone_dialed_hash', 64)->nullable();
            $table->string('phone_dialed_last4', 4)->nullable();
            $table->string('phone_source', 32)->nullable();
            $table->text('spoken_to_name')->nullable();
            $table->string('outcome', 16);
            $table->jsonb('checklist');
            $table->unsignedBigInteger('replaces_account_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->text('notes')->nullable();
            $table->foreignId('performed_by')->constrained('users');
            $table->boolean('sod_waived')->default(false);
            $table->unsignedBigInteger('evidence_entry_id')->nullable();
            $table->timestampTz('created_at');

            $table->index(['workspace_id', 'payment_request_id']);
            $table->index(['workspace_id', 'vendor_id']);
            $table->foreign('payment_request_id')->references('id')->on('payment_requests');
            $table->foreign('vendor_id')->references('id')->on('vendors');
            $table->foreign('vendor_bank_account_id')->references('id')->on('vendor_bank_accounts');
            $table->foreign('contact_id')->references('id')->on('vendor_contacts');
            $table->foreign('replaces_account_id')->references('id')->on('vendor_bank_accounts');
            $table->foreign('evidence_entry_id')->references('id')->on('evidence_entries');
        });
        DomainSchema::enumCheck('verifications', 'method', VerificationMethod::class);
        DomainSchema::enumCheck('verifications', 'outcome', VerificationOutcome::class);
        DomainSchema::check('verifications', 'verifications_phone_source_check', "phone_source IS NULL OR phone_source IN ('contract', 'website_verified', 'in_person', 'onboarding_form')");

        Schema::create('report_exports', function (Blueprint $table): void {
            DomainSchema::identity($table);
            DomainSchema::workspace($table);
            $table->string('kind', 32);
            $table->string('format', 8);
            $table->string('locale', 2);
            $table->foreignId('payment_request_id')->nullable()->constrained('payment_requests')->cascadeOnDelete();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->string('status', 16)->default(ExportStatus::Queued->value);
            $table->string('path', 512)->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('error', 500)->nullable();
            $table->boolean('chain_verified')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
        });
        DomainSchema::enumCheck('report_exports', 'kind', ExportKind::class);
        DomainSchema::enumCheck('report_exports', 'format', ExportFormat::class);
        DomainSchema::enumCheck('report_exports', 'status', ExportStatus::class);
        DB::statement('CREATE INDEX report_exports_ws_kind_created_index ON report_exports (workspace_id, kind, created_at DESC)');

        Schema::create('employee_alerts', function (Blueprint $table): void {
            DomainSchema::identity($table);
            DomainSchema::workspace($table);
            $table->unsignedBigInteger('payment_request_id');
            $table->foreignId('vendor_id')->constrained('vendors');
            $table->string('channel', 16);
            $table->char('token_hash', 64)->nullable()->unique();
            $table->char('nonce_hash', 64)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('answered_at')->nullable();
            $table->string('response', 16)->nullable();
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestampsTz();

            $table->index(['workspace_id', 'payment_request_id']);
            $table->foreign('payment_request_id')->references('id')->on('payment_requests');
        });
        DomainSchema::valuesCheck('employee_alerts', 'channel', ['telegram', 'email']);
        DomainSchema::enumCheck('employee_alerts', 'response', EmployeeResponse::class, nullable: true);

        Schema::create('sp_notifications', function (Blueprint $table): void {
            DomainSchema::identity($table);
            DomainSchema::workspace($table);
            $table->foreignId('payment_request_id')->nullable()->constrained('payment_requests')->cascadeOnDelete();
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->cascadeOnDelete();
            $table->string('template', 64);
            $table->foreignId('recipient_user_id')->nullable()->constrained('users');
            $table->foreignId('recipient_vendor_contact_id')->nullable()->constrained('vendor_contacts')->nullOnDelete();
            $table->string('channel', 16);
            $table->string('status', 16)->default('queued');
            $table->string('provider_message_id', 255)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestampsTz();

            $table->index(['workspace_id', 'payment_request_id']);
        });
        DomainSchema::valuesCheck('sp_notifications', 'channel', ['telegram', 'email', 'database']);
        DomainSchema::valuesCheck('sp_notifications', 'status', ['queued', 'sent', 'failed']);

        Schema::table('vendor_bank_accounts', function (Blueprint $table): void {
            $table->foreign('verification_id')->references('id')->on('verifications');
        });
        Schema::table('payment_requests', function (Blueprint $table): void {
            $table->foreign('satisfying_verification_id')->references('id')->on('verifications')->nullOnDelete();
            $table->foreign('current_route_id')->references('id')->on('approval_routes')->nullOnDelete();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION scampause_verifications_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF current_setting('scampause.allow_evidence_purge', true) = 'on' THEN
                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    IF OLD.evidence_entry_id IS NULL AND NEW.evidence_entry_id IS NOT NULL
                        AND (to_jsonb(NEW) - 'evidence_entry_id') = (to_jsonb(OLD) - 'evidence_entry_id') THEN
                        RETURN NEW;
                    END IF;
                    IF current_setting('scampause.allow_pii_redaction', true) = 'on'
                        AND (to_jsonb(NEW) - ARRAY['spoken_to_name', 'phone_dialed', 'notes']) = (to_jsonb(OLD) - ARRAY['spoken_to_name', 'phone_dialed', 'notes'])
                        AND (NEW.spoken_to_name IS NULL OR NEW.spoken_to_name IS NOT DISTINCT FROM OLD.spoken_to_name)
                        AND (NEW.phone_dialed IS NULL OR NEW.phone_dialed IS NOT DISTINCT FROM OLD.phone_dialed)
                        AND (NEW.notes IS NULL OR NEW.notes IS NOT DISTINCT FROM OLD.notes) THEN
                        RETURN NEW;
                    END IF;
                END IF;
                RAISE EXCEPTION 'verifications are immutable' USING ERRCODE = 'P0001';
            END;
            $$;
            SQL);
        DomainSchema::immutableTrigger('verifications', 'scampause_verifications_guard');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION scampause_evidence_entries_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF current_setting('scampause.allow_evidence_purge', true) = 'on' THEN
                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'evidence entries are immutable' USING ERRCODE = 'P0001';
            END;
            $$;
            SQL);
        DomainSchema::immutableTrigger('evidence_entries', 'scampause_evidence_entries_guard');

        DB::statement(<<<'SQL'
            INSERT INTO workspace_payment_settings (workspace_id, country, created_at, updated_at)
            SELECT id, CASE WHEN locale = 'ru' THEN 'RU' ELSE 'US' END, now(), now() FROM workspaces
            ON CONFLICT (workspace_id) DO NOTHING
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS evidence_entries_immutable ON evidence_entries');
        DB::statement('DROP TRIGGER IF EXISTS verifications_immutable ON verifications');
        DB::statement('DROP FUNCTION IF EXISTS scampause_evidence_entries_guard()');
        DB::statement('DROP FUNCTION IF EXISTS scampause_verifications_guard()');

        Schema::table('payment_requests', function (Blueprint $table): void {
            $table->dropForeign(['satisfying_verification_id']);
            $table->dropForeign(['current_route_id']);
        });
        Schema::table('vendor_bank_accounts', function (Blueprint $table): void {
            $table->dropForeign(['verification_id']);
        });

        Schema::dropIfExists('sp_notifications');
        Schema::dropIfExists('employee_alerts');
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('verifications');
        Schema::dropIfExists('evidence_entries');
        Schema::dropIfExists('evidence_heads');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('approval_routes');
    }
};
