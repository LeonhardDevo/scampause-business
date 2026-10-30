<?php

declare(strict_types=1);

namespace App\Domain\Intake\Models;

use Illuminate\Database\Eloquent\Model;

class DomainIntel extends Model
{
    protected $table = 'domain_intel';

    protected $guarded = [];

    protected $primaryKey = 'domain';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'has_mx' => 'boolean',
            'has_spf' => 'boolean',
            'registered_at' => 'immutable_date',
            'checked_at' => 'datetime',
            'fresh_until' => 'datetime',
        ];
    }
}
