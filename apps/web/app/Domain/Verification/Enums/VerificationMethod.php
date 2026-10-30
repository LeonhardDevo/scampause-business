<?php

declare(strict_types=1);

namespace App\Domain\Verification\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum VerificationMethod: string
{
    use EnumHelpers;

    case CallbackKnownNumber = 'callback_known_number';
    case InPerson = 'in_person';
    case VideoCallKnownContact = 'video_call_known_contact';
    case SignedLetterAndCallback = 'signed_letter_and_callback';
    case VendorPortal = 'vendor_portal';
}
