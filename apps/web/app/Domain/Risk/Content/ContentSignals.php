<?php

declare(strict_types=1);

namespace App\Domain\Risk\Content;

use App\Domain\Risk\SignalHit;

final class ContentSignals
{
    public const string SCOPE_MESSAGE = 'message_body';

    public const string SCOPE_DOCUMENT = 'subject_and_text';

    private const string BOUNDARY_BEFORE = '(?<![\p{L}\p{N}])';

    private const string BOUNDARY_AFTER = '(?![\p{L}\p{N}])';

    /** @var array<string, string> */
    private static array $patterns = [];

    /**
     * @return list<SignalHit>
     */
    public static function detect(?string $messageText, ?string $documentText): array
    {
        $maxChars = (int) config('scampause_lexicon.max_chars', 200000);
        $message = $messageText === null ? '' : self::prepare(self::stripMessage($messageText), $maxChars);
        $document = $documentText === null ? '' : self::prepare($documentText, $maxChars);
        $hits = [];

        foreach (self::categories() as $code => $category) {
            $text = $category['scope'] === self::SCOPE_MESSAGE ? $message : $document;

            if ($text === '') {
                continue;
            }

            $phrases = self::matches($text, $category['phrases']);

            if ($phrases !== []) {
                $hits[] = new SignalHit($code, ['phrases' => $phrases]);
            }
        }

        return $hits;
    }

    /**
     * @param  list<array{text: string, mode: string, locale: string}>  $phrases
     * @return list<string>
     */
    public static function matches(string $text, array $phrases): array
    {
        $found = [];

        foreach ($phrases as $phrase) {
            if (preg_match(self::pattern($phrase['text'], $phrase['mode']), $text, $match) === 1) {
                $value = mb_substr(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $match[0])), 'UTF-8'), 0, 60, 'UTF-8');

                if (! in_array($value, $found, true)) {
                    $found[] = $value;
                }

                if (count($found) === 3) {
                    break;
                }
            }
        }

        return $found;
    }

    public static function pattern(string $phrase, string $mode): string
    {
        $key = $mode.'|'.$phrase;

        if (isset(self::$patterns[$key])) {
            return self::$patterns[$key];
        }

        $words = preg_split('/\s+/u', trim(self::normalizeApostrophes($phrase)), -1, PREG_SPLIT_NO_EMPTY);
        $parts = [];

        foreach ($words === false ? [] : $words as $word) {
            $prefix = str_ends_with($word, '…');
            $stem = $prefix ? mb_substr($word, 0, -1, 'UTF-8') : $word;
            $parts[] = preg_quote($stem, '/').($prefix && $mode === 'prefix' ? '[\p{L}\p{N}]*' : '');
        }

        return self::$patterns[$key] = '/'.self::BOUNDARY_BEFORE.implode('\s+', $parts).self::BOUNDARY_AFTER.'/iu';
    }

    public static function stripMessage(string $text): string
    {
        $lines = preg_split('/\R/u', $text);
        $kept = [];

        foreach ($lines === false ? [] : $lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '--' || $trimmed === '-- '
                || preg_match('/^-{2,}\s*(original message|forwarded message|исходное сообщение|пересылаемое сообщение)/iu', $trimmed) === 1
                || preg_match('/^(on .{3,200} wrote:|.{3,200} (написал|написала)\s?:)$/iu', $trimmed) === 1) {
                break;
            }

            if (str_starts_with($trimmed, '>')) {
                continue;
            }

            $kept[] = $line;
        }

        $body = implode("\n", $kept);
        $paragraphs = preg_split('/\n\s*\n/u', $body);

        $paragraphs = array_filter($paragraphs === false ? [] : $paragraphs, static fn (string $paragraph): bool => ! self::isDisclaimer($paragraph));

        return implode("\n\n", $paragraphs);
    }

    public static function isDisclaimer(string $paragraph): bool
    {
        $lower = mb_strtolower($paragraph, 'UTF-8');

        return preg_match('/(this (e-?mail|message)( and any attachments)?|intended (solely )?(only )?for the (named )?(addressee|recipient)|confidentiality notice|disclaimer|данное (сообщение|письмо).{0,80}конфиденциальн|если вы получили это (сообщение|письмо) по ошибке)/u', $lower) === 1;
    }

    /**
     * @return array<string, array{points: int, scope: string, phrases: list<array{text: string, mode: string, locale: string}>}>
     */
    public static function categories(): array
    {
        /** @var array<string, array{points: int, scope: string, phrases: list<array{text: string, mode: string, locale: string}>}> $categories */
        $categories = config('scampause_lexicon.categories', []);

        return $categories;
    }

    private static function prepare(string $text, int $maxChars): string
    {
        return self::normalizeApostrophes(mb_substr($text, 0, $maxChars, 'UTF-8'));
    }

    private static function normalizeApostrophes(string $text): string
    {
        return str_replace(["\u{2018}", "\u{2019}", "\u{02BC}"], "'", $text);
    }
}
