<?php

declare(strict_types=1);

namespace App\Domain\Intake\Data;

use App\Domain\Intake\Enums\PaymentRequestKind;

final readonly class ExtractedFields
{
    /**
     * @param  list<string>  $emails
     * @param  list<string>  $domains
     * @param  list<string>  $urls
     * @param  list<string>  $phonesE164
     * @param  list<array{page: int|null, format: string, fields: array<string, string>}>  $qr
     */
    public function __construct(
        public ?ExtractedField $vendorName,
        public ?ExtractedField $vendorTaxId,
        public ?ExtractedField $invoiceNumber,
        public ?ExtractedField $invoiceDate,
        public ?ExtractedField $dueDate,
        public ?ExtractedField $amountMinor,
        public ?ExtractedField $currency,
        public ?ExtractedPayee $payee,
        public ?ExtractedPayee $qrPayee,
        public ?ExtractedSender $sender,
        public array $emails,
        public array $domains,
        public array $urls,
        public array $phonesE164,
        public array $qr,
        public PaymentRequestKind $kindSuggestion,
    ) {}

    /** @param  array<string, mixed>  $raw */
    public static function from(array $raw): self
    {
        $amount = ExtractedField::from($raw['amount_minor'] ?? null);

        if ($amount !== null && (! is_int($amount->value) || $amount->value < 0)) {
            $amount = null;
        }

        $currency = ExtractedField::from($raw['currency'] ?? null);

        if ($currency !== null && preg_match('/^[A-Z]{3}$/', strtoupper($currency->string())) !== 1) {
            $currency = null;
        }

        return new self(
            ExtractedField::from($raw['vendor_name'] ?? null),
            ExtractedField::from($raw['vendor_tax_id'] ?? null),
            ExtractedField::from($raw['invoice_number'] ?? null),
            self::date(ExtractedField::from($raw['invoice_date'] ?? null)),
            self::date(ExtractedField::from($raw['due_date'] ?? null)),
            $amount,
            $currency,
            ExtractedPayee::from($raw['payee'] ?? null),
            ExtractedPayee::from($raw['qr_payee'] ?? null),
            ExtractedSender::from($raw['sender'] ?? null),
            self::strings($raw['emails'] ?? [], 100),
            self::strings($raw['domains'] ?? [], 100),
            self::strings($raw['urls'] ?? [], 100),
            self::strings($raw['phones_e164'] ?? [], 50),
            self::qr($raw['qr'] ?? []),
            PaymentRequestKind::tryFrom((string) ($raw['kind_suggestion'] ?? '')) ?? PaymentRequestKind::Other,
        );
    }

    /** @return list<string> */
    public function llmAssistedFields(): array
    {
        $fields = [];

        foreach (['vendor_name' => $this->vendorName, 'invoice_number' => $this->invoiceNumber, 'due_date' => $this->dueDate, 'amount' => $this->amountMinor] as $name => $field) {
            if ($field !== null && $field->isLlmAssisted()) {
                $fields[] = $name;
            }
        }

        return $fields;
    }

    private static function date(?ExtractedField $field): ?ExtractedField
    {
        if ($field === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $field->string()) !== 1) {
            return null;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $field->string()));

        return checkdate($m, $d, $y) ? $field : null;
    }

    /** @return list<string> */
    private static function strings(mixed $raw, int $max): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $values = array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => is_string($value) ? trim($value) : '', $raw),
            static fn (string $value): bool => $value !== '' && mb_strlen($value, 'UTF-8') <= 2048,
        )));

        return array_slice($values, 0, $max);
    }

    /** @return list<array{page: int|null, format: string, fields: array<string, string>}> */
    private static function qr(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $items = [];

        foreach (array_slice($raw, 0, 20) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $fields = [];

            foreach (is_array($item['fields'] ?? null) ? $item['fields'] : [] as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $fields[$key] = mb_substr((string) $value, 0, 500, 'UTF-8');
                }
            }

            $format = in_array($item['format'] ?? null, ['st00012', 'epc', 'url', 'unknown'], true) ? (string) $item['format'] : 'unknown';
            $items[] = ['page' => isset($item['page']) && is_int($item['page']) ? $item['page'] : null, 'format' => $format, 'fields' => $fields];
        }

        return $items;
    }
}
