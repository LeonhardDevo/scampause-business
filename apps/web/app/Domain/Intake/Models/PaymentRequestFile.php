<?php

declare(strict_types=1);

namespace App\Domain\Intake\Models;

use App\Domain\Intake\Enums\FileRole;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRequestFile extends Model
{
    use BelongsToWorkspace;

    protected $table = 'payment_request_files';

    protected $guarded = [];

    public const ?string UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => FileRole::class,
            'size_bytes' => 'integer',
            'pages' => 'integer',
            'page' => 'integer',
            'purged_at' => 'datetime',
            'payment_request_id' => 'integer',
            'parent_file_id' => 'integer',
            'uploaded_by' => 'integer',
        ];
    }

    /** @return BelongsTo<PaymentRequest, $this> */
    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }

    /** @return BelongsTo<PaymentRequestFile, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(PaymentRequestFile::class, 'parent_file_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
