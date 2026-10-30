<?php

declare(strict_types=1);

use App\Domain\Intake\Enums\AuthResult;
use App\Domain\Intake\Enums\BankComparisonOutcome;
use App\Domain\Intake\Enums\EmployeeResponse;
use App\Domain\Intake\Enums\ExtractionStatus;
use App\Domain\Intake\Enums\FileRole;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Enums\PaymentRequestSource;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Enums\PreventedCategory;
use App\Domain\Intake\Enums\VendorMatchMethod;
use App\Domain\Risk\Enums\AssessmentTrigger;
use App\Domain\Risk\Enums\RiskLevel;
use App\Domain\Risk\Enums\SignalCategory;
use App\Domain\Risk\Enums\SignalSeverity;
use App\Domain\Support\Database\DomainSchema;
use App\Domain\Vendors\Enums\BankScheme;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_requests', function (Blueprint $table): void {
            DomainSchema::identity($table);
            DomainSchema::workspace($table);
            $table->string('reference', 16);
            $table->string('kind', 32)->default(PaymentRequestKind::Invoice->value);
            $table->string('source', 32);
            $table->string('status', 32)->default(PaymentRequestStatus::Received->value);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('vendor_match_method', 32)->nullable();
            $table->smallInteger('vendor_match_confidence')->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->bigInteger('amount_base_minor')->nullable();
            $table->char('base_currency', 3);
            $table->decimal('fx_rate', 18, 8)->nullable();
            $table->date('fx_rate_date')->nullable();
            $table->string('invoice_number', 64)->nullable();
            $table->string('invoice_number_normalized', 64)->nullable();
            $table->date('invoice_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('payee_scheme', 32)->nullable();
            $table->text('payee_account_holder')->nullable();
            $table->text('payee_account_number')->nullable();
            $table->text('payee_routing_code')->nullable();
            $table->text('payee_correspondent_account')->nullable();
            $table->string('payee_bic', 11)->nullable();
            $table->string('payee_bank_name', 160)->nullable();
            $table->char('payee_bank_country', 2)->nullable();
            $table->char('payee_fingerprint', 64)->nullable();
            $table->string('payee_last4', 4)->nullable();
            $table->boolean('payee_checksum_valid')->nullable();
            $table->foreignId('matched_bank_account_id')->nullable()->constrained('vendor_bank_accounts')->nullOnDelete();
            $table->string('sender_email', 254)->nullable();
            $table->string('sender_domain', 253)->nullable();
            $table->string('reply_to_email', 254)->nullable();
            $table->string('reply_to_domain', 253)->nullable();
            $table->unsignedBigInteger('risk_assessment_id')->nullable();
            $table->smallInteger('risk_score')->nullable();
            $table->string('risk_level', 16)->nullable();
            $table->boolean('verification_required')->default(false);
            $table->jsonb('verification_reasons')->default(DB::raw("'[]'::jsonb"));
            $table->timestampTz('verification_satisfied_at')->nullable();
            $table->unsignedBigInteger('satisfying_verification_id')->nullable();
            $table->string('employee_response', 16)->nullable();
            $table->timestampTz('employee_responded_at')->nullable();
            $table->unsignedBigInteger('current_route_id')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('submitted_by')->nullable()->constrained('users');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_reason', 32)->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestampTz('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users');
            $table->string('payment_reference', 120)->nullable();
            $table->string('prevented_category', 16)->nullable();
            $table->bigInteger('prevented_amount_base_minor')->nullable();
            $table->integer('revision')->default(1);
            $table->jsonb('pipeline')->default(DB::raw("'{}'::jsonb"));
            $table->boolean('extraction_failed')->default(false);
            $table->timestampsTz();

            $table->unique(['workspace_id', 'reference']);
        });
        DomainSchema::enumCheck('payment_requests', 'kind', PaymentRequestKind::class);
        DomainSchema::enumCheck('payment_requests', 'source', PaymentRequestSource::class);
        DomainSchema::enumCheck('payment_requests', 'status', PaymentRequestStatus::class);
        DomainSchema::enumCheck('payment_requests', 'vendor_match_method', VendorMatchMethod::class, nullable: true);
        DomainSchema::enumCheck('payment_requests', 'payee_scheme', BankScheme::class, nullable: true);
        DomainSchema::enumCheck('payment_requests', 'risk_level', RiskLevel::class, nullable: true);
        DomainSchema::enumCheck('payment_requests', 'employee_response', EmployeeResponse::class, nullable: true);
        DomainSchema::enumCheck('payment_requests', 'prevented_category', PreventedCategory::class, nullable: true);
        DomainSchema::check('payment_requests', 'payment_requests_risk_score_check', 'risk_score BETWEEN 0 AND 100');
        DomainSchema::check('payment_requests', 'payment_requests_amount_check', 'amount_minor IS NULL OR amount_minor >= 0');
        DomainSchema::check('payment_requests', 'payment_requests_match_confidence_check', 'vendor_match_confidence IS NULL OR vendor_match_confidence BETWEEN 0 AND 100');
        DomainSchema::check('payment_requests', 'payment_requests_paid_check', "status <> 'paid' OR paid_at IS NOT NULL");
        DB::statement('CREATE INDEX payment_requests_ws_status_created_index ON payment_requests (workspace_id, status, created_at DESC)');
        DB::statement('CREATE INDEX payment_requests_ws_vendor_created_index ON payment_requests (workspace_id, vendor_id, created_at DESC)');
        DB::statement('CREATE INDEX payment_requests_ws_risk_created_index ON payment_requests (workspace_id, risk_level, created_at DESC)');
        DB::statement('CREATE INDEX payment_requests_ws_payee_fingerprint_index ON payment_requests (workspace_id, payee_fingerprint) WHERE payee_fingerprint IS NOT NULL');
        DB::statement('CREATE INDEX payment_requests_ws_vendor_invoice_index ON payment_requests (workspace_id, vendor_id, invoice_number_normalized) WHERE invoice_number_normalized IS NOT NULL');
        DB::statement("CREATE INDEX payment_requests_expiring_index ON payment_requests (expires_at) WHERE status = 'awaiting_approval'");
        DB::statement('CREATE INDEX payment_requests_ws_requester_index ON payment_requests (workspace_id, requested_by, created_at DESC)');

        Schema::create('payment_request_files', function (Blueprint $table): void {
            DomainSchema::identity($table);
            DomainSchema::workspace($table);
            $table->foreignId('payment_request_id')->constrained('payment_requests')->cascadeOnDelete();
            $table->string('role', 32);
            $table->unsignedBigInteger('parent_file_id')->nullable();
            $table->integer('page')->nullable();
            $table->string('disk', 32)->default('private');
            $table->string('path', 512);
            $table->string('original_name', 255);
            $table->string('mime', 127);
            $table->bigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->integer('pages')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->timestampTz('purged_at')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->unique(['payment_request_id', 'sha256', 'role']);
            $table->index(['workspace_id', 'payment_request_id']);
            $table->foreign('parent_file_id')->references('id')->on('payment_request_files')->cascadeOnDelete();
        });
        DomainSchema::enumCheck('payment_request_files', 'role', FileRole::class);

        Schema::create('extractions', function (Blueprint $table): void {
            DomainSchema::identity($table);
            DomainSchema::workspace($table);
            $table->foreignId('payment_request_id')->constrained('payment_requests')->cascadeOnDelete();
            $table->integer('revision');
            $table->string('status', 32);
            $table->string('extractor_version', 32);
            $table->boolean('ocr_used')->default(false);
            $table->integer('pages')->default(0);
            $table->text('text')->nullable();
            $table->text('fields')->nullable();
            $table->jsonb('warnings')->default(DB::raw("'[]'::jsonb"));
            $table->string('error_code', 64)->nullable();
            $table->integer('duration_ms')->nullable();
            $table->boolean('llm_assisted')->default(false);
            $table->timestampsTz();

            $table->unique(['payment_request_id', 'revision']);
            $table->index(['workspace_id', 'payment_request_id']);
        });
        DomainSchema::enumCheck('extractions', 'status', ExtractionStatus::class);

        Schema::create('email_messages', function (Blueprint $table): void {
            DomainSchema::identity($table);
            DomainSchema::workspace($table);
            $table->foreignId('payment_request_id')->constrained('payment_requests')->cascadeOnDelete();
            $table->foreignId('file_id')->nullable()->constrained('payment_request_files')->cascadeOnDelete();
            $table->string('message_id', 998)->nullable();
            $table->string('from_name', 255)->nullable();
            $table->string('from_email', 254)->nullable();
            $table->string('from_domain', 253)->nullable();
            $table->string('reply_to_email', 254)->nullable();
            $table->string('reply_to_domain', 253)->nullable();
            $table->string('return_path_domain', 253)->nullable();
            $table->jsonb('to_emails')->nullable()->default(DB::raw("'[]'::jsonb"));
            $table->string('subject', 998)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->string('auth_spf', 16)->nullable();
            $table->string('auth_dkim', 16)->nullable();
            $table->string('auth_dmarc', 16)->nullable();
            $table->smallInteger('received_hops')->default(0);
            $table->smallInteger('attachments_count')->default(0);
            $table->timestampsTz();

            $table->index(['workspace_id', 'from_domain']);
            $table->index(['workspace_id', 'payment_request_id']);
        });
        DomainSchema::enumCheck('email_messages', 'auth_spf', AuthResult::class, nullable: true);
        DomainSchema::enumCheck('email_messages', 'auth_dkim', AuthResult::class, nullable: true);
        DomainSchema::enumCheck('email_messages', 'auth_dmarc', AuthResult::class, nullable: true);

        Schema::create('domain_intel', function (Blueprint $table): void {
            $table->string('domain', 253);
            $table->string('unicode_form', 253)->nullable();
            $table->boolean('has_mx')->nullable();
            $table->boolean('has_spf')->nullable();
            $table->string('dmarc_policy', 16)->nullable();
            $table->date('registered_at')->nullable();
            $table->string('registrar', 255)->nullable();
            $table->string('rdap_status', 16);
            $table->string('source', 8);
            $table->timestampTz('checked_at');
            $table->timestampTz('fresh_until');
            $table->timestampsTz();

            $table->primary(['domain', 'source']);
        });
        DomainSchema::valuesCheck('domain_intel', 'dmarc_policy', ['none', 'quarantine', 'reject', 'absent'], nullable: true);
        DomainSchema::valuesCheck('domain_intel', 'rdap_status', ['ok', 'not_found', 'error', 'skipped']);
        DomainSchema::valuesCheck('domain_intel', 'source', ['live', 'fake']);

        Schema::create('domain_intel_sightings', function (Blueprint $table): void {
            DomainSchema::identity($table);
            DomainSchema::workspace($table);
            $table->string('domain', 253);
            $table->timestampTz('first_checked_at');
            $table->timestampsTz();

            $table->unique(['workspace_id', 'domain']);
        });

        Schema::create('bank_comparisons', function (Blueprint $table): void {
            DomainSchema::identity($table);
            DomainSchema::workspace($table);
            $table->foreignId('payment_request_id')->constrained('payment_requests')->cascadeOnDelete();
            $table->integer('revision');
            $table->string('outcome', 32);
            $table->foreignId('previous_account_id')->nullable()->constrained('vendor_bank_accounts')->nullOnDelete();
            $table->jsonb('details')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->unique(['payment_request_id', 'revision']);
            $table->index(['workspace_id', 'payment_request_id']);
        });
        DomainSchema::enumCheck('bank_comparisons', 'outcome', BankComparisonOutcome::class);

        Schema::create('risk_assessments', function (Blueprint $table): void {
            DomainSchema::identity($table);
            DomainSchema::workspace($table);
            $table->foreignId('payment_request_id')->constrained('payment_requests')->cascadeOnDelete();
            $table->integer('revision');
            $table->string('rules_version', 16);
            $table->smallInteger('score');
            $table->string('level', 16);
            $table->smallInteger('raw_points');
            $table->string('floor_signal', 48)->nullable();
            $table->boolean('verification_required');
            $table->char('inputs_digest', 64);
            $table->string('trigger', 32);
            $table->timestampTz('created_at')->nullable();
        });
        DomainSchema::enumCheck('risk_assessments', 'level', RiskLevel::class);
        DomainSchema::enumCheck('risk_assessments', 'trigger', AssessmentTrigger::class);
        DomainSchema::check('risk_assessments', 'risk_assessments_score_check', 'score BETWEEN 0 AND 100');
        DB::statement('CREATE INDEX risk_assessments_ws_request_created_index ON risk_assessments (workspace_id, payment_request_id, created_at DESC)');

        Schema::create('risk_signals', function (Blueprint $table): void {
            DomainSchema::identity($table);
            DomainSchema::workspace($table);
            $table->foreignId('risk_assessment_id')->constrained('risk_assessments')->cascadeOnDelete();
            $table->foreignId('payment_request_id')->constrained('payment_requests')->cascadeOnDelete();
            $table->string('code', 48);
            $table->string('category', 16);
            $table->string('severity', 16);
            $table->smallInteger('points');
            $table->jsonb('evidence')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('created_at')->nullable();

            $table->index(['workspace_id', 'code', 'created_at']);
            $table->index('risk_assessment_id');
        });
        DomainSchema::enumCheck('risk_signals', 'category', SignalCategory::class);
        DomainSchema::enumCheck('risk_signals', 'severity', SignalSeverity::class);

        Schema::table('payment_requests', function (Blueprint $table): void {
            $table->foreign('risk_assessment_id')->references('id')->on('risk_assessments')->nullOnDelete();
        });
        Schema::table('vendor_bank_accounts', function (Blueprint $table): void {
            $table->foreign('source_payment_request_id')->references('id')->on('payment_requests')->nullOnDelete();
        });
        Schema::table('blocked_bank_accounts', function (Blueprint $table): void {
            $table->foreign('source_payment_request_id')->references('id')->on('payment_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('blocked_bank_accounts', function (Blueprint $table): void {
            $table->dropForeign(['source_payment_request_id']);
        });
        Schema::table('vendor_bank_accounts', function (Blueprint $table): void {
            $table->dropForeign(['source_payment_request_id']);
        });
        Schema::table('payment_requests', function (Blueprint $table): void {
            $table->dropForeign(['risk_assessment_id']);
        });
        Schema::dropIfExists('risk_signals');
        Schema::dropIfExists('risk_assessments');
        Schema::dropIfExists('bank_comparisons');
        Schema::dropIfExists('domain_intel_sightings');
        Schema::dropIfExists('domain_intel');
        Schema::dropIfExists('email_messages');
        Schema::dropIfExists('extractions');
        Schema::dropIfExists('payment_request_files');
        Schema::dropIfExists('payment_requests');
    }
};
