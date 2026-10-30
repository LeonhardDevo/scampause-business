<?php

declare(strict_types=1);

namespace App\Domain\Risk\Models;

use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Risk\Enums\SignalCategory;
use App\Domain\Risk\Enums\SignalSeverity;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskSignal extends Model
{
    use BelongsToWorkspace;

    protected $table = 'risk_signals';

    protected $guarded = [];

    public const ?string UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => SignalCategory::class,
            'severity' => SignalSeverity::class,
            'points' => 'integer',
            'evidence' => 'array',
            'risk_assessment_id' => 'integer',
            'payment_request_id' => 'integer',
        ];
    }

    /** @return BelongsTo<RiskAssessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(RiskAssessment::class, 'risk_assessment_id');
    }

    /** @return BelongsTo<PaymentRequest, $this> */
    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }
}
