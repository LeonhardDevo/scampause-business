<?php

declare(strict_types=1);

namespace App\Domain\Risk\Models;

use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Risk\Enums\AssessmentTrigger;
use App\Domain\Risk\Enums\RiskLevel;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiskAssessment extends Model
{
    use BelongsToWorkspace;

    protected $table = 'risk_assessments';

    protected $guarded = [];

    public const ?string UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'score' => 'integer',
            'level' => RiskLevel::class,
            'raw_points' => 'integer',
            'verification_required' => 'boolean',
            'trigger' => AssessmentTrigger::class,
            'payment_request_id' => 'integer',
        ];
    }

    /** @return BelongsTo<PaymentRequest, $this> */
    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }

    /** @return HasMany<RiskSignal, $this> */
    public function signals(): HasMany
    {
        return $this->hasMany(RiskSignal::class, 'risk_assessment_id');
    }
}
