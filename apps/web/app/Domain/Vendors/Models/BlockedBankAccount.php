<?php

declare(strict_types=1);

namespace App\Domain\Vendors\Models;

use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Vendors\Enums\BankScheme;
use App\Domain\Vendors\Enums\BlockReason;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BlockedBankAccount extends Model
{
    use BelongsToWorkspace, SoftDeletes;

    protected $table = 'blocked_bank_accounts';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scheme' => BankScheme::class,
            'reason' => BlockReason::class,
            'note' => 'encrypted',
            'created_by' => 'integer',
            'source_payment_request_id' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function unblocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unblocked_by');
    }

    /** @return BelongsTo<PaymentRequest, $this> */
    public function sourcePaymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'source_payment_request_id');
    }
}
