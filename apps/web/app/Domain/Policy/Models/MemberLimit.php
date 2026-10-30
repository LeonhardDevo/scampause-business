<?php

declare(strict_types=1);

namespace App\Domain\Policy\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberLimit extends Model
{
    use BelongsToWorkspace;

    protected $table = 'member_limits';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'approval_limit_base_minor' => 'integer',
            'monthly_limit_base_minor' => 'integer',
            'user_id' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
