<?php

declare(strict_types=1);

namespace App\Domain\Policy\Models;

use App\Domain\Risk\Enums\RiskLevel;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApprovalRule extends Model
{
    use BelongsToWorkspace, SoftDeletes;

    protected $table = 'approval_rules';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'version' => 'integer',
            'position' => 'integer',
            'min_amount_base_minor' => 'integer',
            'max_amount_base_minor' => 'integer',
            'kinds' => 'array',
            'min_risk_level' => RiskLevel::class,
            'applies_to_new_vendor' => 'boolean',
            'applies_to_bank_change' => 'boolean',
            'approvals_required' => 'integer',
            'required_roles' => 'array',
            'approver_roles' => 'array',
            'verification_required' => 'boolean',
            'expiry_hours' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
