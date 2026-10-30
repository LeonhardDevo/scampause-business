<?php

declare(strict_types=1);

namespace App\Domain\Intake\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum VendorMatchMethod: string
{
    use EnumHelpers;

    case TaxId = 'tax_id';
    case SenderDomain = 'sender_domain';
    case Name = 'name';
    case BankFingerprint = 'bank_fingerprint';
    case Manual = 'manual';
}
