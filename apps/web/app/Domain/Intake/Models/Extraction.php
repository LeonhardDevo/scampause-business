<?php

declare(strict_types=1);

namespace App\Domain\Intake\Models;

use App\Domain\Intake\Enums\ExtractionStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Extraction extends Model
{
    use BelongsToWorkspace;

    protected $table = 'extractions';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'status' => ExtractionStatus::class,
            'ocr_used' => 'boolean',
            'pages' => 'integer',
            'text' => 'encrypted',
            'fields' => 'encrypted:array',
            'warnings' => 'array',
            'duration_ms' => 'integer',
            'llm_assisted' => 'boolean',
            'payment_request_id' => 'integer',
        ];
    }

    /** @return BelongsTo<PaymentRequest, $this> */
    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }
}
