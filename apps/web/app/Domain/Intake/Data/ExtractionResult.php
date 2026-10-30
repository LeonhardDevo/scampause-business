<?php

declare(strict_types=1);

namespace App\Domain\Intake\Data;

use App\Domain\Intake\Enums\ExtractionStatus;

final readonly class ExtractionResult
{
    /**
     * @param  array<string, mixed>  $rawFields
     * @param  list<string>  $warnings
     * @param  list<array{file_id: int, pages: int, ocr_used: bool}>  $files
     */
    public function __construct(
        public int $workspaceId,
        public int $paymentRequestId,
        public int $revision,
        public ExtractionStatus $status,
        public string $extractorVersion,
        public bool $ocrUsed,
        public int $pages,
        public string $text,
        public ExtractedFields $fields,
        public array $rawFields,
        public array $warnings,
        public ?string $errorCode,
        public int $durationMs,
        public bool $llmAssisted,
        public array $files,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $rawFields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        $maxChars = (int) config('scampause.intake.extraction_text_max_chars', 200000);
        $warnings = array_values(array_unique(array_filter(
            is_array($data['warnings'] ?? null) ? $data['warnings'] : [],
            static fn (mixed $warning): bool => is_string($warning) && preg_match('/^[A-Z0-9_]{2,64}$/', $warning) === 1,
        )));
        $files = [];

        foreach (is_array($data['files'] ?? null) ? $data['files'] : [] as $file) {
            if (is_array($file) && is_int($file['file_id'] ?? null)) {
                $files[] = ['file_id' => $file['file_id'], 'pages' => max(0, (int) ($file['pages'] ?? 0)), 'ocr_used' => (bool) ($file['ocr_used'] ?? false)];
            }
        }

        /** @var array<string, mixed> $rawFields */
        return new self(
            (int) ($data['workspace_id'] ?? 0),
            (int) ($data['payment_request_id'] ?? 0),
            (int) ($data['revision'] ?? 0),
            ExtractionStatus::tryFrom((string) ($data['status'] ?? '')) ?? ExtractionStatus::Failed,
            mb_substr((string) ($data['extractor_version'] ?? 'unknown'), 0, 32),
            (bool) ($data['ocr_used'] ?? false),
            max(0, (int) ($data['pages'] ?? 0)),
            mb_substr(is_string($data['text'] ?? null) ? $data['text'] : '', 0, $maxChars, 'UTF-8'),
            ExtractedFields::from($rawFields),
            $rawFields,
            array_map('strval', $warnings),
            is_string($data['error_code'] ?? null) ? mb_substr($data['error_code'], 0, 64) : null,
            max(0, (int) ($data['duration_ms'] ?? 0)),
            (bool) ($data['llm_assisted'] ?? false),
            $files,
        );
    }
}
