<?php

declare(strict_types=1);

namespace App\Domain\Reports\Models;

use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Enums\ExportKind;
use App\Domain\Reports\Enums\ExportStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportExport extends Model
{
    use BelongsToWorkspace;

    protected $table = 'report_exports';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => ExportKind::class,
            'format' => ExportFormat::class,
            'status' => ExportStatus::class,
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'size_bytes' => 'integer',
            'chain_verified' => 'boolean',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'payment_request_id' => 'integer',
        ];
    }

    /** @return BelongsTo<PaymentRequest, $this> */
    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
