<?php

declare(strict_types=1);

namespace App\Domain\Vendors\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum BankScheme: string
{
    use EnumHelpers;

    case Iban = 'iban';
    case UsAch = 'us_ach';
    case RuAccount = 'ru_account';
    case UkSortCode = 'uk_sort_code';
    case Other = 'other';
}
