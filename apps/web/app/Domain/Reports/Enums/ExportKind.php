<?php

declare(strict_types=1);

namespace App\Domain\Reports\Enums;

use App\Domain\Support\Concerns\EnumHelpers;

enum ExportKind: string
{
    use EnumHelpers;

    case EvidencePacket = 'evidence_packet';
    case AuditJournal = 'audit_journal';
    case PreventedRisk = 'prevented_risk';
}
