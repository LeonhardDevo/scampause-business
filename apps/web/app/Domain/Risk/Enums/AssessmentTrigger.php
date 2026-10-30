<?php

declare(strict_types=1);

namespace App\Domain\Risk\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum AssessmentTrigger: string
{
    use EnumHelpers;

    case Pipeline = 'pipeline';
    case ManualEdit = 'manual_edit';
    case Submit = 'submit';
    case Reopen = 'reopen';
    case EmployeeResponse = 'employee_response';
    case MasterDataChanged = 'master_data_changed';
}
