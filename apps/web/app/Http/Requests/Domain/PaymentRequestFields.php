<?php

declare(strict_types=1);

namespace App\Http\Requests\Domain;

use App\Domain\Intake\Data\ManualEntry;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Money\Currencies;
use App\Domain\Money\MoneyParser;
use App\Domain\Support\BankValidators;
use App\Domain\Vendors\Enums\BankScheme;
use App\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class PaymentRequestFields
{
    public const array PAYEE_ROUTING_SCHEMES = ['us_ach', 'ru_account', 'uk_sort_code'];

    /**
     * @return array<string, mixed>
     */
    public static function rules(bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $workspaceId = app(CurrentWorkspace::class)->id();

        return [
            'title' => [$required, 'string', 'min:3', 'max:200'],
            'kind' => [$required, Rule::enum(PaymentRequestKind::class)],
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at')],
            'amount' => [$partial ? 'sometimes' : 'required_unless:kind,bank_details_change,payroll_change', 'nullable', 'string', 'max:20'],
            'currency' => ['required_with:amount', 'nullable', 'string', 'size:3', Rule::in(Currencies::codes())],
            'invoice_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'invoice_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'payee' => ['sometimes', 'nullable', 'array'],
            'payee.scheme' => ['required_with:payee.account_number', 'nullable', Rule::enum(BankScheme::class)],
            'payee.account_number' => [$partial ? 'sometimes' : 'required_unless:kind,other', 'nullable', 'string', 'max:64'],
            'payee.routing_code' => ['nullable', 'string', 'max:32', 'required_if:payee.scheme,'.implode(',', self::PAYEE_ROUTING_SCHEMES)],
            'payee.bic' => ['nullable', 'string', 'max:11', 'regex:/^[A-Za-z]{4}[A-Za-z]{2}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?$/'],
            'payee.account_holder' => ['nullable', 'string', 'max:160'],
            'payee.bank_name' => ['nullable', 'string', 'max:160'],
            'payee.bank_country' => ['nullable', 'string', 'size:2'],
            'payee.correspondent_account' => ['nullable', 'string', 'max:32'],
            'sender_email' => ['sometimes', 'nullable', 'email:rfc', 'max:254'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public static function after(Validator $validator, bool $partial): void
    {
        $data = $validator->getData();

        if (! $partial || array_key_exists('amount', $data)) {
            $amount = $data['amount'] ?? null;
            $currency = is_string($data['currency'] ?? null) ? strtoupper($data['currency']) : null;

            if (is_string($amount) && $amount !== '') {
                $minor = $currency !== null ? MoneyParser::toMinor($amount, $currency) : null;
                $max = $currency !== null ? 1_000_000_000 * (10 ** Currencies::exponent($currency)) : 0;

                if ($minor === null || $minor <= 0 || $minor > $max) {
                    $validator->errors()->add('amount', (string) __('domain.validation.amount'));
                }
            }
        }

        $payee = is_array($data['payee'] ?? null) ? $data['payee'] : null;

        if ($payee === null || ! is_string($payee['account_number'] ?? null) || trim($payee['account_number']) === '') {
            return;
        }

        $scheme = BankScheme::tryFrom((string) ($payee['scheme'] ?? ''));
        $account = $payee['account_number'];
        $routing = is_string($payee['routing_code'] ?? null) ? $payee['routing_code'] : '';

        $accountValid = match ($scheme) {
            BankScheme::Iban => BankValidators::iban($account),
            BankScheme::UsAch => BankValidators::usAccount($account),
            BankScheme::RuAccount => preg_match('/^\d{20}$/', (string) preg_replace('/\D+/', '', $account)) === 1
                && ($routing === '' || ! BankValidators::ruBik($routing) || BankValidators::ruAccount($account, $routing)),
            BankScheme::UkSortCode => BankValidators::ukAccount($account),
            BankScheme::Other, null => true,
        };

        if (! $accountValid) {
            $validator->errors()->add('payee.account_number', (string) __('domain.validation.account_checksum'));
        }

        $routingValid = match ($scheme) {
            BankScheme::UsAch => $routing === '' || BankValidators::aba($routing),
            BankScheme::RuAccount => $routing === '' || BankValidators::ruBik($routing),
            BankScheme::UkSortCode => $routing === '' || BankValidators::ukSortCode($routing),
            default => true,
        };

        if (! $routingValid) {
            $validator->errors()->add('payee.routing_code', (string) __('domain.validation.routing_checksum'));
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  list<string>  $provided
     */
    public static function entry(array $validated, array $provided): ManualEntry
    {
        $payee = is_array($validated['payee'] ?? null) ? $validated['payee'] : [];
        $currency = is_string($validated['currency'] ?? null) ? strtoupper($validated['currency']) : null;
        $amount = is_string($validated['amount'] ?? null) && $validated['amount'] !== '' && $currency !== null
            ? MoneyParser::toMinor($validated['amount'], $currency)
            : null;

        return new ManualEntry(
            provided: $provided,
            title: is_string($validated['title'] ?? null) ? $validated['title'] : null,
            kind: isset($validated['kind']) ? PaymentRequestKind::tryFrom((string) $validated['kind']) : null,
            vendorId: isset($validated['vendor_id']) ? (int) $validated['vendor_id'] : null,
            amountMinor: $amount,
            currency: $currency,
            invoiceNumber: self::str($validated['invoice_number'] ?? null),
            invoiceDate: self::date($validated['invoice_date'] ?? null),
            dueDate: self::date($validated['due_date'] ?? null),
            payeeScheme: BankScheme::tryFrom((string) ($payee['scheme'] ?? '')),
            payeeAccountNumber: self::str($payee['account_number'] ?? null),
            payeeRoutingCode: self::str($payee['routing_code'] ?? null),
            payeeBic: self::str($payee['bic'] ?? null),
            payeeAccountHolder: self::str($payee['account_holder'] ?? null),
            payeeBankName: self::str($payee['bank_name'] ?? null),
            payeeBankCountry: self::str($payee['bank_country'] ?? null),
            payeeCorrespondentAccount: self::str($payee['correspondent_account'] ?? null),
            senderEmail: self::str($validated['sender_email'] ?? null),
            description: self::str($validated['description'] ?? null),
        );
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        $names = ['title', 'kind', 'vendor_id', 'amount', 'currency', 'invoice_number', 'invoice_date', 'due_date', 'sender_email', 'description', 'files'];
        $attributes = [];

        foreach ($names as $name) {
            $attributes[$name] = (string) __('domain.attributes.'.$name);
        }

        foreach (['scheme', 'account_number', 'routing_code', 'bic', 'account_holder', 'bank_name', 'bank_country', 'correspondent_account'] as $name) {
            $attributes['payee.'.$name] = (string) __('domain.attributes.payee_'.$name);
        }

        return $attributes;
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof CarbonImmutable ? $date : null;
    }
}
