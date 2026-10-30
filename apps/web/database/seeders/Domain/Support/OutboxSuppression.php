<?php

declare(strict_types=1);

namespace Database\Seeders\Domain\Support;

use App\Models\OutboxMessage;
use Closure;

final class OutboxSuppression
{
    public const array DEFAULT_PATTERNS = ['intake.*', 'approval.*', 'audit.*', 'email.send', 'telegram.notify'];

    /** @var list<string> */
    private static array $patterns = [];

    private static ?object $dispatcher = null;

    private static int $suppressed = 0;

    /**
     * @template T
     *
     * @param  list<string>  $patterns
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(array $patterns, Closure $callback): mixed
    {
        self::register();
        $previous = self::$patterns;
        self::$patterns = $patterns;

        try {
            return $callback();
        } finally {
            self::$patterns = $previous;
        }
    }

    public static function matches(string $topic): bool
    {
        foreach (self::$patterns as $pattern) {
            if ($pattern === $topic || (str_ends_with($pattern, '.*') && str_starts_with($topic, substr($pattern, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    public static function suppressedCount(): int
    {
        return self::$suppressed;
    }

    private static function register(): void
    {
        $dispatcher = OutboxMessage::getEventDispatcher();

        if ($dispatcher === null || self::$dispatcher === $dispatcher) {
            return;
        }

        self::$dispatcher = $dispatcher;
        OutboxMessage::creating(static function (OutboxMessage $message): ?bool {
            if (self::matches((string) $message->topic)) {
                self::$suppressed++;

                return false;
            }

            return null;
        });
    }
}
