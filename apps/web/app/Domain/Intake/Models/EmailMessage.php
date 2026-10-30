<?php

declare(strict_types=1);

namespace App\Domain\Intake\Models;

use App\Domain\Intake\Enums\AuthResult;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailMessage extends Model
{
    use BelongsToWorkspace;

    protected $table = 'email_messages';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'to_emails' => 'array',
            'sent_at' => 'datetime',
            'auth_spf' => AuthResult::class,
            'auth_dkim' => AuthResult::class,
            'auth_dmarc' => AuthResult::class,
            'received_hops' => 'integer',
            'attachments_count' => 'integer',
            'payment_request_id' => 'integer',
            'file_id' => 'integer',
        ];
    }

    /** @return BelongsTo<PaymentRequest, $this> */
    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }

    /** @return BelongsTo<PaymentRequestFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(PaymentRequestFile::class, 'file_id');
    }
}
