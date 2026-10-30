<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use Illuminate\Support\Str;

final class StepTokens
{
    /** @return array{token: string, hash: string} */
    public static function issue(): array
    {
        $token = Str::random(8);

        return ['token' => $token, 'hash' => self::hash($token)];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function matches(?string $token, mixed $hash): bool
    {
        return is_string($token) && $token !== '' && is_string($hash) && $hash !== '' && hash_equals($hash, self::hash($token));
    }
}
