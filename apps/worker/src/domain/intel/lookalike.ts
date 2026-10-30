import { domainToASCII, domainToUnicode } from 'node:url';
import { parse } from 'tldts';

export const FREE_MAIL_DOMAINS: ReadonlySet<string> = new Set([
  'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'yahoo.com', 'icloud.com', 'me.com',
  'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'gmx.de', 'zoho.com', 'mail.ru', 'inbox.ru', 'list.ru', 'bk.ru',
  'yandex.ru', 'ya.ru', 'yandex.com', 'rambler.ru',
]);

export type LookalikeMethod = 'edit_distance' | 'homoglyph' | 'affix' | 'tld_swap';

export interface LookalikeMatch {
  domain: string;
  known: string;
  method: LookalikeMethod;
}

export interface DomainParts {
  ascii: string;
  unicode: string;
  label: string;
  suffix: string;
}

const AFFIXES = ['billing', 'invoice', 'invoices', 'pay', 'payments', 'accounts', 'finance', 'ar', 'corp', 'inc', 'group', 'co'];

const CYRILLIC_SKELETON: Readonly<Record<string, string>> = Object.freeze({
  а: 'a', е: 'e', о: 'o', р: 'p', с: 'c', у: 'y', х: 'x', і: 'i', ј: 'j',
});

export function registrableParts(raw: string): DomainParts | null {
  const trimmed = raw.trim().toLowerCase().replace(/\.$/, '');
  if (trimmed === '') return null;
  const ascii = domainToASCII(trimmed);
  if (ascii === '') return null;
  const parsed = parse(ascii, { allowPrivateDomains: false });
  if (!parsed.domain || !parsed.publicSuffix) return null;
  const label = parsed.domainWithoutSuffix ?? parsed.domain.slice(0, -(parsed.publicSuffix.length + 1));
  return { ascii: parsed.domain, unicode: domainToUnicode(parsed.domain), label, suffix: parsed.publicSuffix };
}

export function damerauLevenshtein(a: string, b: string): number {
  const rows = a.length + 1;
  const cols = b.length + 1;
  const d: number[][] = Array.from({ length: rows }, (_, i) => Array.from({ length: cols }, (_, j) => (i === 0 ? j : j === 0 ? i : 0)));
  for (let i = 1; i < rows; i++) {
    for (let j = 1; j < cols; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1;
      const row = d[i]!;
      const prev = d[i - 1]!;
      row[j] = Math.min(prev[j]! + 1, row[j - 1]! + 1, prev[j - 1]! + cost);
      if (i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) row[j] = Math.min(row[j]!, d[i - 2]![j - 2]! + 1);
    }
  }
  return d[a.length]![b.length]!;
}

export function skeleton(label: string): string {
  let out = '';
  for (const char of label.toLowerCase()) out += CYRILLIC_SKELETON[char] ?? char;
  return out
    .replace(/rn/g, 'm')
    .replace(/vv/g, 'w')
    .replace(/cl/g, 'd')
    .replace(/0/g, 'o')
    .replace(/[1i]/g, 'l');
}

function isAffixed(candidate: string, known: string): boolean {
  for (const affix of AFFIXES) {
    for (const glue of ['', '-']) {
      if (candidate === `${known}${glue}${affix}` || candidate === `${affix}${glue}${known}`) return true;
    }
  }
  return false;
}

export function lookalike(domain: string, known: string): LookalikeMatch | null {
  const d = registrableParts(domain);
  const k = registrableParts(known);
  if (!d || !k || d.ascii === k.ascii) return null;
  const ld = d.label;
  const lk = k.label;
  if (ld === lk) return d.suffix !== k.suffix ? { domain: d.ascii, known: k.ascii, method: 'tld_swap' } : null;
  const unicodeLabel = d.unicode.slice(0, d.unicode.length - d.suffix.length - 1) || ld;
  if (skeleton(unicodeLabel) === skeleton(lk) || skeleton(ld) === skeleton(lk)) return { domain: d.ascii, known: k.ascii, method: 'homoglyph' };
  const distance = damerauLevenshtein(ld, lk);
  if ((lk.length >= 8 && distance <= 2) || (lk.length >= 4 && lk.length < 8 && distance <= 1)) {
    return { domain: d.ascii, known: k.ascii, method: 'edit_distance' };
  }
  if (isAffixed(ld, lk)) return { domain: d.ascii, known: k.ascii, method: 'affix' };
  return null;
}

export function findLookalikes(domains: readonly string[], known: readonly string[]): LookalikeMatch[] {
  const out: LookalikeMatch[] = [];
  for (const domain of domains) {
    for (const candidate of known) {
      const match = lookalike(domain, candidate);
      if (match) {
        out.push(match);
        break;
      }
    }
  }
  return out;
}

export function isFreeMail(domain: string): boolean {
  const parts = registrableParts(domain);
  return parts !== null && FREE_MAIL_DOMAINS.has(parts.ascii);
}
