<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Models;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenceHead extends Model
{
    protected $table = 'evidence_heads';

    protected $guarded = [];

    protected $primaryKey = 'workspace_id';

    public $incrementing = false;

    public const ?string CREATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_seq' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }
}
