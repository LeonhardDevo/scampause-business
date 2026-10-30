import { simpleParser, type AddressObject, type ParsedMail } from 'mailparser';
import type { AuthResult, SenderFields } from './types.js';

export const MAX_EML_ATTACHMENTS = 10;
export const MAX_ATTACHMENT_BYTES = 15 * 1024 * 1024;
export const MAX_EML_DEPTH = 1;
export const EXTRACTABLE_MIMES: ReadonlySet<string> = new Set(['application/pdf', 'image/png', 'image/jpeg', 'image/webp']);

const AUTH_RESULTS: ReadonlySet<string> = new Set(['pass', 'fail', 'softfail', 'neutral', 'none', 'temperror', 'permerror']);

export interface EmlAttachment {
  filename: string;
  contentType: string;
  content: Buffer;
  size: number;
  depth: number;
}

export interface SkippedAttachment {
  filename: string;
  contentType: string;
  reason: 'type_not_allowed' | 'too_large' | 'limit_reached' | 'nesting_too_deep';
}

export interface ParsedEml {
  sender: SenderFields;
  bodyText: string;
  nestedBodies: string[];
  attachments: EmlAttachment[];
  skipped: SkippedAttachment[];
  warnings: string[];
}

export class EmlParseError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'EmlParseError';
  }
}

function addresses(value: AddressObject | AddressObject[] | undefined): Array<{ name: string; address: string }> {
  if (!value) return [];
  const list = Array.isArray(value) ? value : [value];
  return list.flatMap((item) =>
    item.value.flatMap((entry) => {
      const own = entry.address ? [{ name: entry.name ?? '', address: entry.address.toLowerCase() }] : [];
      const group = (entry.group ?? []).filter((member) => member.address).map((member) => ({ name: member.name ?? '', address: (member.address ?? '').toLowerCase() }));
      return [...own, ...group];
    }),
  );
}

function headerLines(mail: ParsedMail, key: string): string[] {
  return mail.headerLines.filter((line) => line.key.toLowerCase() === key).map((line) => line.line.replace(/^[^:]*:\s*/, '').replace(/\r?\n[ \t]+/g, ' '));
}

export function parseAuthenticationResults(values: readonly string[]): { spf: AuthResult | null; dkim: AuthResult | null; dmarc: AuthResult | null } {
  const result: { spf: AuthResult | null; dkim: AuthResult | null; dmarc: AuthResult | null } = { spf: null, dkim: null, dmarc: null };
  for (const value of values) {
    for (const match of value.matchAll(/(?<![\w-])(spf|dkim|dmarc)\s*=\s*([a-z]+)/gi)) {
      const method = (match[1] ?? '').toLowerCase() as 'spf' | 'dkim' | 'dmarc';
      const verdict = (match[2] ?? '').toLowerCase();
      if (result[method] === null && AUTH_RESULTS.has(verdict)) result[method] = verdict as AuthResult;
    }
  }
  return result;
}

export function parseReceivedSpf(values: readonly string[]): AuthResult | null {
  for (const value of values) {
    const verdict = /^\s*([a-z]+)/i.exec(value)?.[1]?.toLowerCase();
    if (verdict && AUTH_RESULTS.has(verdict)) return verdict as AuthResult;
  }
  return null;
}

function angleAddress(value: string): string | null {
  const inner = /<([^<>\s]*)>/.exec(value)?.[1] ?? value.trim();
  return inner.includes('@') ? inner.toLowerCase() : null;
}

export function htmlToText(html: string): string {
  return html
    .replace(/<(script|style|head)[\s\S]*?<\/\1>/gi, ' ')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/(p|div|tr|li|h[1-6]|table)>/gi, '\n')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&quot;/gi, '"')
    .replace(/&#39;|&apos;/gi, "'")
    .replace(/&amp;/gi, '&')
    .replace(/[ \t]+\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}

function senderOf(mail: ParsedMail): SenderFields {
  const from = addresses(mail.from)[0] ?? null;
  const replyTo = addresses(mail.replyTo)[0]?.address ?? null;
  const returnPath = headerLines(mail, 'return-path').map(angleAddress).find((value) => value !== null) ?? null;
  const auth = parseAuthenticationResults(headerLines(mail, 'authentication-results'));
  if (auth.spf === null) auth.spf = parseReceivedSpf(headerLines(mail, 'received-spf'));
  return {
    from_name: from?.name ? from.name.slice(0, 255) : null,
    from_email: from?.address ?? null,
    reply_to: replyTo,
    return_path: returnPath,
    to: addresses(mail.to).map((entry) => entry.address).slice(0, 50),
    subject: mail.subject ? mail.subject.slice(0, 998) : null,
    sent_at: mail.date && !Number.isNaN(mail.date.getTime()) ? mail.date.toISOString() : null,
    message_id: mail.messageId ? mail.messageId.slice(0, 998) : null,
    auth,
    received_hops: Math.min(headerLines(mail, 'received').length, 32767),
  };
}

function bodyOf(mail: ParsedMail): string {
  if (mail.text && mail.text.trim() !== '') return mail.text;
  if (typeof mail.html === 'string') return htmlToText(mail.html);
  return '';
}

export async function parseEml(source: Buffer, depth = 0): Promise<ParsedEml> {
  let mail: ParsedMail;
  try {
    mail = await simpleParser(source, { skipImageLinks: true, skipTextToHtml: true, skipTextLinks: true, maxHtmlLengthToParse: 2_000_000 });
  } catch (error) {
    throw new EmlParseError(`invalid e-mail: ${error instanceof Error ? error.message : String(error)}`);
  }
  const result: ParsedEml = { sender: senderOf(mail), bodyText: bodyOf(mail), nestedBodies: [], attachments: [], skipped: [], warnings: [] };
  for (const attachment of mail.attachments) {
    const filename = (attachment.filename ?? 'attachment').replace(/[\\/:*?"<>|]/g, '_')
      .replace(/[^\P{Cc}]/gu, '_').slice(0, 255);
    const contentType = (attachment.contentType || 'application/octet-stream').toLowerCase();
    if (contentType === 'message/rfc822') {
      if (depth + 1 > MAX_EML_DEPTH) {
        result.skipped.push({ filename, contentType, reason: 'nesting_too_deep' });
        if (!result.warnings.includes('EML_NESTING_TOO_DEEP')) result.warnings.push('EML_NESTING_TOO_DEEP');
        continue;
      }
      const nested = await parseEml(attachment.content, depth + 1).catch(() => null);
      if (!nested) continue;
      if (!result.warnings.includes('EML_NESTED_MESSAGE')) result.warnings.push('EML_NESTED_MESSAGE');
      if (nested.bodyText.trim() !== '') result.nestedBodies.push(nested.bodyText);
      result.nestedBodies.push(...nested.nestedBodies);
      result.skipped.push(...nested.skipped);
      for (const warning of nested.warnings) if (!result.warnings.includes(warning)) result.warnings.push(warning);
      for (const inner of nested.attachments) pushAttachment(result, inner);
      continue;
    }
    if (!EXTRACTABLE_MIMES.has(contentType)) {
      result.skipped.push({ filename, contentType, reason: 'type_not_allowed' });
      continue;
    }
    pushAttachment(result, { filename, contentType, content: attachment.content, size: attachment.content.byteLength, depth });
  }
  return result;
}

function pushAttachment(result: ParsedEml, attachment: EmlAttachment): void {
  if (attachment.size > MAX_ATTACHMENT_BYTES) {
    result.skipped.push({ filename: attachment.filename, contentType: attachment.contentType, reason: 'too_large' });
    return;
  }
  if (result.attachments.length >= MAX_EML_ATTACHMENTS) {
    result.skipped.push({ filename: attachment.filename, contentType: attachment.contentType, reason: 'limit_reached' });
    return;
  }
  result.attachments.push(attachment);
}

export function looksLikeEml(bytes: Buffer, filename: string | null, mime: string): boolean {
  if (mime === 'message/rfc822') return true;
  if (!(filename ?? '').toLowerCase().endsWith('.eml')) return false;
  const head = bytes.subarray(0, 4096).toString('utf8');
  return /^From:/im.test(head) && /^Subject:/im.test(head);
}
