<?php

declare(strict_types=1);

namespace App\Domain\Support;

use App\Domain\Support\Normalize\BankNormalizer;
use App\Domain\Vendors\Enums\BankScheme;

final readonly class Fingerprinter
{
    public function __construct(private string $key) {}

    public static function fromConfig(): self
    {
        $configured = config('scampause.fingerprint_key');

        if (is_string($configured) && $configured !== '') {
            $decoded = base64_decode($configured, true);

            if ($decoded !== false && $decoded !== '') {
                return new self($decoded);
            }
        }

        if (app()->isProduction()) {
            throw new \RuntimeException('SCAMPAUSE_FINGERPRINT_KEY must be set in production.');
        }

        return new self(hash_hmac('sha256', 'scampause-fingerprint', (string) config('app.key'), true));
    }

    public function hash(int $workspaceId, string $body): string
    {
        return hash_hmac('sha256', $workspaceId.':'.$body, $this->key);
    }

    public function bankBody(BankScheme $scheme, string $account, ?string $routing = null): string
    {
        return $scheme->value.':'.BankNormalizer::routing($scheme, $routing).':'.BankNormalizer::account($scheme, $account);
    }

    public function bank(int $workspaceId, BankScheme $scheme, string $account, ?string $routing = null): string
    {
        return $this->hash($workspaceId, $this->bankBody($scheme, $account, $routing));
    }

    public function phone(int $workspaceId, string $e164): string
    {
        return $this->hash($workspaceId, 'phone::'.$e164);
    }
}
