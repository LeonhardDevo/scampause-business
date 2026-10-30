<?php

declare(strict_types=1);

namespace App\Domain\Intake\Data;

use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Vendors\Enums\BankScheme;
use Carbon\CarbonImmutable;

final readonly class ManualEntry
{
    /**
     * @param  list<string>  $provided
     */
    public function __construct(
        public array $provided,
        public ?string $title = null,
        public ?PaymentRequestKind $kind = null,
        public ?int $vendorId = null,
        public ?int $amountMinor = null,
        public ?string $currency = null,
        public ?string $invoiceNumber = null,
        public ?CarbonImmutable $invoiceDate = null,
        public ?CarbonImmutable $dueDate = null,
        public ?BankScheme $payeeScheme = null,
        public ?string $payeeAccountNumber = null,
        public ?string $payeeRoutingCode = null,
        public ?string $payeeBic = null,
        public ?string $payeeAccountHolder = null,
        public ?string $payeeBankName = null,
        public ?string $payeeBankCountry = null,
        public ?string $payeeCorrespondentAccount = null,
        public ?string $senderEmail = null,
        public ?string $description = null,
    ) {}

    public function has(string $field): bool
    {
        return in_array($field, $this->provided, true);
    }
}
