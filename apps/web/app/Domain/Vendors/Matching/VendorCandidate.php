<?php

declare(strict_types=1);

namespace App\Domain\Vendors\Matching;

use App\Domain\Intake\Enums\VendorMatchMethod;

final readonly class VendorCandidate
{
    public function __construct(
        public int $vendorId,
        public VendorMatchMethod $method,
        public int $confidence,
    ) {}

    public static function methodRank(VendorMatchMethod $method): int
    {
        return match ($method) {
            VendorMatchMethod::Manual => 5,
            VendorMatchMethod::TaxId => 4,
            VendorMatchMethod::SenderDomain => 3,
            VendorMatchMethod::Name => 2,
            VendorMatchMethod::BankFingerprint => 1,
        };
    }

    public function beats(self $other): bool
    {
        if ($this->confidence !== $other->confidence) {
            return $this->confidence > $other->confidence;
        }

        return self::methodRank($this->method) > self::methodRank($other->method);
    }
}
