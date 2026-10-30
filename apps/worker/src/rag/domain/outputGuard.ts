import { findPhoneNumbersInText, type CountryCode } from 'libphonenumber-js/max';
import type { Logger } from '../../logger.js';
import { findForbiddenCopy } from '../../domain/copyGuard.js';
import { escapeHtml } from '../answer/sources.js';
import type { ChatOutput, OutputGuard, OutputGuardContext } from '../answer/pipeline.js';
import { markdownToHtml } from '../answer/render.js';
import { answerText } from './answers.js';
import { phoneDigits, placeholdersFor } from './piiPlaceholders.js';

const SENTINEL = '\u0000';
const LABEL_BEFORE_RE = /(?:ИНН|БИК|КПП|ОГРН|ОГРНИП|EIN|INN|BIK|TIN)\s*[:№#]?\s*$/iu;
const HEX64_RE = /(?<![0-9a-f])[0-9a-f]{64}(?![0-9a-f])/gi;
const IBAN_RE = /(?<![A-Z0-9])[A-Z]{2}\d{2}(?:[ \u00A0]?[A-Z0-9]){11,34}(?![A-Z0-9])/g;
const DIGIT_RUN_RE = /(?<![\p{L}\p{N}])\d(?:[\d \u00A0.-]*\d)?(?![\p{L}\p{N}])/gu;
const DATE_LIKE_RE = /^\d{1,4}[.\-/ ]\d{1,2}[.\-/ ]\d{1,4}$/;
const PHONE_LIKE_RE = /(?<![\p{L}\p{N}])\+?\d[\d ()\u00A0.-]{6,}\d(?![\p{L}\p{N}])/gu;
const APPROVE_REQUEST_RE =
  /(?:^|\s)(?:approve|reject|verify|mark\s+(?:it\s+)?(?:as\s+)?paid)\s+(?:the\s+)?(?:payment|request|invoice|sp-\d{5}|it)|(?:согласуй|согласуйте|одобри|одобрите|отклони|отклоните|подтверди\s+платёж|подтверди\s+платеж|отметь\s+оплату)/iu;

export interface OutputGuardOptions {
  publicWebUrl: string;
  logger?: Logger;
}

export function maskAccount(token: string): string {
  const compact = token.replace(/[^0-9A-Za-z]/g, '');
  return `••••${compact.slice(-4)}`;
}

function protect(text: string, re: RegExp, store: string[]): string {
  return text.replace(re, (match) => {
    store.push(match);
    return `${SENTINEL}${store.length - 1}${SENTINEL}`;
  });
}

function unprotect(text: string, store: readonly string[]): string {
  return text.replace(new RegExp(`${SENTINEL}(\\d+)${SENTINEL}`, 'g'), (_match, index: string) => store[Number(index)] ?? '');
}

export interface MaskResult {
  text: string;
  accounts: number;
  phones: number;
}

export function maskSensitive(text: string, allowedPhones: ReadonlySet<string>, hiddenLabel: string, defaultCountry?: CountryCode): MaskResult {
  const store: string[] = [];
  let accounts = 0;
  let phones = 0;
  let out = protect(text, HEX64_RE, store);
  out = out.replace(IBAN_RE, (match) => {
    accounts++;
    return maskAccount(match);
  });
  const candidates: Array<{ start: number; end: number; digits: string }> = [];
  try {
    for (const found of findPhoneNumbersInText(out, defaultCountry ? { defaultCountry } : {})) candidates.push({ start: found.startsAt, end: found.endsAt, digits: phoneDigits(found.number.number) });
  } catch {
    candidates.length = 0;
  }
  for (const match of out.matchAll(PHONE_LIKE_RE)) {
    const start = match.index;
    const raw = match[0];
    const digits = phoneDigits(raw);
    if (raw.trim().startsWith('+') && digits.length >= 10 && digits.length <= 15 && !candidates.some((item) => item.start <= start && item.end >= start + raw.length)) {
      candidates.push({ start, end: start + raw.length, digits });
    }
  }
  candidates.sort((a, b) => b.start - a.start);
  let lastStart = Number.POSITIVE_INFINITY;
  for (const candidate of candidates) {
    if (candidate.end > lastStart) continue;
    lastStart = candidate.start;
    const original = out.slice(candidate.start, candidate.end);
    const before = out.slice(Math.max(0, candidate.start - 16), candidate.start);
    if (LABEL_BEFORE_RE.test(before)) continue;
    if (allowedPhones.has(candidate.digits)) {
      store.push(original);
      out = `${out.slice(0, candidate.start)}${SENTINEL}${store.length - 1}${SENTINEL}${out.slice(candidate.end)}`;
      continue;
    }
    if (!original.trim().startsWith('+') && DATE_LIKE_RE.test(original.trim())) continue;
    phones++;
    out = `${out.slice(0, candidate.start)}${hiddenLabel}${out.slice(candidate.end)}`;
  }
  out = out.replace(DIGIT_RUN_RE, (match: string, offset: number, whole: string) => {
    const digits = match.replace(/\D/g, '');
    if (digits.length < 8 || DATE_LIKE_RE.test(match)) return match;
    if (LABEL_BEFORE_RE.test(whole.slice(Math.max(0, offset - 16), offset))) return match;
    accounts++;
    return maskAccount(match);
  });
  return { text: unprotect(out, store), accounts, phones };
}

function splitSentences(text: string): string[] {
  return text.match(/[^.!?\n]+[.!?]*\s*|\n+/g) ?? [text];
}

export function replaceForbiddenSentences(text: string, disclaimer: string): { text: string; replaced: number } {
  if (findForbiddenCopy(text).length === 0) return { text, replaced: 0 };
  let replaced = 0;
  const out = splitSentences(text)
    .map((sentence) => {
      if (findForbiddenCopy(sentence).length === 0) return sentence;
      replaced++;
      const trailing = /\s*$/.exec(sentence)?.[0] ?? '';
      return `${disclaimer}${trailing}`;
    })
    .join('');
  return { text: out, replaced };
}

export function sanitizeHtml(html: string, publicWebUrl: string, strict: boolean): string {
  const base = publicWebUrl.replace(/\/+$/, '');
  const allowed = strict ? new Set(['b', 'i', 'code', 'a']) : new Set(['p', 'b', 'strong', 'i', 'em', 'code', 'pre', 'ul', 'ol', 'li', 'br', 'a', 'h1', 'h2', 'h3', 'h4', 'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td']);
  const stack: string[] = [];
  return html.replace(/<(\/?)([a-zA-Z][a-zA-Z0-9]*)([^>]*)>/g, (match, slash: string, rawName: string, attrs: string) => {
    let name = rawName.toLowerCase();
    if (strict && name === 'strong') name = 'b';
    if (strict && name === 'em') name = 'i';
    if (!allowed.has(name)) return strict && ['p', 'li', 'br', 'ul', 'ol'].includes(name) && slash === '' ? (name === 'li' ? '\n• ' : '\n') : strict ? '' : escapeHtml(match);
    if (name === 'a') {
      if (slash) {
        const open = stack.pop();
        return open === 'a' ? '</a>' : '';
      }
      const href = /href\s*=\s*"([^"]*)"/i.exec(attrs)?.[1] ?? '';
      if (href.startsWith(`${base}/`) || href === base) {
        stack.push('a');
        return `<a href="${href}">`;
      }
      stack.push('a!');
      return '';
    }
    if (name === 'br') return strict ? '\n' : '<br>';
    return `<${slash}${name}>`;
  });
}

export function createOutputGuard(options: OutputGuardOptions): OutputGuard {
  return (output: ChatOutput, ctx: OutputGuardContext): ChatOutput => {
    const locale = ctx.locale;
    const registry = placeholdersFor(ctx.pool);
    let markdown = output.answer_markdown;
    const actions = [...output.actions];
    if (!output.refused && APPROVE_REQUEST_RE.test(ctx.input.message)) {
      markdown = answerText(locale, 'approval.use_web');
      const url = `${options.publicWebUrl.replace(/\/+$/, '')}/approvals`;
      if (!actions.some((action) => action.type === 'open_url')) actions.push({ type: 'open_url', label: answerText(locale, 'actions.open_approvals'), payload: { url } });
    }
    markdown = registry.restore(markdown);
    const allowed = registry.issuedPhoneDigits();
    const hidden = answerText(locale, 'guard.number_hidden');
    const country: CountryCode = locale === 'ru' ? 'RU' : 'US';
    const masked = maskSensitive(markdown, allowed, hidden, country);
    markdown = masked.text;
    if (masked.phones > 0) {
      markdown = `${markdown}\n\n${answerText(locale, 'guard.official_numbers_only')}`;
      options.logger?.info({ count: masked.phones }, 'guard.redacted_phone');
    }
    if (masked.accounts > 0) options.logger?.info({ count: masked.accounts }, 'guard.redacted_account');
    const forbidden = replaceForbiddenSentences(markdown, answerText(locale, 'guard.disclaimer'));
    markdown = forbidden.text;
    const citations = output.citations.map((citation) => {
      const snippet = maskSensitive(registry.restore(citation.snippet), allowed, hidden, country).text;
      return snippet === citation.snippet ? citation : { ...citation, snippet };
    });
    const html = sanitizeHtml(markdownToHtml(markdown), options.publicWebUrl, ctx.input.channel === 'telegram');
    return { ...output, answer_markdown: markdown, answer_html: html, citations, actions };
  };
}
