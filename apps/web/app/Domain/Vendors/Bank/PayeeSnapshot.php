<?php

declare(strict_types=1);

namespace App\Domain\Vendors\Bank;

use App\Domain\Vendors\Enums\BankScheme;

final readonly class PayeeSnapshot
{
    public function __construct(
        public string $fingerprint,
        public BankScheme $scheme,
        public string $accountNumber,
        public ?string $routingCode,
        public ?string $bic,
        public ?string $bankCountry,
        public ?string $holder,
        public string $last4,
    ) {}
}
