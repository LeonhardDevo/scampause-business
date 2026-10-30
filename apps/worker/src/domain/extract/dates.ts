import { allMatches, keywordRe, type Span } from './text.js';

export interface DateCandidate {
  value: string;
  span: Span;
  ambiguous: boolean;
}

const EN_MONTHS: Readonly<Record<string, number>> = Object.freeze({
  jan: 1, january: 1, feb: 2, february: 2, mar: 3, march: 3, apr: 4, april: 4, may: 5, jun: 6, june: 6, jul: 7, july: 7,
  aug: 8, august: 8, sep: 9, sept: 9, september: 9, oct: 10, october: 10, nov: 11, november: 11, dec: 12, december: 12,
});

const RU_MONTHS: Readonly<Record<string, number>> = Object.freeze({
  января: 1, февраля: 2, марта: 3, апреля: 4, мая: 5, июня: 6, июля: 7, августа: 8, сентября: 9, октября: 10, ноября: 11, декабря: 12,
});

const EN_MONTH_SOURCE = '(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|June?|July?|Aug(?:ust)?|Sept?(?:ember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)';
const RU_MONTH_SOURCE = '(января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря)';

const DOTTED_RE = /(?<![\d.])(\d{1,2})\.(\d{1,2})\.(\d{4})(?![\d])/g;
const ISO_RE = /(?<!\d)(\d{4})-(\d{2})-(\d{2})(?!\d)/g;
const SLASH_RE = /(?<![\d/])(\d{1,2})\/(\d{1,2})\/(\d{4})(?![\d/])/g;
const EN_MDY_RE = new RegExp(`(?<![\\p{L}])${EN_MONTH_SOURCE}\\.?\\s+(\\d{1,2})(?:st|nd|rd|th)?,?\\s+(\\d{4})(?!\\d)`, 'giu');
const EN_DMY_RE = new RegExp(`(?<![\\d])(\\d{1,2})(?:st|nd|rd|th)?\\s+${EN_MONTH_SOURCE}\\.?,?\\s+(\\d{4})(?!\\d)`, 'giu');
const RU_RE = new RegExp(`(?<![\\d])(\\d{1,2})\\s+${RU_MONTH_SOURCE}\\s+(\\d{4})(?:\\s*г\\.?)?`, 'giu');

const DUE_KEYWORDS = keywordRe(['due date', 'payment due', 'pay by', 'due', 'срок оплаты', 'оплатить до', 'оплата до']);
const INVOICE_DATE_KEYWORDS = keywordRe(['invoice date', 'date', 'дата', 'от']);
const KEYWORD_WINDOW = 60;

function isoDate(year: number, month: number, day: number): string | null {
  if (year < 1990 || year > 2100 || month < 1 || month > 12 || day < 1) return null;
  const date = new Date(Date.UTC(year, month - 1, day));
  if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) return null;
  return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

function monthNumber(name: string): number | undefined {
  const lower = name.toLowerCase();
  return EN_MONTHS[lower] ?? EN_MONTHS[lower.slice(0, 3)] ?? RU_MONTHS[lower];
}

export function findDates(text: string, country: string): DateCandidate[] {
  const out: DateCandidate[] = [];
  const push = (value: string | null, match: RegExpExecArray, ambiguous = false): void => {
    if (value) out.push({ value, span: { start: match.index, end: match.index + match[0].length }, ambiguous });
  };
  for (const match of allMatches(DOTTED_RE, text)) push(isoDate(Number(match[3]), Number(match[2]), Number(match[1])), match);
  for (const match of allMatches(ISO_RE, text)) push(isoDate(Number(match[1]), Number(match[2]), Number(match[3])), match);
  for (const match of allMatches(EN_MDY_RE, text)) push(isoDate(Number(match[3]), monthNumber(match[1] ?? '') ?? 0, Number(match[2])), match);
  for (const match of allMatches(EN_DMY_RE, text)) push(isoDate(Number(match[3]), monthNumber(match[2] ?? '') ?? 0, Number(match[1])), match);
  for (const match of allMatches(RU_RE, text)) push(isoDate(Number(match[3]), monthNumber(match[2] ?? '') ?? 0, Number(match[1])), match);
  const us = country.toUpperCase() === 'US';
  for (const match of allMatches(SLASH_RE, text)) {
    const first = Number(match[1]);
    const second = Number(match[2]);
    const year = Number(match[3]);
    const value = us ? isoDate(year, first, second) : isoDate(year, second, first);
    push(value, match, first <= 12 && second <= 12 && first !== second);
  }
  out.sort((a, b) => a.span.start - b.span.start);
  return out.filter((candidate, index) => index === 0 || candidate.span.start >= (out[index - 1]?.span.end ?? 0));
}

function dateAfterKeyword(text: string, dates: readonly DateCandidate[], re: RegExp, skip?: (match: RegExpExecArray) => boolean): DateCandidate | null {
  for (const match of allMatches(re, text)) {
    if (skip?.(match)) continue;
    const end = match.index + match[0].length;
    const found = dates.find((date) => date.span.start >= end && date.span.start - end <= KEYWORD_WINDOW);
    if (found) return found;
  }
  return null;
}

export interface DatePick {
  due: DateCandidate | null;
  invoice: DateCandidate | null;
  invoiceFromKeyword: boolean;
}

export function pickDates(text: string, dates: readonly DateCandidate[]): DatePick {
  const due = dateAfterKeyword(text, dates, DUE_KEYWORDS);
  const invoiceKeyword = dateAfterKeyword(text, dates, INVOICE_DATE_KEYWORDS, (match) => {
    const before = text.slice(Math.max(0, match.index - 12), match.index).toLowerCase();
    return /(?:due|payment)\s*$/.test(before);
  });
  const invoice = invoiceKeyword && invoiceKeyword !== due ? invoiceKeyword : (dates.find((date) => date !== due) ?? null);
  return { due, invoice, invoiceFromKeyword: Boolean(invoiceKeyword && invoiceKeyword !== due) };
}
