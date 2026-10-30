import { findPhoneNumbersInText, type CountryCode } from 'libphonenumber-js/max';
import { getDomain } from 'tldts';
import { allMatches, cleanValue, overlapsAny, TextMap, type Span } from './text.js';
import { isValidInn } from './validators.js';

export interface Found<T> {
  value: T;
  span: Span;
  confidence: number;
}

const INVOICE_EN_RE = /(?<![\p{L}])(?:invoice|inv\.)[ \t]*(?:no\.?|#|number|num\.?)?[ \t]*[:#]?[ \t]*([A-Z0-9][A-Z0-9\-/]{2,31})/giu;
const INVOICE_RU_RE = /(?<![\p{L}])сч[её]т(?:[\s\u00A0]+на[\s\u00A0]+оплату)?[\s\u00A0]*№[\s\u00A0]*([\wА-Яа-яЁё\-/]{1,32})/giu;
const NUMBER_SIGN_RE = /(?:№|#)\s*([A-Z0-9][A-Z0-9\-/]{0,31})/giu;

export function findInvoiceNumber(text: string): Found<string> | null {
  for (const match of allMatches(INVOICE_RU_RE, text)) {
    const value = (match[1] ?? '').replace(/[-/]+$/, '');
    if (/\d/.test(value)) return { value: value.toUpperCase(), span: spanOf(match, 1), confidence: 85 };
  }
  for (const match of allMatches(INVOICE_EN_RE, text)) {
    const value = (match[1] ?? '').replace(/[-/]+$/, '');
    if (/\d/.test(value) && !/^\d{1,2}[/.-]\d{1,2}[/.-]\d{2,4}$/.test(value)) {
      return { value: value.toUpperCase(), span: spanOf(match, 1), confidence: 85 };
    }
  }
  return null;
}

export function numberSignSpans(text: string): Span[] {
  return allMatches(NUMBER_SIGN_RE, text).map((match) => spanOf(match, 1));
}

function spanOf(match: RegExpExecArray, group: number): Span {
  const value = match[group] ?? '';
  const offset = match[0].lastIndexOf(value);
  const start = match.index + (offset === -1 ? 0 : offset);
  return { start, end: start + value.length };
}

const VENDOR_LABEL_RE =
  /(?<![\p{L}\p{N}])(bill\s+from|supplier|vendor|поставщик|исполнитель|продавец|получатель)(?:[ \t]*\([^)\n]{0,40}\))?(?![\p{L}])[ \t:.\-–—]*(.*)$/gimu;

function cutVendor(value: string): string {
  return cleanValue(
    value
      .replace(/(?:,|\s)\s*(?:ИНН|КПП|ОГРН|INN|EIN|Tax\s+ID|tel\.?|тел\.?|адрес|address)(?![\p{L}]).*$/iu, '')
      .replace(/,\s*\d{6}.*$/u, ''),
  );
}

export function findVendorName(text: string, fromName: string | null): Found<string> | null {
  const map = new TextMap(text);
  let best: Found<string> | null = null;
  for (const match of allMatches(VENDOR_LABEL_RE, text)) {
    const recipient = (match[1] ?? '').toLowerCase() === 'получатель';
    let value = cutVendor(match[2] ?? '');
    if (value === '') {
      const line = map.lineOf(match.index);
      const other = recipient ? map.previousNonEmptyLine(line) : map.nextNonEmptyLine(line);
      value = other === null ? '' : cutVendor(map.line(other));
    }
    const confidence = recipient ? 60 : 75;
    if (value.length >= 2 && /\p{L}/u.test(value) && !/^(?:name|details|банк|сч[её]т)/iu.test(value) && (!best || confidence > best.confidence)) {
      best = { value, span: { start: match.index, end: match.index + match[0].length }, confidence };
    }
  }
  if (best) return best;
  if (fromName && fromName.trim() !== '') return { value: cleanValue(fromName), span: { start: 0, end: 0 }, confidence: 60 };
  for (let line = 0; line < map.lineStarts.length; line++) {
    const value = cleanValue(map.line(line));
    if (value !== '' && /\p{L}{2}/u.test(value)) return { value, span: { start: map.lineStart(line), end: map.lineStart(line) + value.length }, confidence: 40 };
  }
  return null;
}

const INN_RE = /(?<![\p{L}])ИНН(?:\s*\/\s*КПП)?[\s:№]*(\d{12}|\d{10})(?!\d)/giu;
const EIN_RE = /(?<![\p{L}])(?:EIN|Tax\s+ID|TIN)[\s:#№]*(\d{2}-\d{7})(?!\d)/giu;
const BUYER_RE = /(?:покупател|заказчик|плательщик|buyer|bill\s+to|customer)/iu;

export function findTaxIds(text: string): { vendor: Found<string> | null; all: Found<string>[] } {
  const map = new TextMap(text);
  const all: Found<string>[] = [];
  for (const match of allMatches(INN_RE, text)) {
    const value = match[1] ?? '';
    if (!isValidInn(value)) continue;
    all.push({ value, span: spanOf(match, 1), confidence: 90 });
  }
  for (const match of allMatches(EIN_RE, text)) all.push({ value: match[1] ?? '', span: spanOf(match, 1), confidence: 85 });
  all.sort((a, b) => a.span.start - b.span.start);
  const vendor = all.find((item) => !BUYER_RE.test(map.line(map.lineOf(item.span.start)))) ?? null;
  return { vendor, all };
}

const EMAIL_RE = /(?<![\p{L}\p{N}._%+-])[A-Z0-9._%+-]{1,64}@[A-Z0-9.-]{1,253}\.[A-Z]{2,24}(?![\p{L}\p{N}])/giu;
const URL_RE = /(?<![\p{L}\p{N}])(?:https?:\/\/|www\.)[^\s<>"'()[\]{}]{2,2000}/giu;

export function findEmails(text: string): Found<string>[] {
  return allMatches(EMAIL_RE, text).map((match) => ({
    value: match[0].toLowerCase(),
    span: { start: match.index, end: match.index + match[0].length },
    confidence: 90,
  }));
}

export function findUrls(text: string): Found<string>[] {
  return allMatches(URL_RE, text).map((match) => {
    const raw = match[0].replace(/[.,;:!?]+$/, '');
    const value = raw.toLowerCase().startsWith('www.') ? `http://${raw}` : raw;
    return { value, span: { start: match.index, end: match.index + raw.length }, confidence: 90 };
  });
}

export function registrableDomain(hostOrEmail: string): string | null {
  const host = hostOrEmail.includes('@') ? (hostOrEmail.split('@').pop() ?? '') : hostOrEmail;
  const domain = getDomain(host.trim().toLowerCase().replace(/\.$/, ''), { allowPrivateDomains: false });
  return domain ?? null;
}

export function domainFromUrl(url: string): string | null {
  try {
    return registrableDomain(new URL(url).hostname);
  } catch {
    return null;
  }
}

export function findPhones(text: string, country: string, exclude: readonly Span[]): Found<string>[] {
  const region = /^[A-Z]{2}$/.test(country.toUpperCase()) ? (country.toUpperCase() as CountryCode) : undefined;
  const found: Found<string>[] = [];
  let results;
  try {
    results = findPhoneNumbersInText(text, region ? { defaultCountry: region } : {});
  } catch {
    return found;
  }
  for (const result of results) {
    const span = { start: result.startsAt, end: result.endsAt };
    if (overlapsAny(span, exclude)) continue;
    if (!result.number.isValid()) continue;
    found.push({ value: result.number.number, span, confidence: 80 });
  }
  return found;
}
