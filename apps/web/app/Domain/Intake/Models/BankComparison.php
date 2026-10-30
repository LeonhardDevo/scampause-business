<?php

declare(strict_types=1);

namespace App\Domain\Intake\Models;

use App\Domain\Intake\Enums\BankComparisonOutcome;
use App\Domain\Vendors\Models\VendorBankAccount;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankComparison extends Model
{
    use BelongsToWorkspace;

    protected $table = 'bank_comparisons';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'outcome' => BankComparisonOutcome::class,
            'details' => 'array',
            'payment_request_id' => 'integer',
            'previous_account_id' => 'integer',
        ];
    }

    /** @return BelongsTo<PaymentRequest, $this> */
    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }

    /** @return BelongsTo<VendorBankAccount, $this> */
    public function previousAccount(): BelongsTo
    {
        return $this->belongsTo(VendorBankAccount::class, 'previous_account_id');
    }
}
