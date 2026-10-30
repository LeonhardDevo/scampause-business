<?php

declare(strict_types=1);

namespace Database\Seeders\Domain\Support;

use App\Domain\Intake\ReferenceSequence;
use Illuminate\Support\Facades\DB;

final class ReferenceAllocator
{
    public static function reserve(int $workspaceId, int $number): void
    {
        if (DB::table('payment_requests')->where('workspace_id', $workspaceId)->where('reference', ReferenceSequence::format($number))->exists()) {
            throw new \LogicException('Reference '.ReferenceSequence::format($number).' is already taken.');
        }

        self::set($workspaceId, $number - 1);
    }

    public static function finish(int $workspaceId): void
    {
        $max = 0;

        foreach (DB::table('payment_requests')->where('workspace_id', $workspaceId)->pluck('reference') as $reference) {
            $max = max($max, (int) substr((string) $reference, 3));
        }

        self::set($workspaceId, $max);
    }

    private static function set(int $workspaceId, int $value): void
    {
        DB::table('workspace_sequences')->upsert(
            [['workspace_id' => $workspaceId, 'name' => ReferenceSequence::PAYMENT_REQUEST, 'value' => $value]],
            ['workspace_id', 'name'],
            ['value'],
        );
    }
}
