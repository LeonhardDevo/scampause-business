<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Models;

use App\Domain\Evidence\Enums\EvidenceChannel;
use App\Domain\Intake\Models\PaymentRequest;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenceEntry extends Model
{
    use BelongsToWorkspace;

    protected $table = 'evidence_entries';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    public const ?string UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'subject_id' => 'integer',
            'actor_user_id' => 'integer',
            'channel' => EvidenceChannel::class,
            'occurred_at' => 'immutable_datetime',
            'payload' => 'array',
            'payment_request_id' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<PaymentRequest, $this> */
    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }
}
