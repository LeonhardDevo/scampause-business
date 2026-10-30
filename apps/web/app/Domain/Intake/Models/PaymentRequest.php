<?php

declare(strict_types=1);

namespace App\Domain\Intake\Models;

use App\Domain\Approvals\Models\Approval;
use App\Domain\Approvals\Models\ApprovalRoute;
use App\Domain\Evidence\Models\EvidenceEntry;
use App\Domain\Intake\Enums\EmployeeResponse;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Enums\PaymentRequestSource;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Enums\PreventedCategory;
use App\Domain\Intake\Enums\VendorMatchMethod;
use App\Domain\Risk\Enums\RiskLevel;
use App\Domain\Risk\Models\RiskAssessment;
use App\Domain\Vendors\Enums\BankScheme;
use App\Domain\Vendors\Models\Vendor;
use App\Domain\Vendors\Models\VendorBankAccount;
use App\Domain\Verification\Models\Verification;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentRequest extends Model
{
    use BelongsToWorkspace;

    protected $table = 'payment_requests';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => PaymentRequestKind::class,
            'source' => PaymentRequestSource::class,
            'status' => PaymentRequestStatus::class,
            'description' => 'encrypted',
            'vendor_id' => 'integer',
            'vendor_match_method' => VendorMatchMethod::class,
            'vendor_match_confidence' => 'integer',
            'amount_minor' => 'integer',
            'amount_base_minor' => 'integer',
            'fx_rate' => 'string',
            'fx_rate_date' => 'immutable_date',
            'invoice_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'payee_scheme' => BankScheme::class,
            'payee_account_holder' => 'encrypted',
            'payee_account_number' => 'encrypted',
            'payee_routing_code' => 'encrypted',
            'payee_correspondent_account' => 'encrypted',
            'payee_checksum_valid' => 'boolean',
            'matched_bank_account_id' => 'integer',
            'risk_assessment_id' => 'integer',
            'risk_score' => 'integer',
            'risk_level' => RiskLevel::class,
            'verification_required' => 'boolean',
            'verification_reasons' => 'array',
            'verification_satisfied_at' => 'datetime',
            'satisfying_verification_id' => 'integer',
            'employee_response' => EmployeeResponse::class,
            'employee_responded_at' => 'datetime',
            'current_route_id' => 'integer',
            'expires_at' => 'datetime',
            'requested_by' => 'integer',
            'submitted_by' => 'integer',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'decision_note' => 'encrypted',
            'decided_by' => 'integer',
            'paid_at' => 'datetime',
            'paid_by' => 'integer',
            'prevented_category' => PreventedCategory::class,
            'prevented_amount_base_minor' => 'integer',
            'revision' => 'integer',
            'pipeline' => 'array',
            'extraction_failed' => 'boolean',
        ];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsTo<User, $this> */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /** @return BelongsTo<VendorBankAccount, $this> */
    public function matchedBankAccount(): BelongsTo
    {
        return $this->belongsTo(VendorBankAccount::class, 'matched_bank_account_id');
    }

    /** @return BelongsTo<RiskAssessment, $this> */
    public function riskAssessment(): BelongsTo
    {
        return $this->belongsTo(RiskAssessment::class, 'risk_assessment_id');
    }

    /** @return BelongsTo<Verification, $this> */
    public function satisfyingVerification(): BelongsTo
    {
        return $this->belongsTo(Verification::class, 'satisfying_verification_id');
    }

    /** @return BelongsTo<ApprovalRoute, $this> */
    public function currentRoute(): BelongsTo
    {
        return $this->belongsTo(ApprovalRoute::class, 'current_route_id');
    }

    /** @return HasMany<PaymentRequestFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(PaymentRequestFile::class, 'payment_request_id');
    }

    /** @return HasMany<Extraction, $this> */
    public function extractions(): HasMany
    {
        return $this->hasMany(Extraction::class, 'payment_request_id');
    }

    /** @return HasMany<EmailMessage, $this> */
    public function emailMessages(): HasMany
    {
        return $this->hasMany(EmailMessage::class, 'payment_request_id');
    }

    /** @return HasMany<BankComparison, $this> */
    public function bankComparisons(): HasMany
    {
        return $this->hasMany(BankComparison::class, 'payment_request_id');
    }

    /** @return HasMany<RiskAssessment, $this> */
    public function riskAssessments(): HasMany
    {
        return $this->hasMany(RiskAssessment::class, 'payment_request_id');
    }

    /** @return HasMany<ApprovalRoute, $this> */
    public function routes(): HasMany
    {
        return $this->hasMany(ApprovalRoute::class, 'payment_request_id');
    }

    /** @return HasMany<Approval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'payment_request_id');
    }

    /** @return HasMany<Verification, $this> */
    public function verifications(): HasMany
    {
        return $this->hasMany(Verification::class, 'payment_request_id');
    }

    /** @return HasMany<EvidenceEntry, $this> */
    public function evidenceEntries(): HasMany
    {
        return $this->hasMany(EvidenceEntry::class, 'payment_request_id');
    }
}
