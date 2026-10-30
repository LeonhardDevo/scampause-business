<?php

declare(strict_types=1);

namespace App\Domain\Intake;

use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Support\BankValidators;
use App\Domain\Support\Fingerprinter;
use App\Domain\Support\Normalize\BankNormalizer;
use App\Domain\Vendors\Enums\BankScheme;

final class PayeeWriter
{
    public const array FIELDS = [
        'payee_scheme', 'payee_account_holder', 'payee_account_number', 'payee_routing_code', 'payee_correspondent_account',
        'payee_bic', 'payee_bank_name', 'payee_bank_country', 'payee_fingerprint', 'payee_last4', 'payee_checksum_valid',
    ];

    /**
     * @return array<string, string|bool|BankScheme|null>
     */
    public static function attributes(
        int $workspaceId,
        BankScheme $scheme,
        string $account,
        ?string $routing = null,
        ?string $bic = null,
        ?string $holder = null,
        ?string $bankName = null,
        ?string $bankCountry = null,
        ?string $correspondent = null,
    ): array {
        $normalized = BankNormalizer::account($scheme, $account);
        $routingNormalized = $routing !== null && trim($routing) !== '' ? BankNormalizer::routing($scheme, $routing) : null;
        $routingNormalized = $routingNormalized === '' ? null : $routingNormalized;
        $bicNormalized = BankNormalizer::bic($bic);
        $country = self::country($scheme, $normalized, $bicNormalized, $bankCountry);

        return [
            'payee_scheme' => $scheme,
            'payee_account_holder' => self::clean($holder, 160),
            'payee_account_number' => $normalized,
            'payee_routing_code' => $routingNormalized,
            'payee_correspondent_account' => $correspondent !== null && trim($correspondent) !== '' ? (string) preg_replace('/\D+/', '', $correspondent) : null,
            'payee_bic' => $bicNormalized !== null && strlen($bicNormalized) <= 11 ? $bicNormalized : null,
            'payee_bank_name' => self::clean($bankName, 160),
            'payee_bank_country' => $country,
            'payee_fingerprint' => Fingerprinter::fromConfig()->bank($workspaceId, $scheme, $normalized, $routingNormalized),
            'payee_last4' => BankNormalizer::last4($normalized),
            'payee_checksum_valid' => BankValidators::checksum($scheme, $normalized, $routingNormalized),
        ];
    }

    /** @return array<string, null> */
    public static function cleared(): array
    {
        return array_fill_keys(self::FIELDS, null);
    }

    public static function apply(PaymentRequest $request, BankScheme $scheme, string $account, ?string $routing = null, ?string $bic = null, ?string $holder = null, ?string $bankName = null, ?string $bankCountry = null, ?string $correspondent = null): void
    {
        $request->forceFill(self::attributes((int) $request->getAttribute('workspace_id'), $scheme, $account, $routing, $bic, $holder, $bankName, $bankCountry, $correspondent));
    }

    public static function country(BankScheme $scheme, string $normalized, ?string $bic, ?string $explicit): ?string
    {
        if ($explicit !== null && preg_match('/^[A-Za-z]{2}$/', trim($explicit)) === 1) {
            return strtoupper(trim($explicit));
        }

        if ($scheme === BankScheme::Iban) {
            return BankValidators::ibanCountry($normalized);
        }

        if ($bic !== null && BankValidators::bic($bic)) {
            return BankValidators::bicCountry($bic);
        }

        return match ($scheme) {
            BankScheme::RuAccount => 'RU',
            BankScheme::UsAch => 'US',
            BankScheme::UkSortCode => 'GB',
            default => null,
        };
    }

    private static function clean(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : mb_substr($value, 0, $max, 'UTF-8');
    }
}
