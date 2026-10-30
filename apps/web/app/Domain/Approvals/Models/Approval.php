<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Models;

use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Enums\DecisionChannel;
use App\Domain\Intake\Enums\DecisionReason;
use App\Domain\Intake\Models\PaymentRequest;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
    use BelongsToWorkspace;

    protected $table = 'approvals';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'status' => ApprovalStatus::class,
            'channel' => DecisionChannel::class,
            'decided_at' => 'datetime',
            'reject_reason' => DecisionReason::class,
            'comment' => 'encrypted',
            'reminders_sent' => 'integer',
            'last_reminded_at' => 'datetime',
            'telegram_message' => 'array',
            'approval_route_id' => 'integer',
            'payment_request_id' => 'integer',
            'approver_id' => 'integer',
            'replaced_approval_id' => 'integer',
        ];
    }

    /** @return BelongsTo<ApprovalRoute, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(ApprovalRoute::class, 'approval_route_id');
    }

    /** @return BelongsTo<PaymentRequest, $this> */
    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /** @return BelongsTo<Approval, $this> */
    public function replacedApproval(): BelongsTo
    {
        return $this->belongsTo(Approval::class, 'replaced_approval_id');
    }
}
