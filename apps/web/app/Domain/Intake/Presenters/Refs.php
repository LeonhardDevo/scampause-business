<?php

declare(strict_types=1);

namespace App\Domain\Intake\Presenters;

use App\Domain\Money\Money;
use App\Domain\Privacy\UserPseudonymizer;
use App\Domain\Support\Masking;
use App\Domain\Vendors\Enums\BankScheme;
use App\Domain\Vendors\Models\Vendor;
use App\Models\User;
use Carbon\CarbonImmutable;

final class Refs
{
    /** @return array{id: int, name: string, avatar: null}|null */
    public static function user(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $name = $user->getAttribute('deleted_at') !== null ? UserPseudonymizer::displayName($user->id, app()->getLocale()) : $user->name;

        return ['id' => $user->id, 'name' => $name, 'avatar' => null];
    }

    /** @return array{id: int, name: string, kind: string, status: string}|null */
    public static function vendor(?Vendor $vendor): ?array
    {
        if ($vendor === null) {
            return null;
        }

        $kind = $vendor->getAttribute('kind');
        $status = $vendor->getAttribute('status');

        return [
            'id' => (int) $vendor->getKey(),
            'name' => (string) $vendor->getAttribute('name'),
            'kind' => $kind instanceof \BackedEnum ? (string) $kind->value : (string) $kind,
            'status' => $status instanceof \BackedEnum ? (string) $status->value : (string) $status,
        ];
    }

    /** @return array{minor: int, currency: string, formatted: string}|null */
    public static function money(mixed $minor, mixed $currency): ?array
    {
        if (! is_int($minor) || ! is_string($currency) || $currency === '') {
            return null;
        }

        return Money::of($minor, $currency)->toDto(app()->getLocale());
    }

    public static function iso(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? CarbonImmutable::instance($value)->utc()->toIso8601ZuluString() : null;
    }

    public static function date(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? CarbonImmutable::instance($value)->toDateString() : null;
    }

    public static function enum(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * @return array{scheme: string, last4: string, masked: string, bank_name: string|null, bank_country: string|null, bic: string|null, holder_masked: string|null}|null
     */
    public static function maskedAccount(mixed $scheme, mixed $last4, ?string $accountForPrefix, mixed $bankName, mixed $bankCountry, mixed $bic, mixed $holder): ?array
    {
        if (! $scheme instanceof BankScheme || ! is_string($last4) || $last4 === '') {
            return null;
        }

        $prefix = $scheme === BankScheme::Iban && $accountForPrefix !== null ? substr($accountForPrefix, 0, 4) : null;

        return [
            'scheme' => $scheme->value,
            'last4' => $last4,
            'masked' => Masking::account($scheme, $last4, $prefix),
            'bank_name' => is_string($bankName) ? $bankName : null,
            'bank_country' => is_string($bankCountry) ? $bankCountry : null,
            'bic' => is_string($bic) ? $bic : null,
            'holder_masked' => Masking::holder(is_string($holder) ? $holder : null),
        ];
    }
}
