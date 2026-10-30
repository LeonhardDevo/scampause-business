<?php

declare(strict_types=1);

namespace App\Http\Requests\Domain;

use App\Domain\Support\BankValidators;
use App\Domain\Vendors\Enums\BankScheme;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class BankAccountChecks
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(bool $withDetails = true): array
    {
        $rules = [
            'scheme' => ['required', Rule::enum(BankScheme::class)],
            'account_number' => ['required', 'string', 'max:64'],
            'routing_code' => ['nullable', 'string', 'max:32', 'required_if:scheme,'.implode(',', PaymentRequestFields::PAYEE_ROUTING_SCHEMES)],
        ];

        if ($withDetails) {
            $rules += [
                'bic' => ['nullable', 'string', 'max:11', 'regex:/^[A-Za-z]{4}[A-Za-z]{2}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?$/'],
                'account_holder' => ['nullable', 'string', 'max:160'],
                'bank_name' => ['nullable', 'string', 'max:160'],
                'bank_country' => ['nullable', 'string', 'size:2'],
                'currency' => ['nullable', 'string', 'size:3'],
                'correspondent_account' => ['nullable', 'string', 'max:32'],
            ];
        }

        return $rules;
    }

    public static function check(Validator $validator): void
    {
        $data = $validator->getData();
        $scheme = BankScheme::tryFrom((string) ($data['scheme'] ?? ''));
        $account = is_string($data['account_number'] ?? null) ? $data['account_number'] : '';
        $routing = is_string($data['routing_code'] ?? null) ? $data['routing_code'] : '';

        if ($account === '' || $scheme === null) {
            return;
        }

        $accountValid = match ($scheme) {
            BankScheme::Iban => BankValidators::iban($account),
            BankScheme::UsAch => BankValidators::usAccount($account),
            BankScheme::RuAccount => preg_match('/^\d{20}$/', (string) preg_replace('/\D+/', '', $account)) === 1
                && ($routing === '' || ! BankValidators::ruBik($routing) || BankValidators::ruAccount($account, $routing)),
            BankScheme::UkSortCode => BankValidators::ukAccount($account),
            BankScheme::Other => true,
        };

        if (! $accountValid) {
            $validator->errors()->add('account_number', (string) __('domain.validation.account_checksum'));
        }

        $routingValid = match ($scheme) {
            BankScheme::UsAch => $routing === '' || BankValidators::aba($routing),
            BankScheme::RuAccount => $routing === '' || BankValidators::ruBik($routing),
            BankScheme::UkSortCode => $routing === '' || BankValidators::ukSortCode($routing),
            default => true,
        };

        if (! $routingValid) {
            $validator->errors()->add('routing_code', (string) __('domain.validation.routing_checksum'));
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{scheme: BankScheme, account_number: string, routing_code: string|null, bic: string|null, account_holder: string|null, bank_name: string|null, bank_country: string|null, currency: string|null, correspondent_account: string|null}
     */
    public static function data(array $validated): array
    {
        $string = static fn (string $key): ?string => is_string($validated[$key] ?? null) && trim($validated[$key]) !== '' ? trim($validated[$key]) : null;

        return [
            'scheme' => BankScheme::from((string) $validated['scheme']),
            'account_number' => (string) $validated['account_number'],
            'routing_code' => $string('routing_code'),
            'bic' => $string('bic'),
            'account_holder' => $string('account_holder'),
            'bank_name' => $string('bank_name'),
            'bank_country' => $string('bank_country'),
            'currency' => $string('currency'),
            'correspondent_account' => $string('correspondent_account'),
        ];
    }
}
