<?php

declare(strict_types=1);

namespace App\Domain\Support\Normalize;

final class TaxId
{
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = (string) preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($raw)));

        return $value === '' ? null : substr($value, 0, 32);
    }
}
