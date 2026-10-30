import { z } from 'zod';
import { internalFetch, type InternalTarget } from './internalFetch.js';
import { UpstreamHttpError } from './errors.js';

const localeSchema = z
  .string()
  .nullish()
  .transform((value) => (value === 'ru' || value === 'en' ? value : null));

export const telegramContextSchema = z.object({
  user: z.object({ id: z.number(), name: z.string(), locale: localeSchema }),
  workspace: z
    .object({
      id: z.number(),
      name: z.string(),
      plan: z.string().nullish(),
      ai_external_allowed: z.boolean().nullish().transform((value) => value ?? false),
    })
    .nullable(),
  role: z.string().nullish().transform((value) => value ?? 'member'),
  permissions: z.array(z.string()).default([]),
  telegram: z
    .object({
      user_id: z.number().nullish(),
      chat_id: z.number().nullish(),
      username: z.string().nullish(),
    })
    .nullish(),
});
export type TelegramContext = z.infer<typeof telegramContextSchema>;

const linkResponseSchema = z
  .object({
    user: z.object({ id: z.number().optional(), name: z.string().optional(), locale: localeSchema.optional() }).nullish(),
    workspace: z.object({ id: z.number().optional(), name: z.string().optional() }).nullish(),
  })
  .passthrough()
  .nullable();

export interface LinkTelegramInput {
  token: string;
  telegram_user_id: number;
  chat_id: number;
  username: string | null;
  language_code: string | null;
}

export type LinkTelegramResult =
  | { ok: true; userName: string | null; workspaceName: string | null; locale: 'ru' | 'en' | null }
  | { ok: false; reason: 'invalid_token' | 'conflict' };

export interface DeliveryReport {
  reference: { type: string; id: string | number };
  channel: 'email' | 'telegram';
  status: 'sent' | 'failed';
  error?: string;
  provider_message_id?: string;
}

export interface DocumentStatusReport {
  status: 'processing' | 'indexed' | 'failed';
  pages?: number;
  chunks?: number;
  ocr_used?: boolean;
  error?: string;
}

export interface AiQueryLog {
  workspace_id: number;
  user_id: number | null;
  channel: 'telegram' | 'web';
  question: string;
  answer: string | null;
  refused: boolean;
  confidence: number | null;
  citations: unknown[];
  tools_used: string[];
  latency_ms: number | null;
  provider: string | null;
  model: string | null;
}

export interface TelegramActionInput {
  type: string;
  payload: Record<string, unknown>;
  telegram_user_id: number;
  chat_id: number;
  workspace_id: number | null;
  user_id: number | null;
  locale: 'ru' | 'en';
}

const telegramActionResponseSchema = z
  .object({
    ok: z.boolean().default(true),
    message: z.string().nullish(),
    message_html: z.string().nullish(),
    alert: z.boolean().optional(),
  })
  .passthrough();
export type TelegramActionResult = z.infer<typeof telegramActionResponseSchema>;

export type ContextLookup = { telegramUserId: number } | { userId: number };

export const privacyPurgeResponseSchema = z
  .object({
    deletions: z.record(z.string(), z.number()).default({}),
    retention: z.record(z.string(), z.number()).default({}),
    webhook_events_days: z.number().int().positive().default(30),
    ai_memory: z
      .object({
        default_days: z.number().int().positive(),
        workspaces: z.array(z.object({ workspace_id: z.number().int().positive(), days: z.number().int().positive() })).default([]),
      })
      .nullish(),
    skipped: z.boolean().default(false),
  })
  .passthrough();
export type PrivacyPurgeResult = z.infer<typeof privacyPurgeResponseSchema>;

const exportBuildResponseSchema = z.object({ id: z.number(), status: z.string() }).passthrough();
export type ExportBuildResult = z.infer<typeof exportBuildResponseSchema>;

const billingRunResponseSchema = z.record(z.string(), z.union([z.number(), z.boolean()]));
export type BillingRunResult = z.infer<typeof billingRunResponseSchema>;


export interface WebApi {
  ping(requestId?: string): Promise<boolean>;
  linkTelegram(input: LinkTelegramInput, requestId?: string): Promise<LinkTelegramResult>;
  telegramContext(lookup: ContextLookup, requestId?: string): Promise<TelegramContext | null>;
  reportDelivery(report: DeliveryReport, requestId?: string): Promise<void>;
  forwardStripeEvent(event: unknown, requestId?: string): Promise<void>;
  reportDocumentStatus(documentId: number | string, report: DocumentStatusReport, requestId?: string): Promise<void>;
  logAiQuery(entry: AiQueryLog, requestId?: string): Promise<void>;
  telegramAction(input: TelegramActionInput, requestId?: string): Promise<TelegramActionResult>;
  unlinkTelegram(telegramUserId: number, requestId?: string): Promise<boolean>;
  privacyPurge(requestId?: string): Promise<PrivacyPurgeResult>;
  buildExport(exportId: number, requestId?: string): Promise<ExportBuildResult>;
  forwardYooKassaNotification(notification: unknown, requestId?: string): Promise<void>;
  runBillingRenewals(requestId?: string): Promise<BillingRunResult>;
}

export class WebClient implements WebApi {
  constructor(private readonly target: InternalTarget) {}

  async ping(requestId?: string): Promise<boolean> {
    await internalFetch(this.target, { method: 'GET', path: '/internal/v1/ping', requestId });
    return true;
  }

  async linkTelegram(input: LinkTelegramInput, requestId?: string): Promise<LinkTelegramResult> {
    try {
      const response = await internalFetch(this.target, { method: 'POST', path: '/internal/v1/telegram/link', body: input, requestId });
      const parsed = linkResponseSchema.safeParse(response.data);
      const data = parsed.success ? parsed.data : null;
      return {
        ok: true,
        userName: data?.user?.name ?? null,
        workspaceName: data?.workspace?.name ?? null,
        locale: data?.user?.locale ?? null,
      };
    } catch (error) {
      if (error instanceof UpstreamHttpError) {
        if ([400, 404, 410, 422].includes(error.status)) return { ok: false, reason: 'invalid_token' };
        if (error.status === 409) return { ok: false, reason: 'conflict' };
      }
      throw error;
    }
  }

  async telegramContext(lookup: ContextLookup, requestId?: string): Promise<TelegramContext | null> {
    const query = 'telegramUserId' in lookup ? { telegram_user_id: lookup.telegramUserId } : { user_id: lookup.userId };
    try {
      const response = await internalFetch(this.target, { method: 'GET', path: '/internal/v1/telegram/context', query, requestId });
      return telegramContextSchema.parse(response.data);
    } catch (error) {
      if (error instanceof UpstreamHttpError && error.status === 404) return null;
      throw error;
    }
  }

  async reportDelivery(report: DeliveryReport, requestId?: string): Promise<void> {
    await internalFetch(this.target, { method: 'POST', path: '/internal/v1/deliveries', body: report, requestId });
  }

  async forwardStripeEvent(event: unknown, requestId?: string): Promise<void> {
    await internalFetch(this.target, { method: 'POST', path: '/internal/v1/stripe/events', body: event, requestId });
  }

  async reportDocumentStatus(documentId: number | string, report: DocumentStatusReport, requestId?: string): Promise<void> {
    await internalFetch(this.target, {
      method: 'POST',
      path: `/internal/v1/documents/${encodeURIComponent(String(documentId))}/status`,
      body: report,
      requestId,
    });
  }

  async logAiQuery(entry: AiQueryLog, requestId?: string): Promise<void> {
    await internalFetch(this.target, { method: 'POST', path: '/internal/v1/ai-queries', body: entry, requestId });
  }

  async telegramAction(input: TelegramActionInput, requestId?: string): Promise<TelegramActionResult> {
    const response = await internalFetch(this.target, { method: 'POST', path: '/internal/v1/telegram/actions', body: input, requestId });
    return telegramActionResponseSchema.parse(response.data ?? {});
  }

  async unlinkTelegram(telegramUserId: number, requestId?: string): Promise<boolean> {
    try {
      await internalFetch(this.target, { method: 'POST', path: '/internal/v1/telegram/unlink', body: { telegram_user_id: telegramUserId }, requestId });
      return true;
    } catch (error) {
      if (error instanceof UpstreamHttpError && error.status === 404) return false;
      throw error;
    }
  }

  async privacyPurge(requestId?: string): Promise<PrivacyPurgeResult> {
    const response = await internalFetch(this.target, { method: 'POST', path: '/internal/v1/privacy/purge', body: {}, requestId });
    return privacyPurgeResponseSchema.parse(response.data ?? {});
  }

  async buildExport(exportId: number, requestId?: string): Promise<ExportBuildResult> {
    const response = await internalFetch(this.target, {
      method: 'POST',
      path: `/internal/v1/privacy/exports/${encodeURIComponent(String(exportId))}/build`,
      body: {},
      requestId,
    });
    return exportBuildResponseSchema.parse(response.data);
  }

  async forwardYooKassaNotification(notification: unknown, requestId?: string): Promise<void> {
    await internalFetch(this.target, { method: 'POST', path: '/internal/v1/yookassa/notifications', body: notification, requestId });
  }

  async runBillingRenewals(requestId?: string): Promise<BillingRunResult> {
    const response = await internalFetch(this.target, { method: 'POST', path: '/internal/v1/billing/renewals', body: {}, requestId });
    return billingRunResponseSchema.parse(response.data ?? {});
  }
}
