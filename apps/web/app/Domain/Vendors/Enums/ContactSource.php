<?php

declare(strict_types=1);

namespace App\Domain\Vendors\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum ContactSource: string
{
    use EnumHelpers;

    case Contract = 'contract';
    case WebsiteVerified = 'website_verified';
    case InPerson = 'in_person';
    case OnboardingForm = 'onboarding_form';
    case InvoiceEmail = 'invoice_email';
}
