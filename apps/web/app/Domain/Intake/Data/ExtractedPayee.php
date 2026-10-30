<?php

declare(strict_types=1);

namespace App\Domain\Intake\Data;

use App\Domain\Vendors\Enums\BankScheme;

final readonly class ExtractedPayee
{
    public function __construct(
        public BankScheme $scheme,
        public ExtractedField $accountNumber,
        public ?ExtractedField $routingCode,
        public ?ExtractedField $bic,
        public ?ExtractedField $accountHolder,
        public ?ExtractedField $bankName,
        public ?ExtractedField $bankCountry,
        public ?ExtractedField $correspondentAccount,
        public bool $validChecksum,
        public ?ExtractedField $payeeTaxId = null,
        public ?ExtractedField $amountMinor = null,
    ) {}

    public static function from(mixed $raw): ?self
    {
        if (! is_array($raw)) {
            return null;
        }

        $schemeField = ExtractedField::from($raw['scheme'] ?? null);
        $account = ExtractedField::from($raw['account_number'] ?? null);
        $scheme = $schemeField !== null ? BankScheme::tryFrom($schemeField->string()) : null;

        if ($scheme === null || $account === null || ! is_string($account->value)) {
            return null;
        }

        return new self(
            $scheme,
            $account,
            ExtractedField::from($raw['routing_code'] ?? null),
            ExtractedField::from($raw['bic'] ?? null),
            ExtractedField::from($raw['account_holder'] ?? null),
            ExtractedField::from($raw['bank_name'] ?? null),
            ExtractedField::from($raw['bank_country'] ?? null),
            ExtractedField::from($raw['correspondent_account'] ?? null),
            (bool) ($raw['valid_checksum'] ?? false),
            ExtractedField::from($raw['payee_tax_id'] ?? null),
            ExtractedField::from($raw['amount_minor'] ?? null),
        );
    }

    public function confidence(): int
    {
        return $this->accountNumber->confidence;
    }

    public function fromQr(): bool
    {
        return $this->accountNumber->source === 'qr';
    }
}
