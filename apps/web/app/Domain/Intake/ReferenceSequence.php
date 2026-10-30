<?php

declare(strict_types=1);

namespace App\Domain\Intake;

use Illuminate\Support\Facades\DB;

final class ReferenceSequence
{
    public const string PAYMENT_REQUEST = 'payment_request';

    public function next(int $workspaceId, string $name = self::PAYMENT_REQUEST): int
    {
        $row = DB::selectOne(
            'INSERT INTO workspace_sequences (workspace_id, name, value) VALUES (?, ?, 1)
             ON CONFLICT (workspace_id, name) DO UPDATE SET value = workspace_sequences.value + 1
             RETURNING value',
            [$workspaceId, $name],
        );

        return (int) $row->value;
    }

    public function nextReference(int $workspaceId): string
    {
        return self::format($this->next($workspaceId));
    }

    public static function format(int $value): string
    {
        return 'SP-'.str_pad((string) $value, 5, '0', STR_PAD_LEFT);
    }
}
