<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Models;

use App\Domain\Approvals\Enums\RouteStatus;
use App\Domain\Intake\Models\PaymentRequest;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalRoute extends Model
{
    use BelongsToWorkspace;

    protected $table = 'approval_routes';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'status' => RouteStatus::class,
            'approvals_required' => 'integer',
            'required_roles' => 'array',
            'approver_roles' => 'array',
            'rule_snapshot' => 'array',
            'solo_mode' => 'boolean',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'escalated_at' => 'datetime',
            'closed_at' => 'datetime',
            'payment_request_id' => 'integer',
        ];
    }

    /** @return BelongsTo<PaymentRequest, $this> */
    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }

    /** @return HasMany<Approval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'approval_route_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
