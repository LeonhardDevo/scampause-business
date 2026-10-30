<?php

declare(strict_types=1);

namespace App\Domain\Intake\Files;

use App\Domain\Intake\Models\PaymentRequestFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final readonly class FileVault
{
    public function __construct(private FileCipher $cipher) {}

    public static function disk(): string
    {
        return (string) config('scampause.disk', 'local');
    }

    /** @return array{disk: string, path: string, sha256: string, size_bytes: int} */
    public function store(int $workspaceId, int $paymentRequestId, string $plaintext, string $extension): array
    {
        $extension = preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1 ? $extension : 'bin';
        $path = sprintf('scampause/%d/%d/%s.%s', $workspaceId, $paymentRequestId, (string) Str::uuid(), $extension);
        Storage::disk(self::disk())->put($path, $this->cipher->encrypt($plaintext));

        return [
            'disk' => self::disk(),
            'path' => $path,
            'sha256' => hash('sha256', $plaintext),
            'size_bytes' => strlen($plaintext),
        ];
    }

    public function read(PaymentRequestFile $file): ?string
    {
        if ($file->getAttribute('purged_at') !== null) {
            return null;
        }

        $disk = Storage::disk((string) $file->getAttribute('disk'));
        $path = (string) $file->getAttribute('path');

        if (! $disk->exists($path)) {
            return null;
        }

        return $this->cipher->decrypt((string) $disk->get($path));
    }

    public function delete(PaymentRequestFile $file): void
    {
        Storage::disk((string) $file->getAttribute('disk'))->delete((string) $file->getAttribute('path'));
    }

    public static function sanitizeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u', '_', $name);
        $name = trim($name, " .\t");

        return $name === '' ? 'file' : mb_substr($name, 0, 255, 'UTF-8');
    }
}
