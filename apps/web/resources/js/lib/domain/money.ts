import type { Locale } from '@/types/shared';

const NBSP = ' ';

const EXPONENTS: Record<string, number> = {
    JPY: 0,
    KRW: 0,
    VND: 0,
    CLP: 0,
    ISK: 0,
};

type FormatSpec = {
    symbol: string | null;
    position: 'prefix' | 'suffix';
    group: string;
    decimal: string;
    spaceBeforeSymbol: boolean;
};

const SYMBOLS: Record<Locale, Record<string, string>> = {
    en: { USD: '$', EUR: '€', GBP: '£', JPY: '¥' },
    ru: { USD: '$', EUR: '€', GBP: '£', RUB: '₽', JPY: '¥', KZT: '₸' },
};

function spec(locale: Locale, currency: string): FormatSpec {
    const symbol = SYMBOLS[locale][currency] ?? null;

    if (locale === 'ru') {
        return {
            symbol,
            position: 'suffix',
            group: NBSP,
            decimal: ',',
            spaceBeforeSymbol: true,
        };
    }

    return {
        symbol,
        position: 'prefix',
        group: ',',
        decimal: '.',
        spaceBeforeSymbol: false,
    };
}

export function exponent(currency: string): number {
    return EXPONENTS[currency.toUpperCase()] ?? 2;
}

function groupDigits(digits: string, separator: string): string {
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, separator);
}

export function formatMinor(
    minor: number,
    currency: string,
    locale: Locale,
): string {
    const code = currency.toUpperCase();
    const digits = exponent(code);
    const format = spec(locale, code);
    const negative = minor < 0;
    const absolute = Math.abs(Math.trunc(minor));
    const divisor = 10 ** digits;
    const whole = Math.floor(absolute / divisor);
    const fraction = absolute % divisor;
    let number = groupDigits(String(whole), format.group);

    if (digits > 0) {
        number += format.decimal + String(fraction).padStart(digits, '0');
    }

    const sign = negative ? '-' : '';

    if (format.symbol === null) {
        return format.position === 'prefix'
            ? `${sign}${code} ${number}`
            : `${sign}${number}${NBSP}${code}`;
    }

    if (format.position === 'prefix') {
        return `${sign}${format.symbol}${number}`;
    }

    return `${sign}${number}${format.spaceBeforeSymbol ? NBSP : ''}${format.symbol}`;
}

export function minorToInput(
    minor: number | null | undefined,
    currency: string,
): string {
    if (minor === null || minor === undefined) {
        return '';
    }

    const digits = exponent(currency);

    if (digits === 0) {
        return String(minor);
    }

    const negative = minor < 0;
    const absolute = Math.abs(minor);
    const divisor = 10 ** digits;
    const whole = Math.floor(absolute / divisor);
    const fraction = String(absolute % divisor).padStart(digits, '0');

    return `${negative ? '-' : ''}${whole}.${fraction}`;
}

export function normalizeAmountInput(raw: string): string {
    const compact = raw.replace(/[\s ']/g, '');

    if (compact === '') {
        return '';
    }

    const lastComma = compact.lastIndexOf(',');
    const lastDot = compact.lastIndexOf('.');

    if (lastComma > -1 && lastDot > -1) {
        const decimal = lastComma > lastDot ? ',' : '.';
        const group = decimal === ',' ? '.' : ',';

        return compact.split(group).join('').replace(decimal, '.');
    }

    if (lastComma > -1) {
        const parts = compact.split(',');

        return parts.length === 2 && parts[1].length !== 3
            ? `${parts[0]}.${parts[1]}`
            : parts.join('');
    }

    const dots = compact.split('.');

    return dots.length > 2 ? dots.join('') : compact;
}

export function parseAmount(raw: string, currency: string): number | null {
    const normalized = normalizeAmountInput(raw);

    if (!/^\d+(\.\d+)?$/.test(normalized)) {
        return null;
    }

    const [whole, fraction = ''] = normalized.split('.');
    const digits = exponent(currency);

    if (fraction.length > digits) {
        return null;
    }

    const minor =
        Number.parseInt(whole, 10) * 10 ** digits +
        (digits ? Number.parseInt(fraction.padEnd(digits, '0') || '0', 10) : 0);

    return Number.isSafeInteger(minor) ? minor : null;
}

export function majorFromMinor(minor: number, currency: string): number {
    return minor / 10 ** exponent(currency);
}

export function compactMoney(
    minor: number,
    currency: string,
    intlLocale: string,
): string {
    const major = majorFromMinor(minor, currency);

    try {
        return new Intl.NumberFormat(intlLocale, {
            style: 'currency',
            currency,
            notation: 'compact',
            maximumFractionDigits: 1,
        }).format(major);
    } catch {
        return `${major} ${currency}`;
    }
}
