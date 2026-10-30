<?php

declare(strict_types=1);

namespace App\Domain\Intake\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

class DomainIntelSighting extends Model
{
    use BelongsToWorkspace;

    protected $table = 'domain_intel_sightings';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'first_checked_at' => 'datetime',
        ];
    }
}
