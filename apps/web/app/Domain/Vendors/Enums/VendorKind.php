<?php

declare(strict_types=1);

namespace App\Domain\Vendors\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum VendorKind: string
{
    use EnumHelpers;

    case Supplier = 'supplier';
    case Contractor = 'contractor';
    case Employee = 'employee';
    case Government = 'government';
    case Other = 'other';
}
