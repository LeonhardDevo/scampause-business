<?php

declare(strict_types=1);

namespace App\Domain\Vendors\Models;

use App\Domain\Vendors\Enums\DomainSource;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorDomain extends Model
{
    use BelongsToWorkspace;

    protected $table = 'vendor_domains';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => DomainSource::class,
            'vendor_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
