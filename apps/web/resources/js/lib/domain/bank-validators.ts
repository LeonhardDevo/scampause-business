import type { BankScheme } from '@/types/domain';

export const IBAN_LENGTHS: Record<string, number> = {
    AD: 24,
    AE: 23,
    AL: 28,
    AT: 20,
    AZ: 28,
    BA: 20,
    BE: 16,
    BG: 22,
    BH: 22,
    BR: 29,
    BY: 28,
    CH: 21,
    CR: 22,
    CY: 28,
    CZ: 24,
    DE: 22,
    DK: 18,
    DO: 28,
    EE: 20,
    EG: 29,
    ES: 24,
    FI: 18,
    FO: 18,
    FR: 27,
    GB: 22,
    GE: 22,
    GI: 23,
    GL: 18,
    GR: 27,
    GT: 28,
    HR: 21,
    HU: 28,
    IE: 22,
    IL: 23,
    IQ: 23,
    IS: 26,
    IT: 27,
    JO: 30,
    KW: 30,
    KZ: 20,
    LB: 28,
    LC: 32,
    LI: 21,
    LT: 20,
    LU: 20,
    LV: 21,
    MC: 27,
    MD: 24,
    ME: 22,
    MK: 19,
    MR: 27,
    MT: 31,
    MU: 30,
    NL: 18,
    NO: 15,
    PK: 24,
    PL: 28,
    PS: 29,
    PT: 25,
    QA: 29,
    RO: 24,
    RS: 22,
    SA: 24,
    SC: 31,
    SE: 24,
    SI: 19,
    SK: 24,
    SM: 27,
    TL: 23,
    TN: 24,
    TR: 26,
    UA: 29,
    VA: 22,
    VG: 24,
    XK: 20,
};

export type ChecksumState = 'ok' | 'invalid' | 'incomplete' | 'not_applicable';

export function compactAccount(value: string): string {
    return value.replace(/[\s\-.]/g, '').toUpperCase();
}

export function groupByFour(value: string): string {
    return compactAccount(value)
        .replace(/(.{4})/g, '$1 ')
        .trim();
}

function mod97(numeric: string): number {
    let remainder = 0;

    for (let index = 0; index < numeric.length; index += 7) {
        remainder =
            Number.parseInt(
                `${remainder}${numeric.slice(index, index + 7)}`,
                10,
            ) % 97;
    }

    return remainder;
}

export function ibanValid(raw: string): boolean {
    const iban = compactAccount(raw);

    if (!/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/.test(iban)) {
        return false;
    }

    const expected = IBAN_LENGTHS[iban.slice(0, 2)];

    if (expected !== undefined && expected !== iban.length) {
        return false;
    }

    const rearranged = `${iban.slice(4)}${iban.slice(0, 4)}`;
    const numeric = rearranged.replace(/[A-Z]/g, (letter) =>
        String(letter.charCodeAt(0) - 55),
    );

    return mod97(numeric) === 1;
}

export function ibanState(raw: string): ChecksumState {
    const iban = compactAccount(raw);

    if (iban.length < 5) {
        return 'incomplete';
    }

    const expected = IBAN_LENGTHS[iban.slice(0, 2)];

    if (expected !== undefined && iban.length < expected) {
        return 'incomplete';
    }

    return ibanValid(iban) ? 'ok' : 'invalid';
}

export function abaValid(raw: string): boolean {
    const routing = raw.replace(/\D/g, '');

    if (routing.length !== 9 || raw.replace(/[\s-]/g, '').length !== 9) {
        return false;
    }

    const d = routing.split('').map(Number);
    const sum =
        3 * (d[0] + d[3] + d[6]) +
        7 * (d[1] + d[4] + d[7]) +
        (d[2] + d[5] + d[8]);

    return sum % 10 === 0 && sum > 0;
}

export function usAccountValid(raw: string): boolean {
    return /^\d{4,17}$/.test(raw.replace(/[\s-]/g, ''));
}

const RU_WEIGHTS = [7, 1, 3];

export function ruAccountValid(account: string, bik: string): boolean {
    const digits = account.replace(/\s/g, '');
    const bank = bik.replace(/\s/g, '');

    if (!/^\d{20}$/.test(digits) || !/^\d{9}$/.test(bank)) {
        return false;
    }

    const control = `${bank.slice(-3)}${digits}`;
    let sum = 0;

    for (let index = 0; index < control.length; index++) {
        sum += Number(control[index]) * RU_WEIGHTS[index % 3];
    }

    return sum % 10 === 0;
}

export function bikValid(raw: string): boolean {
    return /^04\d{7}$/.test(raw.replace(/\s/g, ''));
}

export function ukSortCodeValid(raw: string): boolean {
    return /^\d{2}-?\d{2}-?\d{2}$/.test(raw.trim());
}

export function ukAccountValid(raw: string): boolean {
    return /^\d{8}$/.test(raw.replace(/\s/g, ''));
}

export function bicValid(raw: string): boolean {
    return /^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/.test(
        raw.trim().toUpperCase(),
    );
}

export function bicMatchesIban(bic: string, iban: string): boolean {
    const code = bic.trim().toUpperCase();
    const account = compactAccount(iban);

    if (!bicValid(code) || account.length < 2) {
        return true;
    }

    return code.slice(4, 6) === account.slice(0, 2);
}

export function routingRequired(
    scheme: BankScheme | null | undefined,
): boolean {
    return (
        scheme === 'us_ach' ||
        scheme === 'ru_account' ||
        scheme === 'uk_sort_code'
    );
}

export function accountChecksum(
    scheme: BankScheme | null | undefined,
    account: string,
    routing = '',
): ChecksumState {
    const value = compactAccount(account);

    if (!scheme || value === '') {
        return 'not_applicable';
    }

    switch (scheme) {
        case 'iban':
            return ibanState(value);
        case 'us_ach':
            return usAccountValid(value) ? 'ok' : 'invalid';
        case 'ru_account':
            if (value.length < 20) {
                return 'incomplete';
            }

            if (!bikValid(routing)) {
                return /^\d{20}$/.test(value) ? 'incomplete' : 'invalid';
            }

            return ruAccountValid(value, routing) ? 'ok' : 'invalid';
        case 'uk_sort_code':
            if (value.length < 8) {
                return 'incomplete';
            }

            return ukAccountValid(value) ? 'ok' : 'invalid';
        default:
            return 'not_applicable';
    }
}

export function routingChecksum(
    scheme: BankScheme | null | undefined,
    routing: string,
): ChecksumState {
    const value = routing.replace(/\s/g, '');

    if (!scheme || value === '') {
        return 'not_applicable';
    }

    switch (scheme) {
        case 'us_ach':
            if (value.length < 9) {
                return 'incomplete';
            }

            return abaValid(value) ? 'ok' : 'invalid';
        case 'ru_account':
            if (value.length < 9) {
                return 'incomplete';
            }

            return bikValid(value) ? 'ok' : 'invalid';
        case 'uk_sort_code':
            if (value.replace(/-/g, '').length < 6) {
                return 'incomplete';
            }

            return ukSortCodeValid(value) ? 'ok' : 'invalid';
        default:
            return 'not_applicable';
    }
}
