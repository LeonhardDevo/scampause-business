import { z } from 'zod';
import { answerText } from '../../domain/answers.js';
import { placeholdersFor } from '../../domain/piiPlaceholders.js';
import { ToolError, type ChatAction, type Tool, type ToolContext, type ToolResult } from '../types.js';
import { domainGet, integerArg, NotFound, str, toolResult } from './support.js';

export const AMBIGUITY_GAP = 10;

export const vendorSearchSchema = z.object({
  vendors: z
    .array(
      z
        .object({
          id: z.number().int(),
          name: z.string(),
          legal_name: z.string().nullish(),
          kind: z.string().nullish(),
          status: z.string().nullish(),
          domains: z.array(z.string()).default([]),
          similarity: z.number().default(0),
        })
        .passthrough(),
    )
    .default([]),
});
export type VendorCandidate = z.infer<typeof vendorSearchSchema>['vendors'][number];

export function isAmbiguous(vendors: readonly VendorCandidate[]): boolean {
  const [first, second] = vendors;
  return Boolean(first && second && first.similarity - second.similarity < AMBIGUITY_GAP);
}

export class FindVendorTool implements Tool {
  readonly name = 'find_vendor';
  readonly description =
    'Find vendors/payees in the vendor master by name, alias, legal name or domain. Always call this before vendor-specific tools when you only have a name.';
  readonly parameters = {
    type: 'object',
    properties: {
      query: { type: 'string', minLength: 1, maxLength: 200 },
      limit: { type: 'integer', minimum: 1, maximum: 5, default: 3 },
    },
    required: ['query'],
    additionalProperties: false,
  };
  readonly requiredPermission = 'vendors.view';
  readonly dataTool = true;

  async run(ctx: ToolContext, args: Record<string, unknown>): Promise<ToolResult> {
    const query = typeof args.query === 'string' ? args.query.trim().slice(0, 200) : '';
    if (query === '') throw new ToolError('query is required');
    const limit = Math.max(1, Math.min(5, Number(args.limit ?? 3) || 3));
    const raw = await domainGet<unknown>(ctx, '/vendors/search', { q: query, limit });
    const vendors = raw instanceof NotFound ? [] : vendorSearchSchema.parse(raw).vendors;
    const sorted = [...vendors].sort((a, b) => b.similarity - a.similarity);
    let summary: string;
    const actions: ChatAction[] = [];
    if (sorted.length === 0) summary = answerText(ctx.locale, 'vendor.not_found', { query });
    else if (isAmbiguous(sorted)) {
      const close = sorted.filter((vendor) => (sorted[0]?.similarity ?? 0) - vendor.similarity < AMBIGUITY_GAP);
      summary = answerText(ctx.locale, 'vendor.choose', { query, names: close.map((vendor) => vendor.name).join(', ') });
      for (const vendor of close.slice(0, 3)) actions.push({ type: 'ask', label: vendor.name, payload: { question: vendor.name } });
    } else summary = answerText(ctx.locale, 'vendor.found', { names: sorted.map((vendor) => vendor.name).join(', ') });
    return toolResult(
      this.name,
      {
        summary,
        ambiguous: isAmbiguous(sorted),
        vendors: sorted.map((vendor) => ({
          id: vendor.id,
          name: vendor.name,
          legal_name: vendor.legal_name ?? null,
          kind: vendor.kind ?? null,
          status: vendor.status ?? null,
          domains: vendor.domains,
          similarity: vendor.similarity,
        })),
      },
      actions,
    );
  }
}

const contactsSchema = z.object({
  vendor: z.object({ id: z.number().int(), name: z.string(), kind: z.string().nullish() }),
  callback_contacts: z
    .array(
      z
        .object({
          name: z.string().nullish(),
          role_title: z.string().nullish(),
          phone_e164: z.string().nullish(),
          phone_masked: z.string().nullish(),
          email: z.string().nullish(),
          source: z.string().nullish(),
          source_ref: z.string().nullish(),
          status: z.string().nullish(),
          verified_at: z.string().nullish(),
        })
        .passthrough(),
    )
    .default([]),
  other_contacts_count: z.number().int().default(0),
});

export class GetVendorOfficialContactsTool implements Tool {
  readonly name = 'get_vendor_official_contacts';
  readonly description =
    'Return the official callback contacts recorded for a vendor (numbers taken from contracts or verified sources, never from invoices or e-mails), with their source reference.';
  readonly parameters = {
    type: 'object',
    properties: { vendor_id: { type: 'integer', minimum: 1 } },
    required: ['vendor_id'],
    additionalProperties: false,
  };
  readonly requiredPermission = 'vendors.view';
  readonly dataTool = true;

  async run(ctx: ToolContext, args: Record<string, unknown>): Promise<ToolResult> {
    const vendorId = integerArg(args, 'vendor_id');
    const raw = await domainGet<unknown>(ctx, `/vendors/${vendorId}/contacts`);
    if (raw instanceof NotFound) throw new ToolError('vendor not found');
    const data = contactsSchema.parse(raw);
    const registry = placeholdersFor(ctx.sources);
    const employee = data.vendor.kind === 'employee';
    const contacts = data.callback_contacts
      .filter((contact) => !contact.status || contact.status === 'verified')
      .map((contact) => ({
        name: contact.name ? (employee ? registry.person(contact.name) : contact.name) : null,
        role_title: contact.role_title ?? null,
        phone: contact.phone_e164 ? registry.phone(contact.phone_e164) : (contact.phone_masked ?? null),
        source: contact.source ?? null,
        source_ref: contact.source_ref ?? null,
        verified_at: contact.verified_at ?? null,
      }));
    const first = contacts[0];
    const summary = first
      ? answerText(ctx.locale, 'vendor.official_phone', {
          vendor: data.vendor.name,
          name: str(first.name, ''),
          role: first.role_title ? `, ${first.role_title}` : '',
          phone: str(first.phone),
          source: [first.source, first.source_ref].filter(Boolean).join(' / ') || '—',
        })
      : answerText(ctx.locale, 'vendor.no_contacts', { vendor: data.vendor.name });
    return toolResult(this.name, { summary, vendor: data.vendor, callback_contacts: contacts, other_contacts_count: data.other_contacts_count });
  }
}

const historySchema = z.object({
  vendor: z.object({ id: z.number().int(), name: z.string() }),
  accounts: z
    .array(
      z
        .object({
          id: z.number().int().nullish(),
          scheme: z.string().nullish(),
          bank_name: z.string().nullish(),
          bank_country: z.string().nullish(),
          last4: z.string().nullish(),
          status: z.string().nullish(),
          valid_from: z.string().nullish(),
          valid_to: z.string().nullish(),
          verified_at: z.string().nullish(),
          verified_by_name: z.string().nullish(),
          verification_method: z.string().nullish(),
          retired_reason: z.string().nullish(),
        })
        .passthrough(),
    )
    .default([]),
  blocked_attempts: z
    .array(
      z
        .object({
          last4: z.string().nullish(),
          bank_country: z.string().nullish(),
          created_at: z.string().nullish(),
          reason: z.string().nullish(),
          reference: z.string().nullish(),
        })
        .passthrough(),
    )
    .default([]),
});

function last4(value: string | null | undefined): string {
  const digits = (value ?? '').replace(/[^0-9A-Za-z]/g, '');
  return digits.slice(-4) || '—';
}

export class GetVendorBankHistoryTool implements Tool {
  readonly name = 'get_vendor_bank_history';
  readonly description =
    "Return the history of a vendor's bank accounts (masked: last 4 digits, bank, country, status, validity dates, who verified and how) plus blocked attempts.";
  readonly parameters = {
    type: 'object',
    properties: { vendor_id: { type: 'integer', minimum: 1 } },
    required: ['vendor_id'],
    additionalProperties: false,
  };
  readonly requiredPermission = 'vendors.view_bank';
  readonly dataTool = true;

  async run(ctx: ToolContext, args: Record<string, unknown>): Promise<ToolResult> {
    const vendorId = integerArg(args, 'vendor_id');
    const raw = await domainGet<unknown>(ctx, `/vendors/${vendorId}/bank-history`);
    if (raw instanceof NotFound) throw new ToolError('vendor not found');
    const data = historySchema.parse(raw);
    const registry = placeholdersFor(ctx.sources);
    const accounts = data.accounts.map((account) => ({
      scheme: account.scheme ?? null,
      bank_name: account.bank_name ?? null,
      bank_country: account.bank_country ?? null,
      last4: last4(account.last4),
      status: account.status ?? null,
      valid_from: account.valid_from ?? null,
      valid_to: account.valid_to ?? null,
      verified_at: account.verified_at ?? null,
      verified_by: account.verified_by_name ? registry.person(account.verified_by_name) : null,
      verification_method: account.verification_method ?? null,
      retired_reason: account.retired_reason ?? null,
    }));
    const blocked = data.blocked_attempts.map((attempt) => ({
      last4: last4(attempt.last4),
      bank_country: attempt.bank_country ?? null,
      created_at: attempt.created_at ?? null,
      reason: attempt.reason ?? null,
      reference: attempt.reference ?? null,
    }));
    const lines = accounts.map(
      (account) =>
        answerText(ctx.locale, 'vendor.account', {
          last4: account.last4,
          bank: str(account.bank_name),
          country: str(account.bank_country),
          status: str(account.status),
          from: str(account.valid_from),
          to: account.valid_to ? answerText(ctx.locale, 'vendor.account_to', { to: account.valid_to }) : '',
        }) + (account.verified_by ? answerText(ctx.locale, 'vendor.account_verified', { by: account.verified_by, method: str(account.verification_method) }) : ''),
    );
    const parts = [
      accounts.length > 0
        ? answerText(ctx.locale, 'vendor.bank_history', { vendor: data.vendor.name, accounts: lines.join('; ') })
        : answerText(ctx.locale, 'vendor.no_accounts', { vendor: data.vendor.name }),
    ];
    if (blocked.length > 0) {
      parts.push(
        answerText(ctx.locale, 'vendor.blocked', {
          attempts: blocked
            .map((attempt) =>
              answerText(ctx.locale, 'vendor.blocked_item', {
                last4: attempt.last4,
                country: str(attempt.bank_country),
                date: str(attempt.created_at),
                reason: str(attempt.reason),
                reference: str(attempt.reference),
              }),
            )
            .join('; '),
        }),
      );
    }
    return toolResult(this.name, { summary: parts.join(' '), vendor: data.vendor, accounts, blocked_attempts: blocked });
  }
}
