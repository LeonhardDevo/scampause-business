<?php

declare(strict_types=1);

namespace App\Domain\Vendors\Models;

use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Vendors\Enums\BankAccountSource;
use App\Domain\Vendors\Enums\BankAccountStatus;
use App\Domain\Vendors\Enums\BankScheme;
use App\Domain\Vendors\Enums\RetiredReason;
use App\Domain\Verification\Models\Verification;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorBankAccount extends Model
{
    use BelongsToWorkspace;

    protected $table = 'vendor_bank_accounts';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scheme' => BankScheme::class,
            'account_holder' => 'encrypted',
            'account_number' => 'encrypted',
            'routing_code' => 'encrypted',
            'correspondent_account' => 'encrypted',
            'status' => BankAccountStatus::class,
            'source' => BankAccountSource::class,
            'retired_reason' => RetiredReason::class,
            'verified_at' => 'datetime',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'sod_waived' => 'boolean',
            'vendor_id' => 'integer',
            'verification_id' => 'integer',
            'verified_by' => 'integer',
            'created_by' => 'integer',
            'source_payment_request_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /** @return BelongsTo<Verification, $this> */
    public function verification(): BelongsTo
    {
        return $this->belongsTo(Verification::class, 'verification_id');
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<PaymentRequest, $this> */
    public function sourcePaymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'source_payment_request_id');
    }
}
