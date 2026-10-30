<?php

declare(strict_types=1);

namespace Database\Factories\Domain;

use App\Domain\Vendors\Enums\VendorKind;
use App\Domain\Vendors\Enums\VendorStatus;
use App\Domain\Vendors\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => VendorKind::Supplier,
            'name' => fake()->unique()->company(),
            'aliases' => [],
            'status' => VendorStatus::Active,
        ];
    }
}
