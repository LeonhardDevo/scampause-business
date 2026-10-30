import { currencyExponent } from '../money.js';
import { allMatches, keywordRe, TextMap, type Span } from './text.js';

export interface AmountCandidate {
  minor: bigint;
  currency: string | null;
  score: number;
  confidence: number;
  span: Span;
  raw: string;
}

const CURRENCY_TOKEN = '(?:[$€£₽¥₸]|USD|EUR|GBP|RUB|JPY|KZT|CHF|руб(?:л\\p{L}*|\\.)?|р\\.)';
const NUMBER = '(?:\\d{1,3}(?:[ \\u00A0.,]\\d{3})+|\\d+)(?:[.,]\\d{1,2})?';
const AMOUNT_RE = new RegExp(
  `(?<![\\p{L}\\p{N}.,])(${CURRENCY_TOKEN})?[ \\u00A0]?(${NUMBER})(?![\\p{N}])(?:[ \\u00A0]?(${CURRENCY_TOKEN})(?![\\p{L}]))?`,
  'giu',
);
const CURRENCY_ANYWHERE_RE = new RegExp(`(?<![\\p{L}])${CURRENCY_TOKEN}(?![\\p{L}])`, 'giu');

const TOTAL_KEYWORDS = keywordRe([
  'total due',
  'amount due',
  'balance due',
  'grand total',
  'please pay',
  'total',
  'итого к оплате',
  'всего к оплате',
  'сумма к оплате',
  'к оплате',
  'итого',
]);
const PENALTY_KEYWORDS = keywordRe(['vat', 'tax', 'ндс', 'subtotal', 'подытог…', 'discount', 'скидк…']);

const SYMBOLS: Readonly<Record<string, string>> = Object.freeze({
  $: 'USD',
  '€': 'EUR',
  '£': 'GBP',
  '₽': 'RUB',
  '¥': 'JPY',
  '₸': 'KZT',
  'р.': 'RUB',
  руб: 'RUB',
  'руб.': 'RUB',
});

export function currencyFromToken(token: string | undefined | null): string | null {
  if (!token) return null;
  const lower = token.toLowerCase();
  if (lower.startsWith('руб')) return 'RUB';
  if (SYMBOLS[lower]) return SYMBOLS[lower] ?? null;
  if (SYMBOLS[token]) return SYMBOLS[token] ?? null;
  const upper = token.toUpperCase();
  return /^[A-Z]{3}$/.test(upper) ? upper : null;
}

export function parseAmountDigits(raw: string, exponent: number): bigint | null {
  const compact = raw.replace(/[ \u00A0\u202F]/g, ' ').trim();
  const lastDot = compact.lastIndexOf('.');
  const lastComma = compact.lastIndexOf(',');
  let decimalIndex = -1;
  if (lastDot !== -1 && lastComma !== -1) {
    decimalIndex = Math.max(lastDot, lastComma);
    if (!/^\d{1,2}$/.test(compact.slice(decimalIndex + 1))) decimalIndex = -1;
  } else {
    const sep = lastDot !== -1 ? '.' : lastComma !== -1 ? ',' : null;
    if (sep !== null) {
      const index = compact.lastIndexOf(sep);
      const tail = compact.slice(index + 1);
      const occurrences = compact.split(sep).length - 1;
      if (occurrences === 1 && /^\d{1,2}$/.test(tail)) decimalIndex = index;
    }
  }
  const integerPart = (decimalIndex === -1 ? compact : compact.slice(0, decimalIndex)).replace(/\D/g, '');
  const fractionPart = decimalIndex === -1 ? '' : compact.slice(decimalIndex + 1).replace(/\D/g, '');
  if (integerPart === '' || integerPart.length > 13) return null;
  if (fractionPart.length > exponent) {
    if (exponent === 0 && /^0+$/.test(fractionPart)) return BigInt(integerPart);
    return null;
  }
  return BigInt(integerPart) * 10n ** BigInt(exponent) + BigInt(fractionPart.padEnd(exponent, '0') || '0');
}

export function dominantCurrency(text: string): string | null {
  const counts = new Map<string, number>();
  for (const match of allMatches(CURRENCY_ANYWHERE_RE, text)) {
    const currency = currencyFromToken(match[0]);
    if (currency) counts.set(currency, (counts.get(currency) ?? 0) + 1);
  }
  let best: string | null = null;
  let bestCount = 0;
  for (const [currency, count] of counts) {
    if (count > bestCount) {
      best = currency;
      bestCount = count;
    }
  }
  return best;
}

function hasKeyword(re: RegExp, line: string): boolean {
  re.lastIndex = 0;
  return re.test(line);
}

export function findAmounts(maskedText: string, fallbackCurrency: string | null): AmountCandidate[] {
  const map = new TextMap(maskedText);
  const raw: Array<{ minorText: string; currency: string | null; span: Span; line: number; grouped: boolean; decimals: boolean; full: string }> = [];
  for (const match of allMatches(AMOUNT_RE, maskedText)) {
    const number = match[2] ?? '';
    const currency = currencyFromToken(match[1]) ?? currencyFromToken(match[3]);
    const start = match.index;
    raw.push({
      minorText: number,
      currency,
      span: { start, end: start + match[0].length },
      line: map.lineOf(start + (match[1]?.length ?? 0)),
      grouped: /\d[ \u00A0.,]\d{3}(?:\D|$)/.test(number) && !/^\d+[.,]\d{1,2}$/.test(number),
      decimals: /[.,]\d{1,2}$/.test(number),
      full: match[0].trim(),
    });
  }
  const candidates: AmountCandidate[] = [];
  for (const item of raw) {
    const lineText = map.line(item.line);
    const previous = map.previousNonEmptyLine(item.line);
    const keywordHere = hasKeyword(TOTAL_KEYWORDS, lineText) || (previous !== null && hasKeyword(TOTAL_KEYWORDS, map.line(previous)));
    if (!item.currency && !item.decimals && !item.grouped && !keywordHere) continue;
    const currency = item.currency ?? fallbackCurrency;
    const minor = parseAmountDigits(item.minorText, currencyExponent(currency ?? 'USD'));
    if (minor === null || minor <= 0n) continue;
    let score = 0;
    if (keywordHere) score += 50;
    if (item.currency) score += 10;
    if (hasKeyword(PENALTY_KEYWORDS, lineText)) score -= 30;
    candidates.push({ minor, currency: item.currency, score, confidence: 0, span: item.span, raw: item.full });
  }
  if (candidates.length === 0) return [];
  const largest = candidates.reduce((max, candidate) => (candidate.minor > max ? candidate.minor : max), 0n);
  for (const candidate of candidates) {
    if (candidate.minor === largest) candidate.score += 10;
    candidate.confidence = Math.max(0, Math.min(95, 40 + candidate.score));
  }
  return candidates;
}

export function pickAmount(candidates: readonly AmountCandidate[]): AmountCandidate | null {
  let best: AmountCandidate | null = null;
  for (const candidate of candidates) {
    if (
      best === null ||
      candidate.score > best.score ||
      (candidate.score === best.score && candidate.minor > best.minor)
    ) {
      best = candidate;
    }
  }
  return best;
}
