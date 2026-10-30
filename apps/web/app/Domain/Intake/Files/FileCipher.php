<?php

declare(strict_types=1);

namespace App\Domain\Intake\Files;

final readonly class FileCipher
{
    private const string MAGIC = 'SPF1';

    private const string CIPHER = 'aes-256-gcm';

    private const int NONCE_BYTES = 12;

    private const int TAG_BYTES = 16;

    /**
     * @param  array<string, string>  $keys
     */
    public function __construct(private string $currentKeyId, private array $keys) {}

    public static function fromConfig(): self
    {
        $keyId = (string) config('scampause.file_key_id', 'k1');
        $configured = config('scampause.file_key');
        $keys = [];

        if (is_string($configured) && $configured !== '') {
            $decoded = base64_decode($configured, true);

            if ($decoded === false || strlen($decoded) !== 32) {
                throw new \RuntimeException('SCAMPAUSE_FILE_KEY must be 32 bytes, base64-encoded.');
            }

            $keys[$keyId] = $decoded;
        } elseif (app()->isProduction()) {
            throw new \RuntimeException('SCAMPAUSE_FILE_KEY must be set in production.');
        } else {
            $keys[$keyId] = hash_hmac('sha256', 'scampause-file-key', (string) config('app.key'), true);
        }

        foreach ((array) config('scampause.previous_file_keys', []) as $entry) {
            if (! is_string($entry) || ! str_contains($entry, ':')) {
                continue;
            }

            [$id, $encoded] = explode(':', $entry, 2);
            $decoded = base64_decode($encoded, true);

            if ($decoded !== false && strlen($decoded) === 32 && ! isset($keys[$id])) {
                $keys[$id] = $decoded;
            }
        }

        return new self($keyId, $keys);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $key = $this->keys[$this->currentKeyId];
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, self::header($this->currentKeyId), self::TAG_BYTES);

        if ($ciphertext === false) {
            throw new \RuntimeException('File encryption failed.');
        }

        return self::header($this->currentKeyId).$nonce.$tag.$ciphertext;
    }

    public function decrypt(string $payload): string
    {
        if (! str_starts_with($payload, self::MAGIC) || strlen($payload) < 6) {
            throw new \RuntimeException('Unknown file envelope.');
        }

        $idLength = ord($payload[4]);
        $keyId = substr($payload, 5, $idLength);
        $key = $this->keys[$keyId] ?? null;

        if ($key === null) {
            throw new \RuntimeException('Unknown file key.');
        }

        $offset = 5 + $idLength;
        $nonce = substr($payload, $offset, self::NONCE_BYTES);
        $tag = substr($payload, $offset + self::NONCE_BYTES, self::TAG_BYTES);
        $ciphertext = substr($payload, $offset + self::NONCE_BYTES + self::TAG_BYTES);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, self::header($keyId));

        if ($plaintext === false) {
            throw new \RuntimeException('File decryption failed.');
        }

        return $plaintext;
    }

    private static function header(string $keyId): string
    {
        return self::MAGIC.chr(strlen($keyId)).$keyId;
    }
}
