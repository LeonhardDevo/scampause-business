import { createHash, randomUUID } from 'node:crypto';
import { z } from 'zod';
import { UpstreamHttpError, UpstreamNetworkError } from '../clients/errors.js';
import { buildPathWithQuery, internalFetch, type InternalTarget, type QueryValue } from '../clients/internalFetch.js';
import { REQUEST_ID_HEADER, SERVICE_HEADER, SIGNATURE_HEADER, signRequest } from '../clients/signing.js';
import type { ExtractionResult } from './extract/types.js';
import type { DomainFacts } from './intel/types.js';

export const DOMAIN_PREFIX = '/internal/v1/scampause';
export const CONTENT_SHA256_HEADER = 'X-Content-Sha256';

export const NO_OP_CONFLICTS: ReadonlySet<string> = new Set(['stale_revision', 'invalid_transition', 'already_processed']);

export function conflictCode(error: unknown): string | null {
  if (!(error instanceof UpstreamHttpError) || error.status !== 409) return null;
  const body = error.body as { error?: unknown } | null;
  return body && typeof body === 'object' && typeof body.error === 'string' ? body.error : 'conflict';
}

export function isNoOpConflict(error: unknown): boolean {
  const code = conflictCode(error);
  return code !== null && NO_OP_CONFLICTS.has(code);
}

export const intakeFileSchema = z.object({
  id: z.number().int().positive(),
  role: z.string(),
  mime: z.string(),
  original_name: z.string().default('file'),
  size_bytes: z.number().int().nonnegative().default(0),
  sha256: z.string().default(''),
  parent_file_id: z.number().int().positive().nullish(),
});
export type IntakeFile = z.infer<typeof intakeFileSchema>;

export const intakeSchema = z
  .object({
    payment_request_id: z.number().int().positive(),
    revision: z.number().int().nonnegative(),
    locale: z.enum(['ru', 'en']).catch('en'),
    country: z.string().default('US'),
    currency: z.string().length(3).nullish(),
    vendor_currency: z.string().length(3).nullish(),
    ai_extract_assist: z.boolean().default(false),
    ai_external_allowed: z.boolean().default(false),
    domains: z.array(z.string()).default([]),
    files: z.array(intakeFileSchema).default([]),
  })
  .passthrough();
export type IntakeInfo = z.infer<typeof intakeSchema>;

export const extractionResponseSchema = z.object({ proceed: z.boolean(), domains: z.array(z.string()).default([]) }).passthrough();
export const domainFactsSchema = z.object({
  domain: z.string(),
  unicode_form: z.string().nullish().transform((value) => value ?? null),
  has_mx: z.boolean().nullish().transform((value) => value ?? null),
  has_spf: z.boolean().nullish().transform((value) => value ?? null),
  dmarc_policy: z.enum(['none', 'quarantine', 'reject', 'absent']).nullish().transform((value) => value ?? null),
  registered_at: z.string().nullish().transform((value) => value ?? null),
  registrar: z.string().nullish().transform((value) => value ?? null),
  rdap_status: z.enum(['ok', 'not_found', 'error', 'skipped']),
  source: z.enum(['live', 'fake']),
  checked_at: z.string(),
});
export const domainIntelResponseSchema = z.object({
  mode: z.enum(['live', 'cache_only', 'fake']),
  facts: z.array(domainFactsSchema).default([]),
  stale: z.array(z.string()).default([]),
});
export type DomainIntelResponse = z.infer<typeof domainIntelResponseSchema>;

export const bankComparisonSchema = z.object({ outcome: z.string() }).passthrough();
export const assessSchema = z.object({ score: z.number().nullish(), level: z.string().nullish(), status: z.string().nullish() }).passthrough();
export const telegramMessageSchema = z.object({
  message: z.object({ chat_id: z.union([z.number(), z.string()]), message_id: z.number().int() }).nullable(),
});

export const domainActionSchema = z.object({
  type: z.string().min(1),
  subject_id: z.number().int().positive(),
  step_token: z.string().default(''),
  reason: z.string().nullish(),
});
export type DomainAction = z.infer<typeof domainActionSchema>;

export const telegramMessageSpecSchema = z.object({
  template: z.string().min(1),
  params: z.record(z.string(), z.unknown()).default({}),
  actions: z.array(domainActionSchema).default([]),
});
export type TelegramMessageSpec = z.infer<typeof telegramMessageSpecSchema>;

export const reminderResponseSchema = z.union([
  z.object({ send: z.literal(false), reason: z.string().default('') }),
  z.object({
    send: z.literal(true),
    telegram: telegramMessageSpecSchema
      .extend({ chat_id: z.union([z.number(), z.string()]), reply_to_message_id: z.number().int().nullish() })
      .nullish(),
    email: z.object({ to: z.string(), subject: z.string(), html: z.string().nullish(), text: z.string().nullish() }).nullish(),
    sp_notification_ids: z.object({ telegram: z.number().int().nullish(), email: z.number().int().nullish() }).default({}),
  }),
]);
export type ReminderResponse = z.infer<typeof reminderResponseSchema>;

export const callbackResponseSchema = z.object({
  answer: z.object({ text: z.string().default(''), show_alert: z.boolean().default(false) }).default({ text: '', show_alert: false }),
  edit: telegramMessageSpecSchema.nullish(),
  send: telegramMessageSpecSchema.nullish(),
});
export type CallbackResponse = z.infer<typeof callbackResponseSchema>;

export const pendingItemSchema = z
  .object({
    workspace: z.object({ id: z.number(), name: z.string() }),
    approval_id: z.number(),
    reference: z.string(),
    title: z.string().nullish(),
    vendor: z.string().nullish(),
    amount_formatted: z.string().nullish(),
    risk_level: z.string().nullish(),
    risk_label: z.string().nullish(),
    expires_at: z.string().nullish(),
    expires_human: z.string().nullish(),
    url: z.string().nullish(),
  })
  .passthrough();
export const pendingResponseSchema = z.object({ items: z.array(pendingItemSchema).default([]) });
export type PendingItem = z.infer<typeof pendingItemSchema>;

export const requestLookupSchema = z.union([
  z.object({ ambiguous: z.array(z.object({ workspace: z.object({ id: z.number(), name: z.string(), slug: z.string().nullish() }), reference: z.string() })) }),
  telegramMessageSpecSchema,
]);
export type RequestLookup = z.infer<typeof requestLookupSchema>;

export const workspacesResponseSchema = z.union([
  z.object({ workspaces: z.array(z.object({ id: z.number(), name: z.string(), slug: z.string().nullish(), current: z.boolean().default(false) })) }),
  z.object({ ok: z.literal(true) }),
]);
export type WorkspacesResponse = z.infer<typeof workspacesResponseSchema>;

export const journalPageSchema = z
  .object({ entries: z.array(z.record(z.string(), z.unknown())).default([]), next_page: z.number().int().nullish(), labels: z.record(z.string(), z.string()).default({}) })
  .passthrough();

export interface TelegramCallbackInput {
  action_type: string;
  subject_id: number;
  workspace_id: number;
  telegram_user_id: number;
  chat_id: number;
  message_id: number | null;
  step_token: string;
  reason?: string;
  language_code: string;
}

export interface DownloadedBytes {
  bytes: Buffer;
  sha256: string | null;
  contentType: string | null;
}

export interface UploadFile {
  filename: string;
  contentType: string;
  bytes: Buffer;
}

export interface DomainWebApi {
  intake(paymentRequestId: number, workspaceId: number, requestId?: string): Promise<IntakeInfo>;
  downloadFile(fileId: number, workspaceId: number, requestId?: string): Promise<DownloadedBytes>;
  uploadAttachment(paymentRequestId: number, fields: { workspace_id: number; revision: number; parent_file_id: number }, file: UploadFile, requestId?: string): Promise<{ file_id: number }>;
  uploadPreview(paymentRequestId: number, fields: { workspace_id: number; revision: number; file_id: number; page: number }, png: Buffer, requestId?: string): Promise<{ file_id: number }>;
  pipeline(paymentRequestId: number, body: { workspace_id: number; revision: number; stage: string; state: string; error?: string }, requestId?: string): Promise<void>;
  submitExtraction(paymentRequestId: number, result: ExtractionResult, requestId?: string): Promise<{ proceed: boolean; domains: string[] }>;
  domainIntel(workspaceId: number, domains: readonly string[], requestId?: string): Promise<DomainIntelResponse>;
  storeDomainIntel(workspaceId: number, facts: readonly DomainFacts[], requestId?: string): Promise<{ stored: number }>;
  bankComparison(paymentRequestId: number, workspaceId: number, revision: number, requestId?: string): Promise<{ outcome: string }>;
  assess(paymentRequestId: number, workspaceId: number, revision: number, requestId?: string): Promise<{ score: number | null; level: string | null; status: string | null }>;
  getApprovalTelegramMessage(approvalId: number, workspaceId: number, requestId?: string): Promise<{ chat_id: number | string; message_id: number } | null>;
  setApprovalTelegramMessage(approvalId: number, body: { workspace_id: number; chat_id: number | string; message_id: number; sp_notification_id: number | null }, requestId?: string): Promise<void>;
  reminders(approvalId: number, workspaceId: number, sequence: number, requestId?: string): Promise<ReminderResponse>;
  escalate(routeId: number, workspaceId: number, requestId?: string): Promise<{ escalated: boolean }>;
  expire(routeId: number, workspaceId: number, requestId?: string): Promise<{ expired: boolean }>;
  sweepApprovals(requestId?: string): Promise<{ expired_route_ids: number[] }>;
  exportData(exportId: number, workspaceId: number, page: number | null, requestId?: string): Promise<unknown>;
  uploadExport(exportId: number, workspaceId: number, file: UploadFile, sha256: string, chainVerified: boolean, requestId?: string): Promise<void>;
  exportFailed(exportId: number, workspaceId: number, error: string, requestId?: string): Promise<void>;
  verifyAllChains(requestId?: string): Promise<{ workspaces: number; broken: number }>;
  telegramCallback(input: TelegramCallbackInput, requestId?: string): Promise<CallbackResponse>;
  telegramWorkspace(telegramUserId: number, workspaceId: number | null, requestId?: string): Promise<WorkspacesResponse>;
  telegramPending(telegramUserId: number, requestId?: string): Promise<PendingItem[]>;
  telegramRequest(telegramUserId: number, reference: string, requestId?: string): Promise<RequestLookup | null>;
}

export class DomainWebClient implements DomainWebApi {
  constructor(private readonly target: InternalTarget) {}

  private async json<T>(method: 'GET' | 'POST', path: string, schema: z.ZodType<T>, options: { body?: unknown; query?: Record<string, QueryValue>; requestId?: string | undefined } = {}): Promise<T> {
    const response = await internalFetch(this.target, {
      method,
      path: `${DOMAIN_PREFIX}${path}`,
      ...(options.body !== undefined ? { body: options.body } : {}),
      ...(options.query ? { query: options.query } : {}),
      ...(options.requestId ? { requestId: options.requestId } : {}),
    });
    return schema.parse(response.data ?? {});
  }

  private headers(method: string, pathWithQuery: string, requestId: string | undefined, body = ''): Record<string, string> {
    return {
      Accept: '*/*',
      [SERVICE_HEADER]: this.target.caller ?? 'worker',
      [REQUEST_ID_HEADER]: requestId ?? randomUUID(),
      [SIGNATURE_HEADER]: signRequest({ secret: this.target.secret, method, pathWithQuery, body }),
    };
  }

  private async raw(method: 'GET' | 'POST', path: string, init: { query?: Record<string, QueryValue>; form?: FormData; contentSha256?: string; requestId?: string | undefined }): Promise<Response> {
    const pathWithQuery = buildPathWithQuery(`${DOMAIN_PREFIX}${path}`, init.query);
    const headers = this.headers(method, pathWithQuery, init.requestId);
    if (init.contentSha256) headers[CONTENT_SHA256_HEADER] = init.contentSha256;
    let response: Response;
    try {
      response = await (this.target.fetch ?? fetch)(`${this.target.baseUrl}${pathWithQuery}`, {
        method,
        headers,
        ...(init.form ? { body: init.form } : {}),
        signal: AbortSignal.timeout(Math.max(this.target.timeoutMs, 60_000)),
      });
    } catch (error) {
      throw new UpstreamNetworkError('web', method, path, error);
    }
    if (!response.ok) {
      const text = await response.text().catch(() => '');
      let body: unknown;
      try {
        body = JSON.parse(text) as unknown;
      } catch {
        body = text.slice(0, 500);
      }
      throw new UpstreamHttpError('web', method, path, response.status, body);
    }
    return response;
  }

  private form(fields: Record<string, string | number | boolean>, file: UploadFile | null, fileField = 'file'): FormData {
    const form = new FormData();
    for (const [key, value] of Object.entries(fields)) form.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
    if (file) form.append(fileField, new Blob([new Uint8Array(file.bytes)], { type: file.contentType }), file.filename);
    return form;
  }

  async intake(paymentRequestId: number, workspaceId: number, requestId?: string): Promise<IntakeInfo> {
    return this.json('GET', `/payment-requests/${paymentRequestId}/intake`, intakeSchema, { query: { workspace_id: workspaceId }, requestId });
  }

  async downloadFile(fileId: number, workspaceId: number, requestId?: string): Promise<DownloadedBytes> {
    const response = await this.raw('GET', `/files/${fileId}`, { query: { workspace_id: workspaceId }, requestId });
    const bytes = Buffer.from(await response.arrayBuffer());
    const declared = response.headers.get('x-sha256');
    if (declared) {
      const actual = createHash('sha256').update(bytes).digest('hex');
      if (actual !== declared.toLowerCase()) throw new UpstreamHttpError('web', 'GET', `/files/${fileId}`, 502, { error: 'sha256_mismatch' });
    }
    return { bytes, sha256: declared, contentType: response.headers.get('content-type') };
  }

  async uploadAttachment(paymentRequestId: number, fields: { workspace_id: number; revision: number; parent_file_id: number }, file: UploadFile, requestId?: string): Promise<{ file_id: number }> {
    const response = await this.raw('POST', `/payment-requests/${paymentRequestId}/files`, {
      form: this.form({ ...fields, role: 'attachment' }, file),
      contentSha256: createHash('sha256').update(file.bytes).digest('hex'),
      requestId,
    });
    return z.object({ file_id: z.number().int().positive() }).parse(await response.json());
  }

  async uploadPreview(paymentRequestId: number, fields: { workspace_id: number; revision: number; file_id: number; page: number }, png: Buffer, requestId?: string): Promise<{ file_id: number }> {
    const response = await this.raw('POST', `/payment-requests/${paymentRequestId}/previews`, {
      form: this.form(fields, { filename: `page-${fields.page}.png`, contentType: 'image/png', bytes: png }, 'png'),
      contentSha256: createHash('sha256').update(png).digest('hex'),
      requestId,
    });
    return z.object({ file_id: z.number().int().positive() }).parse(await response.json());
  }

  async pipeline(paymentRequestId: number, body: { workspace_id: number; revision: number; stage: string; state: string; error?: string }, requestId?: string): Promise<void> {
    await this.json('POST', `/payment-requests/${paymentRequestId}/pipeline`, z.unknown(), { body, requestId });
  }

  async submitExtraction(paymentRequestId: number, result: ExtractionResult, requestId?: string): Promise<{ proceed: boolean; domains: string[] }> {
    return this.json('POST', `/payment-requests/${paymentRequestId}/extraction`, extractionResponseSchema, { body: result, requestId });
  }

  async domainIntel(workspaceId: number, domains: readonly string[], requestId?: string): Promise<DomainIntelResponse> {
    return this.json('GET', '/domain-intel', domainIntelResponseSchema, { query: { workspace_id: workspaceId, domains: domains.join(',') }, requestId });
  }

  async storeDomainIntel(workspaceId: number, facts: readonly DomainFacts[], requestId?: string): Promise<{ stored: number }> {
    return this.json('POST', '/domain-intel', z.object({ stored: z.number().int().default(0) }), { body: { workspace_id: workspaceId, facts }, requestId });
  }

  async bankComparison(paymentRequestId: number, workspaceId: number, revision: number, requestId?: string): Promise<{ outcome: string }> {
    return this.json('POST', `/payment-requests/${paymentRequestId}/bank-comparison`, bankComparisonSchema, { body: { workspace_id: workspaceId, revision }, requestId });
  }

  async assess(paymentRequestId: number, workspaceId: number, revision: number, requestId?: string): Promise<{ score: number | null; level: string | null; status: string | null }> {
    const parsed = await this.json('POST', `/payment-requests/${paymentRequestId}/assess`, assessSchema, { body: { workspace_id: workspaceId, revision }, requestId });
    return { score: parsed.score ?? null, level: parsed.level ?? null, status: parsed.status ?? null };
  }

  async getApprovalTelegramMessage(approvalId: number, workspaceId: number, requestId?: string): Promise<{ chat_id: number | string; message_id: number } | null> {
    const parsed = await this.json('GET', `/approvals/${approvalId}/telegram-message`, telegramMessageSchema, { query: { workspace_id: workspaceId }, requestId });
    return parsed.message;
  }

  async setApprovalTelegramMessage(approvalId: number, body: { workspace_id: number; chat_id: number | string; message_id: number; sp_notification_id: number | null }, requestId?: string): Promise<void> {
    await this.json('POST', `/approvals/${approvalId}/telegram-message`, z.unknown(), { body, requestId });
  }

  async reminders(approvalId: number, workspaceId: number, sequence: number, requestId?: string): Promise<ReminderResponse> {
    return this.json('POST', `/approvals/${approvalId}/reminders`, reminderResponseSchema, { body: { workspace_id: workspaceId, sequence }, requestId });
  }

  async escalate(routeId: number, workspaceId: number, requestId?: string): Promise<{ escalated: boolean }> {
    return this.json('POST', `/approval-routes/${routeId}/escalate`, z.object({ escalated: z.boolean().default(false) }), { body: { workspace_id: workspaceId }, requestId });
  }

  async expire(routeId: number, workspaceId: number, requestId?: string): Promise<{ expired: boolean }> {
    return this.json('POST', `/approval-routes/${routeId}/expire`, z.object({ expired: z.boolean().default(false) }), { body: { workspace_id: workspaceId }, requestId });
  }

  async sweepApprovals(requestId?: string): Promise<{ expired_route_ids: number[] }> {
    return this.json('POST', '/approvals/sweep', z.object({ expired_route_ids: z.array(z.number().int()).default([]) }), { body: {}, requestId });
  }

  async exportData(exportId: number, workspaceId: number, page: number | null, requestId?: string): Promise<unknown> {
    return this.json('GET', `/exports/${exportId}/data`, z.unknown(), { query: { workspace_id: workspaceId, page: page ?? undefined }, requestId });
  }

  async uploadExport(exportId: number, workspaceId: number, file: UploadFile, sha256: string, chainVerified: boolean, requestId?: string): Promise<void> {
    await this.raw('POST', `/exports/${exportId}/file`, {
      form: this.form({ workspace_id: workspaceId, sha256, chain_verified: chainVerified }, file),
      contentSha256: sha256,
      requestId,
    });
  }

  async exportFailed(exportId: number, workspaceId: number, error: string, requestId?: string): Promise<void> {
    await this.json('POST', `/exports/${exportId}/failed`, z.unknown(), { body: { workspace_id: workspaceId, error: error.slice(0, 1000) }, requestId });
  }

  async verifyAllChains(requestId?: string): Promise<{ workspaces: number; broken: number }> {
    return this.json('POST', '/evidence/verify-all', z.object({ workspaces: z.number().int().default(0), broken: z.number().int().default(0) }), { body: {}, requestId });
  }

  async telegramCallback(input: TelegramCallbackInput, requestId?: string): Promise<CallbackResponse> {
    return this.json('POST', '/telegram/callback', callbackResponseSchema, { body: input, requestId });
  }

  async telegramWorkspace(telegramUserId: number, workspaceId: number | null, requestId?: string): Promise<WorkspacesResponse> {
    const body = workspaceId === null ? { telegram_user_id: telegramUserId } : { telegram_user_id: telegramUserId, workspace_id: workspaceId };
    return this.json('POST', '/telegram/workspace', workspacesResponseSchema, { body, requestId });
  }

  async telegramPending(telegramUserId: number, requestId?: string): Promise<PendingItem[]> {
    const parsed = await this.json('GET', '/telegram/pending', pendingResponseSchema, { query: { telegram_user_id: telegramUserId }, requestId });
    return parsed.items;
  }

  async telegramRequest(telegramUserId: number, reference: string, requestId?: string): Promise<RequestLookup | null> {
    try {
      return await this.json('GET', '/telegram/request', requestLookupSchema, { query: { telegram_user_id: telegramUserId, reference }, requestId });
    } catch (error) {
      if (error instanceof UpstreamHttpError && error.status === 404) return null;
      throw error;
    }
  }
}
