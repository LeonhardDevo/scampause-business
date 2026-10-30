<?php

declare(strict_types=1);

namespace App\Domain\Vendors\Matching;

use App\Domain\Intake\Enums\VendorMatchMethod;

final readonly class VendorMatch
{
    public const string WARNING_AMBIGUOUS = 'VENDOR_AMBIGUOUS';

    public const string WARNING_NOT_FOUND = 'VENDOR_NOT_FOUND';

    /**
     * @param  list<VendorCandidate>  $candidates
     */
    public function __construct(
        public ?int $vendorId,
        public ?VendorMatchMethod $method,
        public ?int $confidence,
        public ?string $warning,
        public array $candidates,
    ) {}
}
