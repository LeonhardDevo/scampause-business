<?php

declare(strict_types=1);

namespace App\Domain\Intake\Files;

use finfo;

final class UploadInspector
{
    public const string ERROR_MIME = 'mime_not_allowed';

    public const string ERROR_PDF = 'pdf_js_or_encrypted';

    public const string EML = 'message/rfc822';

    private const array EXTENSIONS = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'eml' => self::EML,
    ];

    /** @return list<string> */
    public static function acceptList(): array
    {
        return array_map(static fn (string $extension): string => '.'.$extension, array_keys(self::EXTENSIONS));
    }

    public static function extensionFor(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            self::EML => 'eml',
            default => 'bin',
        };
    }

    /** @return array{mime: string|null, error: string|null} */
    public function inspect(string $bytes, string $originalName): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $sniffed = strtolower((string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes));

        if ($sniffed === 'application/pdf') {
            return self::isUnsafePdf($bytes)
                ? ['mime' => null, 'error' => self::ERROR_PDF]
                : ['mime' => 'application/pdf', 'error' => null];
        }

        if (in_array($sniffed, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return ['mime' => $sniffed, 'error' => null];
        }

        if ($extension === 'eml' && ($sniffed === self::EML || $sniffed === 'text/plain' || $sniffed === 'text/html') && self::looksLikeEml($bytes)) {
            return ['mime' => self::EML, 'error' => null];
        }

        if ($sniffed === self::EML && self::looksLikeEml($bytes)) {
            return ['mime' => self::EML, 'error' => null];
        }

        return ['mime' => null, 'error' => self::ERROR_MIME];
    }

    public static function allowsExtension(string $originalName): bool
    {
        return array_key_exists(strtolower(pathinfo($originalName, PATHINFO_EXTENSION)), self::EXTENSIONS);
    }

    public static function looksLikeEml(string $bytes): bool
    {
        $head = substr($bytes, 0, 4096);

        return preg_match('/^From:/mi', $head) === 1 && preg_match('/^Subject:/mi', $head) === 1;
    }

    public static function isUnsafePdf(string $bytes): bool
    {
        return preg_match('#/(JavaScript|JS|Encrypt)\b#', $bytes) === 1;
    }
}
