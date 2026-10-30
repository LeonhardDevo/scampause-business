<?php

declare(strict_types=1);

namespace App\Domain\Intake\Data;

final readonly class ExtractedField
{
    public const array SOURCES = ['text', 'ocr', 'qr', 'eml_header', 'llm_assist', 'manual'];

    public function __construct(
        public string|int|null $value,
        public int $confidence,
        public string $source,
        public ?int $page = null,
    ) {}

    public static function from(mixed $raw): ?self
    {
        if (! is_array($raw) || ! array_key_exists('value', $raw)) {
            return null;
        }

        $value = $raw['value'];

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return null;
            }
        } elseif (! is_int($value)) {
            return null;
        }

        $source = is_string($raw['source'] ?? null) && in_array($raw['source'], self::SOURCES, true) ? $raw['source'] : 'text';
        $confidence = max(0, min(100, (int) ($raw['confidence'] ?? 0)));

        if ($source === 'llm_assist') {
            $confidence = min($confidence, 60);
        }

        return new self($value, $confidence, $source, isset($raw['page']) && is_int($raw['page']) ? $raw['page'] : null);
    }

    public function string(): string
    {
        return (string) $this->value;
    }

    public function isLlmAssisted(): bool
    {
        return $this->source === 'llm_assist';
    }

    /** @return array{value: string|int|null, confidence: int, source: string, page: int|null} */
    public function toArray(): array
    {
        return ['value' => $this->value, 'confidence' => $this->confidence, 'source' => $this->source, 'page' => $this->page];
    }
}
