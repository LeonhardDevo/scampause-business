<?php

declare(strict_types=1);

namespace App\Domain\Verification\Models;

use App\Domain\Intake\Enums\EmployeeResponse;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Vendors\Models\Vendor;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAlert extends Model
{
    use BelongsToWorkspace;

    protected $table = 'employee_alerts';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'answered_at' => 'datetime',
            'response' => EmployeeResponse::class,
            'payment_request_id' => 'integer',
            'vendor_id' => 'integer',
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
}
