export type MoneyLocale = 'ru' | 'en';

export interface MoneyFormatSpec {
  symbol: string;
  position: 'prefix' | 'suffix';
  group: string;
  decimal: string;
  spaceBeforeSymbol: boolean;
}

export const NBSP = '\u00A0';

const EXPONENTS: Readonly<Record<string, number>> = Object.freeze({
  JPY: 0,
  KRW: 0,
  VND: 0,
  CLP: 0,
  ISK: 0,
  HUF: 2,
  BHD: 3,
  KWD: 3,
  OMR: 3,
  JOD: 3,
  TND: 3,
});

const EN_SYMBOLS: Readonly<Record<string, string>> = Object.freeze({ USD: '$', EUR: '€', GBP: '£', JPY: '¥' });
const RU_SYMBOLS: Readonly<Record<string, string>> = Object.freeze({ USD: '$', EUR: '€', GBP: '£', JPY: '¥', RUB: '₽', KZT: '₸' });

export function currencyExponent(currency: string): number {
  return EXPONENTS[currency.toUpperCase()] ?? 2;
}

export function moneyFormatSpec(locale: MoneyLocale, currency: string): MoneyFormatSpec {
  const code = currency.toUpperCase();
  if (locale === 'ru') {
    return { symbol: RU_SYMBOLS[code] ?? code, position: 'suffix', group: NBSP, decimal: ',', spaceBeforeSymbol: true };
  }
  const symbol = EN_SYMBOLS[code];
  if (symbol) return { symbol, position: 'prefix', group: ',', decimal: '.', spaceBeforeSymbol: false };
  return { symbol: code, position: 'prefix', group: ',', decimal: '.', spaceBeforeSymbol: true };
}

export function groupDigits(digits: string, separator: string): string {
  const out: string[] = [];
  for (let end = digits.length; end > 0; end -= 3) out.unshift(digits.slice(Math.max(0, end - 3), end));
  return out.join(separator);
}

export function formatMoney(minor: number | bigint, currency: string, locale: MoneyLocale): string {
  const exponent = currencyExponent(currency);
  const spec = moneyFormatSpec(locale, currency);
  const value = BigInt(minor);
  const negative = value < 0n;
  const absolute = (negative ? -value : value).toString().padStart(exponent + 1, '0');
  const integer = exponent === 0 ? absolute : absolute.slice(0, absolute.length - exponent);
  const fraction = exponent === 0 ? '' : absolute.slice(absolute.length - exponent);
  const number = `${groupDigits(integer, spec.group)}${fraction === '' ? '' : `${spec.decimal}${fraction}`}`;
  const gap = spec.spaceBeforeSymbol ? (locale === 'ru' ? NBSP : ' ') : '';
  const body = spec.position === 'prefix' ? `${spec.symbol}${gap}${number}` : `${number}${gap}${spec.symbol}`;
  return negative ? `-${body}` : body;
}

export function parseMajorToMinor(amount: string, currency: string): bigint | null {
  const match = /^(\d{1,15})(?:[.,](\d{1,3}))?$/.exec(amount.trim());
  if (!match) return null;
  const exponent = currencyExponent(currency);
  const fraction = match[2] ?? '';
  if (fraction.length > exponent) return null;
  return BigInt(match[1] ?? '0') * 10n ** BigInt(exponent) + BigInt(fraction.padEnd(exponent, '0') || '0');
}
