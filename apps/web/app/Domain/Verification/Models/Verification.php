<?php

declare(strict_types=1);

namespace App\Domain\Verification\Models;

use App\Domain\Evidence\Models\EvidenceEntry;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Vendors\Enums\ContactSource;
use App\Domain\Vendors\Models\Vendor;
use App\Domain\Vendors\Models\VendorBankAccount;
use App\Domain\Vendors\Models\VendorContact;
use App\Domain\Verification\Enums\VerificationMethod;
use App\Domain\Verification\Enums\VerificationOutcome;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Verification extends Model
{
    use BelongsToWorkspace;

    protected $table = 'verifications';

    protected $guarded = [];

    public const ?string UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'method' => VerificationMethod::class,
            'phone_dialed' => 'encrypted',
            'phone_source' => ContactSource::class,
            'spoken_to_name' => 'encrypted',
            'outcome' => VerificationOutcome::class,
            'checklist' => 'array',
            'occurred_at' => 'datetime',
            'notes' => 'encrypted',
            'sod_waived' => 'boolean',
            'payment_request_id' => 'integer',
            'vendor_id' => 'integer',
            'vendor_bank_account_id' => 'integer',
            'contact_id' => 'integer',
            'replaces_account_id' => 'integer',
            'performed_by' => 'integer',
            'evidence_entry_id' => 'integer',
        ];
    }

    /** @return BelongsTo<PaymentRequest, $this> */
    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /** @return BelongsTo<VendorBankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(VendorBankAccount::class, 'vendor_bank_account_id');
    }

    /** @return BelongsTo<VendorContact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(VendorContact::class, 'contact_id');
    }

    /** @return BelongsTo<User, $this> */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /** @return BelongsTo<VendorBankAccount, $this> */
    public function replacesAccount(): BelongsTo
    {
        return $this->belongsTo(VendorBankAccount::class, 'replaces_account_id');
    }

    /** @return BelongsTo<EvidenceEntry, $this> */
    public function evidenceEntry(): BelongsTo
    {
        return $this->belongsTo(EvidenceEntry::class, 'evidence_entry_id');
    }
}
