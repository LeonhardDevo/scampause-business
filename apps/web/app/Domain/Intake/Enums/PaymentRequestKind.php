<?php

declare(strict_types=1);

namespace App\Domain\Intake\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum PaymentRequestKind: string
{
    use EnumHelpers;

    case Invoice = 'invoice';
    case BankDetailsChange = 'bank_details_change';
    case UrgentWire = 'urgent_wire';
    case PayrollChange = 'payroll_change';
    case QrPayment = 'qr_payment';
    case Other = 'other';
}
