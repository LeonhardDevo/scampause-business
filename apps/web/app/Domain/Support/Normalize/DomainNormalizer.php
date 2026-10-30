<?php

declare(strict_types=1);

namespace App\Domain\Support\Normalize;

use Pdp\Domain;
use Pdp\Rules;
use Throwable;

final class DomainNormalizer
{
    private static ?Rules $rules = null;

    public static function registrable(?string $raw): ?string
    {
        return self::resolve($raw)['registrable'] ?? null;
    }

    /** @return array{registrable: string, label: string, suffix: string}|null */
    public static function split(?string $raw): ?array
    {
        return self::resolve($raw);
    }

    public static function ascii(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $host = rtrim(mb_strtolower(trim($raw), 'UTF-8'), '.');

        if ($host === '' || preg_match('/\s/u', $host) === 1) {
            return null;
        }

        if (preg_match('/^[\x20-\x7e]+$/', $host) === 1) {
            return $host;
        }

        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return is_string($ascii) && $ascii !== '' ? strtolower($ascii) : null;
    }

    public static function unicode(string $ascii): ?string
    {
        if (! str_contains($ascii, 'xn--')) {
            return null;
        }

        $unicode = idn_to_utf8($ascii, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return is_string($unicode) && $unicode !== $ascii ? $unicode : null;
    }

    public static function fromEmail(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }

        return self::registrable(substr($email, (int) strrpos($email, '@') + 1));
    }

    public static function fromUrl(string $url): ?string
    {
        $host = parse_url(str_contains($url, '://') ? $url : 'http://'.$url, PHP_URL_HOST);

        return is_string($host) ? self::registrable($host) : null;
    }

    /** @return array{registrable: string, label: string, suffix: string}|null */
    private static function resolve(?string $raw): ?array
    {
        $ascii = self::ascii($raw);

        if ($ascii === null || preg_match('/^[a-z0-9.-]+$/', $ascii) !== 1 || ! str_contains($ascii, '.')) {
            return null;
        }

        try {
            $result = self::rules()->resolve(Domain::fromIDNA2008($ascii));
            $registrable = (string) $result->registrableDomain()->toString();
            $suffix = (string) $result->suffix()->toString();
            $label = (string) $result->secondLevelDomain()->toString();
        } catch (Throwable) {
            return null;
        }

        if ($registrable === '' || $suffix === '' || $label === '') {
            return null;
        }

        return ['registrable' => $registrable, 'label' => $label, 'suffix' => $suffix];
    }

    private static function rules(): Rules
    {
        return self::$rules ??= Rules::fromPath(storage_path('app/psl/public_suffix_list.dat'));
    }
}
