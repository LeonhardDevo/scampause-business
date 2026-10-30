<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Domain\Approvals\Models\Approval;
use App\Domain\Intake\Models\PaymentRequest;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpNotification extends Model
{
    use BelongsToWorkspace;

    protected $table = 'sp_notifications';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payment_request_id' => 'integer',
            'approval_id' => 'integer',
            'recipient_user_id' => 'integer',
            'recipient_vendor_contact_id' => 'integer',
        ];
    }

    /** @return BelongsTo<PaymentRequest, $this> */
    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }

    /** @return BelongsTo<Approval, $this> */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class, 'approval_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
