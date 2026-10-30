<?php

declare(strict_types=1);

namespace App\Domain\Support\Normalize;

final class EmailNormalizer
{
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = mb_strtolower(trim($raw), 'UTF-8');

        if (preg_match('/<([^<>\s]+@[^<>\s]+)>/', $value, $match) === 1) {
            $value = $match[1];
        }

        if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/u', $value) !== 1) {
            return null;
        }

        return substr($value, 0, 254);
    }

    public static function domain(?string $raw): ?string
    {
        return DomainNormalizer::fromEmail(self::normalize($raw));
    }
}
