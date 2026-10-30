<?php

declare(strict_types=1);

namespace Database\Seeders\Domain\Support;

use App\Domain\Money\Money;
use Carbon\CarbonImmutable;

final class InvoiceFixture
{
    /**
     * @param  array<string, mixed>  $account
     * @return array{files: list<array{bytes: string, name: string, mime: string}>, extraction: array<string, mixed>}
     */
    public static function build(string $vendorName, ?string $taxId, string $invoiceNumber, int $amountMinor, string $currency, CarbonImmutable $invoiceDate, CarbonImmutable $dueDate, array $account, string $description, ?string $holderOverride = null): array
    {
        $holder = $holderOverride ?? (is_string($account['account_holder'] ?? null) ? $account['account_holder'] : $vendorName);
        $routing = is_string($account['routing_code'] ?? null) ? $account['routing_code'] : null;
        $bic = is_string($account['bic'] ?? null) ? $account['bic'] : null;
        $accountNumber = (string) $account['account_number'];
        $scheme = $account['scheme'] instanceof \BackedEnum ? (string) $account['scheme']->value : (string) $account['scheme'];
        $amount = Money::of($amountMinor, $currency)->format('en');
        $lines = array_values(array_filter([
            $taxId !== null ? 'Tax ID '.$taxId : null,
            'Invoice '.$invoiceNumber.' dated '.$invoiceDate->toDateString(),
            'Bill to: Northwind Coffee Roasters LLC',
            $description,
            'Total due: '.$amount,
            'Due date: '.$dueDate->toDateString(),
            '',
            'Payee: '.$holder,
            'Bank: '.(string) ($account['bank_name'] ?? ''),
            $routing !== null ? 'Routing: '.$routing : null,
            $bic !== null ? 'BIC: '.$bic : null,
            ($scheme === 'iban' ? 'IBAN: ' : 'Account: ').$accountNumber,
        ], static fn (?string $line): bool => $line !== null));
        $text = $vendorName."\n".implode("\n", $lines);
        $field = static fn (mixed $value, int $confidence = 94): array => ['value' => $value, 'confidence' => $confidence, 'source' => 'text', 'page' => 1];
        $payee = array_filter([
            'scheme' => $field($scheme),
            'account_number' => $field($accountNumber),
            'routing_code' => $routing !== null ? $field($routing) : null,
            'bic' => $bic !== null ? $field($bic) : null,
            'account_holder' => $field($holder, 88),
            'bank_name' => isset($account['bank_name']) ? $field($account['bank_name'], 88) : null,
            'bank_country' => isset($account['bank_country']) ? $field($account['bank_country'], 88) : null,
        ]);
        $payee['valid_checksum'] = true;

        return [
            'files' => [[
                'bytes' => DemoDocuments::pdf($vendorName.' - Invoice '.$invoiceNumber, $lines),
                'name' => strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $vendorName)).'-'.strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $invoiceNumber)).'.pdf',
                'mime' => 'application/pdf',
            ]],
            'extraction' => [
                'status' => 'succeeded',
                'text' => $text,
                'fields' => array_filter([
                    'vendor_name' => $field($vendorName, 92),
                    'vendor_tax_id' => $taxId !== null ? $field($taxId, 92) : null,
                    'invoice_number' => $field($invoiceNumber, 93),
                    'invoice_date' => $field($invoiceDate->toDateString(), 92),
                    'due_date' => $field($dueDate->toDateString(), 90),
                    'amount_minor' => $field($amountMinor, 96),
                    'currency' => $field($currency, 96),
                    'payee' => $payee,
                    'emails' => [],
                    'domains' => [],
                    'urls' => [],
                    'phones_e164' => [],
                    'qr' => [],
                    'kind_suggestion' => 'invoice',
                ], static fn (mixed $value): bool => $value !== null),
            ],
        ];
    }
}
