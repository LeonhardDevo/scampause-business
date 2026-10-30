<?php

declare(strict_types=1);

namespace App\Domain\Vendors\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum DomainSource: string
{
    use EnumHelpers;

    case Contract = 'contract';
    case Manual = 'manual';
    case VerifiedCorrespondence = 'verified_correspondence';
}
