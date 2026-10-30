<?php

declare(strict_types=1);

namespace App\Domain\Policy\Models;

use App\Domain\Risk\Enums\RiskLevel;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspacePaymentSettings extends Model
{
    protected $table = 'workspace_payment_settings';

    protected $guarded = [];

    protected $primaryKey = 'workspace_id';

    public $incrementing = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'own_domains' => 'array',
            'business_days' => 'array',
            'reminder_offsets_hours' => 'array',
            'default_expiry_hours' => 'integer',
            'escalate_after_hours' => 'integer',
            'telegram_approvals_enabled' => 'boolean',
            'telegram_approve_max_risk' => RiskLevel::class,
            'verification_amount_threshold_minor' => 'integer',
            'verification_valid_days' => 'integer',
            'solo_mode_acknowledged_at' => 'datetime',
            'callback_cooldown_days' => 'integer',
            'ai_extract_assist' => 'boolean',
            'new_approver_grace_hours' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }
}
