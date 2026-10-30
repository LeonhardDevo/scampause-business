<?php

declare(strict_types=1);

namespace App\Domain\Vendors\Models;

use App\Domain\Vendors\Enums\ContactSource;
use App\Domain\Vendors\Enums\ContactStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorContact extends Model
{
    use BelongsToWorkspace, SoftDeletes;

    protected $table = 'vendor_contacts';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'phone_e164' => 'encrypted',
            'is_callback' => 'boolean',
            'status' => ContactStatus::class,
            'source' => ContactSource::class,
            'phone_changed_at' => 'datetime',
            'verified_at' => 'datetime',
            'vendor_id' => 'integer',
            'created_by' => 'integer',
            'verified_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
