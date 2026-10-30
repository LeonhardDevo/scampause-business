<?php

declare(strict_types=1);

namespace App\Domain\Vendors\Bank;

use App\Domain\Vendors\Enums\BankAccountStatus;
use App\Domain\Vendors\Enums\BankScheme;
use Carbon\CarbonImmutable;

final readonly class AccountSnapshot
{
    public function __construct(
        public int $id,
        public string $fingerprint,
        public BankAccountStatus $status,
        public BankScheme $scheme,
        public string $accountNumber,
        public ?string $routingCode,
        public ?string $bic,
        public ?string $bankCountry,
        public string $last4,
        public ?CarbonImmutable $validFrom,
    ) {}
}
