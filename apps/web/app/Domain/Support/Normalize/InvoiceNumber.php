<?php

declare(strict_types=1);

namespace App\Domain\Support\Normalize;

final class InvoiceNumber
{
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim($raw), 'UTF-8'));

        return $value === '' ? null : substr($value, 0, 64);
    }
}
