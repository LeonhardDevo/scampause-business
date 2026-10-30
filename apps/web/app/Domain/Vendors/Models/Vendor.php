<?php

declare(strict_types=1);

namespace App\Domain\Vendors\Models;

use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Vendors\Enums\VendorKind;
use App\Domain\Vendors\Enums\VendorStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
    use BelongsToWorkspace, SoftDeletes;

    protected $table = 'vendors';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => VendorKind::class,
            'status' => VendorStatus::class,
            'aliases' => 'array',
            'notes' => 'encrypted',
            'payment_terms_days' => 'integer',
            'first_paid_at' => 'datetime',
            'last_paid_at' => 'datetime',
            'linked_user_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /** @return HasMany<VendorDomain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(VendorDomain::class, 'vendor_id');
    }

    /** @return HasMany<VendorContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(VendorContact::class, 'vendor_id');
    }

    /** @return HasMany<VendorBankAccount, $this> */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(VendorBankAccount::class, 'vendor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function linkedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<PaymentRequest, $this> */
    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class, 'vendor_id');
    }
}
