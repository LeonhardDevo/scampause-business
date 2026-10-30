<?php

declare(strict_types=1);

namespace App\Domain\Intake\Data;

use App\Domain\Intake\Enums\AuthResult;

final readonly class ExtractedSender
{
    /**
     * @param  list<string>  $to
     */
    public function __construct(
        public ?string $fromName,
        public ?string $fromEmail,
        public ?string $replyTo,
        public ?string $returnPath,
        public array $to,
        public ?string $subject,
        public ?string $sentAt,
        public ?string $messageId,
        public ?AuthResult $spf,
        public ?AuthResult $dkim,
        public ?AuthResult $dmarc,
        public int $receivedHops,
        public ?string $bodyText,
    ) {}

    public static function from(mixed $raw): ?self
    {
        if (! is_array($raw)) {
            return null;
        }

        $auth = is_array($raw['auth'] ?? null) ? $raw['auth'] : [];
        $to = array_values(array_filter(is_array($raw['to'] ?? null) ? $raw['to'] : [], 'is_string'));

        return new self(
            self::str($raw['from_name'] ?? null, 255),
            self::str($raw['from_email'] ?? null, 254),
            self::str($raw['reply_to'] ?? null, 254),
            self::str($raw['return_path'] ?? null, 254),
            array_slice($to, 0, 50),
            self::str($raw['subject'] ?? null, 998),
            self::str($raw['sent_at'] ?? null, 64),
            self::str($raw['message_id'] ?? null, 998),
            AuthResult::tryFrom((string) ($auth['spf'] ?? '')),
            AuthResult::tryFrom((string) ($auth['dkim'] ?? '')),
            AuthResult::tryFrom((string) ($auth['dmarc'] ?? '')),
            max(0, min(32767, (int) ($raw['received_hops'] ?? 0))),
            self::str($raw['body_text'] ?? null, 200000),
        );
    }

    private static function str(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max, 'UTF-8');
    }
}
