import type { BankScheme } from './types.js';
import { allMatches, cleanValue, keywordRe, overlapsAny, TextMap, type Span } from './text.js';
import { IBAN_LENGTHS, isValidAba, isValidBicFormat, isValidIban, isValidRuAccount, normalizeDigits } from './validators.js';

export interface AccountCandidate {
  scheme: BankScheme;
  account: string;
  routing: string | null;
  correspondent: string | null;
  valid: boolean;
  country: string | null;
  span: Span;
  spans: Span[];
}

export interface BicCandidate {
  value: string;
  span: Span;
  valid: boolean;
}

export interface BankDetection {
  candidates: AccountCandidate[];
  bics: BicCandidate[];
  spans: Span[];
}

const IBAN_RE = /(?<![A-Z0-9])[A-Z]{2}\d{2}(?:[ \u00A0]?[A-Z0-9]){11,34}/g;
const IBAN_LABEL_RE = /IBAN/i;
const ROUTING_LABEL_RE = keywordRe(['routing', 'aba', 'rtn']);
const ACCOUNT_LABEL_RE = keywordRe(['account', 'acct', 'a/c']);
const SORT_CODE_LABEL_RE = keywordRe(['sort code']);
const BIK_LABEL_RE = /(?<![\p{L}\p{N}])БИК(?![\p{L}])/giu;
const RU_ACCOUNT_LABEL_RE =
  /(?<![\p{L}\p{N}])(?:р\/с|р\/сч|расч[её]тный\s+сч[её]т|сч[её]т\s+получателя|сч\.?\s*№)/giu;
const RU_CORR_LABEL_RE = /(?<![\p{L}\p{N}])(?:к\/с|корр)/giu;
const BIC_LABEL_RE = /(?<![\p{L}\p{N}])(?:BIC|SWIFT)/giu;
const PAYMENT_KEYWORDS_RE = keywordRe([
  'pay…',
  'remit…',
  'transfer',
  'wire',
  'bank details',
  'beneficiary',
  'оплат…',
  'перечисл…',
  'плат…',
  'реквизит…',
  'получател…',
]);

const ROUTING_WINDOW = 40;
const RU_LABEL_WINDOW = 80;
const BIK_PROXIMITY = 200;
const BIC_WINDOW = 30;

function after(labels: RegExpExecArray[], span: Span, window: number): boolean {
  return labels.some((label) => {
    const end = label.index + label[0].length;
    return span.start >= end && span.start - end <= window;
  });
}

function distance(a: Span, b: Span): number {
  if (a.end <= b.start) return b.start - a.end;
  if (b.end <= a.start) return a.start - b.end;
  return 0;
}

function findIbans(text: string): AccountCandidate[] {
  const out: AccountCandidate[] = [];
  for (const match of allMatches(IBAN_RE, text)) {
    const raw = match[0];
    const country = raw.slice(0, 2);
    const length = IBAN_LENGTHS[country];
    if (length === undefined) continue;
    let count = 0;
    let endOffset = 0;
    for (let index = 0; index < raw.length && count < length; index++) {
      if (/[A-Z0-9]/.test(raw[index] ?? '')) count++;
      endOffset = index + 1;
    }
    if (count < length) continue;
    const iban = raw.slice(0, endOffset).replace(/[ \u00A0]/g, '');
    const span = { start: match.index, end: match.index + endOffset };
    const valid = isValidIban(iban);
    const labelled = IBAN_LABEL_RE.test(text.slice(Math.max(0, span.start - 20), span.start));
    if (!valid && !labelled) continue;
    out.push({ scheme: 'iban', account: iban, routing: null, correspondent: null, valid, country, span, spans: [span] });
  }
  return out;
}

function findUsAch(text: string, ibanSpans: Span[]): AccountCandidate[] {
  const routingLabels = allMatches(ROUTING_LABEL_RE, text);
  if (routingLabels.length === 0) return [];
  const accountLabels = allMatches(ACCOUNT_LABEL_RE, text);
  const routings = allMatches(/(?<!\d)\d{9}(?!\d)/g, text)
    .map((match) => ({ value: match[0], span: { start: match.index, end: match.index + 9 } }))
    .filter((item) => after(routingLabels, item.span, ROUTING_WINDOW));
  if (routings.length === 0) return [];
  const routingSpans = routings.map((item) => item.span);
  const accounts = allMatches(/(?<![\d-])\d{4,17}(?![\d-])/g, text)
    .map((match) => ({ value: match[0], span: { start: match.index, end: match.index + match[0].length } }))
    .filter((item) => !overlapsAny(item.span, routingSpans) && !overlapsAny(item.span, ibanSpans))
    .filter((item) => after(accountLabels, item.span, ROUTING_WINDOW));
  return accounts.map((account) => {
    const routing = [...routings].sort((a, b) => distance(a.span, account.span) - distance(b.span, account.span))[0]!;
    return {
      scheme: 'us_ach' as const,
      account: account.value,
      routing: routing.value,
      correspondent: null,
      valid: isValidAba(routing.value),
      country: 'US',
      span: account.span,
      spans: [account.span, routing.span],
    };
  });
}

function findRu(text: string): AccountCandidate[] {
  const bikLabels = allMatches(BIK_LABEL_RE, text);
  const biks = allMatches(/(?<!\d)04\d{7}(?!\d)/g, text)
    .map((match) => ({ value: match[0], span: { start: match.index, end: match.index + 9 } }))
    .filter((item) => after(bikLabels, item.span, BIC_WINDOW));
  const accountLabels = allMatches(RU_ACCOUNT_LABEL_RE, text);
  const corrLabels = allMatches(RU_CORR_LABEL_RE, text);
  const numbers = [
    ...allMatches(/(?<!\d)\d{20}(?!\d)/g, text),
    ...allMatches(/(?<!\d)\d{4}(?:[ \u00A0]\d{4}){4}(?!\d)/g, text),
  ].map((match) => ({ value: normalizeDigits(match[0]), span: { start: match.index, end: match.index + match[0].length } }));
  const accounts: Array<{ value: string; span: Span }> = [];
  const correspondents: Array<{ value: string; span: Span }> = [];
  for (const item of numbers) {
    const nearBik = biks.some((bik) => distance(bik.span, item.span) <= BIK_PROXIMITY);
    if (nearBik && item.value.startsWith('301')) correspondents.push(item);
    else if (nearBik && /^40[1-8]/.test(item.value)) accounts.push(item);
    else if (after(corrLabels, item.span, RU_LABEL_WINDOW) && item.value.startsWith('301')) correspondents.push(item);
    else if (after(accountLabels, item.span, RU_LABEL_WINDOW) && !item.value.startsWith('301')) accounts.push(item);
  }
  return accounts.map((account) => {
    const bik = [...biks].sort((a, b) => distance(a.span, account.span) - distance(b.span, account.span))[0];
    const corr = [...correspondents].sort((a, b) => distance(a.span, account.span) - distance(b.span, account.span))[0];
    const spans = [account.span, ...(bik ? [bik.span] : []), ...(corr ? [corr.span] : [])];
    return {
      scheme: 'ru_account' as const,
      account: account.value,
      routing: bik?.value ?? null,
      correspondent: corr?.value ?? null,
      valid: bik ? isValidRuAccount(bik.value, account.value) : false,
      country: 'RU',
      span: account.span,
      spans,
    };
  });
}

function findUk(text: string): AccountCandidate[] {
  const labels = allMatches(SORT_CODE_LABEL_RE, text);
  if (labels.length === 0) return [];
  const accountLabels = allMatches(ACCOUNT_LABEL_RE, text);
  const sortCodes = allMatches(/(?<![\d-])\d{2}-\d{2}-\d{2}(?![\d-])/g, text)
    .map((match) => ({ value: normalizeDigits(match[0]), span: { start: match.index, end: match.index + match[0].length } }))
    .filter((item) => after(labels, item.span, ROUTING_WINDOW));
  if (sortCodes.length === 0) return [];
  const accounts = allMatches(/(?<!\d)\d{8}(?!\d)/g, text)
    .map((match) => ({ value: match[0], span: { start: match.index, end: match.index + 8 } }))
    .filter((item) => after(accountLabels, item.span, ROUTING_WINDOW) || sortCodes.some((code) => distance(code.span, item.span) <= 60));
  return accounts.map((account) => {
    const sort = [...sortCodes].sort((a, b) => distance(a.span, account.span) - distance(b.span, account.span))[0]!;
    return {
      scheme: 'uk_sort_code' as const,
      account: account.value,
      routing: sort.value,
      correspondent: null,
      valid: true,
      country: 'GB',
      span: account.span,
      spans: [account.span, sort.span],
    };
  });
}

function findBics(text: string): BicCandidate[] {
  const labels = allMatches(BIC_LABEL_RE, text);
  if (labels.length === 0) return [];
  return allMatches(/(?<![A-Za-z0-9])[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}(?:[A-Z0-9]{3})?(?![A-Za-z0-9])/g, text)
    .map((match) => ({ value: match[0], span: { start: match.index, end: match.index + match[0].length } }))
    .filter((item) => after(labels, item.span, BIC_WINDOW) && item.value !== 'SWIFT' && !item.value.startsWith('IBAN'))
    .map((item) => ({ ...item, valid: isValidBicFormat(item.value) }));
}

export function detectBankDetails(text: string): BankDetection {
  const ibans = findIbans(text);
  const ibanSpans = ibans.map((candidate) => candidate.span);
  const ru = findRu(text);
  const ruSpans = ru.flatMap((candidate) => candidate.spans);
  const us = findUsAch(text, [...ibanSpans, ...ruSpans]);
  const uk = findUk(text).filter((candidate) => !overlapsAny(candidate.span, [...ibanSpans, ...ruSpans]));
  const candidates = [...ibans, ...ru, ...us, ...uk];
  const bics = findBics(text);
  return { candidates, bics, spans: [...candidates.flatMap((candidate) => candidate.spans), ...bics.map((bic) => bic.span)] };
}

export function accountKey(candidate: AccountCandidate): string {
  return `${candidate.scheme}:${candidate.routing ?? ''}:${candidate.account}`;
}

export function choosePayee(text: string, candidates: readonly AccountCandidate[]): { chosen: AccountCandidate | null; multiple: boolean } {
  const unique = new Map<string, AccountCandidate>();
  for (const candidate of candidates) {
    const key = accountKey(candidate);
    const existing = unique.get(key);
    if (!existing || (!existing.valid && candidate.valid)) unique.set(key, candidate);
  }
  const list = [...unique.values()];
  if (list.length === 0) return { chosen: null, multiple: false };
  if (list.length === 1) return { chosen: list[0] ?? null, multiple: false };
  const keywords = allMatches(PAYMENT_KEYWORDS_RE, text).map((match) => ({ start: match.index, end: match.index + match[0].length }));
  const score = (candidate: AccountCandidate): number =>
    keywords.length === 0 ? candidate.span.start : Math.min(...keywords.map((keyword) => distance(keyword, candidate.span)));
  const sorted = [...list].sort((a, b) => Number(b.valid) - Number(a.valid) || score(a) - score(b) || a.span.start - b.span.start);
  return { chosen: sorted[0] ?? null, multiple: true };
}

const HOLDER_RE =
  /^[ \t]*(?:beneficiary(?![ \t]+bank)(?:[ \t]+name)?|account[ \t]+name|account[ \t]+holder|payee(?:[ \t]+name)?|наименование[ \t]+получателя|получатель)(?![\p{L}])[ \t:.\-–—]*(.*)$/imu;
const BANK_RE =
  /^[ \t]*(?:beneficiary[ \t]+bank|bank[ \t]+name|банк[ \t]+получателя|bank|банк)(?![\p{L}])[ \t]*(?::|-|–|—|$)[ \t]*(.*)$/imu;
const BANK_BEFORE_BIK_RE = /^[ \t]*([^\n]*?(?:БАНК|BANK)[^\n]*?)[ \t]+БИК(?![\p{L}])/imu;
const STOP_AFTER_RE = /(?:,?\s*(?:ИНН|КПП|БИК|BIC|SWIFT|IBAN|ABA|routing|account|р\/с|сч\.).*)$/iu;

function labelValue(re: RegExp, text: string, standaloneFromPrevious: (label: string) => boolean = () => false): { value: string; index: number } | null {
  const map = new TextMap(text);
  const global = new RegExp(re.source, `${re.flags.replace('g', '')}g`);
  for (let match = global.exec(text); match !== null; match = global.exec(text)) {
    let value = cleanValue(match[1] ?? '').replace(STOP_AFTER_RE, '').trim();
    if (value === '' || /^(?:details|name|account)$/i.test(value)) {
      const line = map.lineOf(match.index);
      const other = standaloneFromPrevious(match[0]) ? map.previousNonEmptyLine(line) : map.nextNonEmptyLine(line);
      value = other === null ? '' : cleanValue(map.line(other)).replace(STOP_AFTER_RE, '').trim();
    }
    if (value.length >= 2 && /\p{L}/u.test(value)) return { value: value.slice(0, 120), index: match.index };
  }
  return null;
}

export function findAccountHolder(text: string): string | null {
  return labelValue(HOLDER_RE, text, (label) => /получатель/iu.test(label))?.value ?? null;
}

export function findBankName(text: string): string | null {
  const labelled = labelValue(BANK_RE, text)?.value ?? null;
  if (labelled) return labelled;
  const beforeBik = BANK_BEFORE_BIK_RE.exec(text)?.[1];
  return beforeBik ? cleanValue(beforeBik) : null;
}
