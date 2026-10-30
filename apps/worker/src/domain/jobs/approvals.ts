import { GrammyError } from 'grammy';
import { z } from 'zod';
import { renderSp } from '../../bot/modules/scampause/render.js';
import { DEFAULT_LOCALE, isLocale, type Locale } from '../../i18n/index.js';
import type { JobDeps } from '../../jobs/deps.js';
import { SENT_MARKER_TTL_S } from '../../jobs/deps.js';
import { errorMessage, parseOutboxJob, permanent, type JobContext, type JobHandler } from '../../jobs/types.js';
import { QUEUES } from '../../queues/names.js';
import { domainActionSchema, type DomainAction } from '../client.js';
import type { DomainJobServices } from './deps.js';
import { domainStep } from './support.js';

export const APPROVAL_JOBS = {
  requested: 'approval.requested',
  dispatchTelegram: 'approvals.dispatch.telegram',
  dispatchEmail: 'approvals.dispatch.email',
  reminder: 'approval.reminder.due',
  escalation: 'approval.escalation.due',
  expiry: 'approval.expiry.due',
  sweep: 'approvals.sweep',
} as const;

const notificationIdsSchema = z
  .object({ telegram: z.number().int().positive().nullish(), email: z.number().int().positive().nullish() })
  .default({});

export const approvalRequestedSchema = z.object({
  approval_id: z.number().int().positive(),
  payment_request_id: z.number().int().positive(),
  workspace_id: z.number().int().positive(),
  sp_notification_ids: notificationIdsSchema,
  recipient: z.object({
    user_id: z.number().int().positive(),
    locale: z.string().nullish(),
    email: z.string().email().nullish(),
    telegram_user_id: z.number().int().positive().nullish(),
  }),
  telegram: z
    .object({
      template: z.string().min(1).default('sp.approval_requested'),
      params: z.record(z.string(), z.unknown()).default({}),
      actions: z.array(domainActionSchema).default([]),
    })
    .nullish(),
  email: z.object({ subject: z.string().min(1).max(998), html: z.string().nullish(), text: z.string().nullish() }).nullish(),
});
export type ApprovalRequested = z.infer<typeof approvalRequestedSchema>;

const childSchema = z.object({ outbox_id: z.string().min(1), payload: approvalRequestedSchema });

export const reminderDueSchema = z.object({
  approval_id: z.number().int().positive(),
  payment_request_id: z.number().int().positive(),
  workspace_id: z.number().int().positive(),
  sequence: z.number().int().positive(),
});

export const escalationDueSchema = z.object({
  approval_route_id: z.number().int().positive(),
  payment_request_id: z.number().int().positive(),
  workspace_id: z.number().int().positive(),
});

export const expiryDueSchema = escalationDueSchema.extend({ expires_at: z.string().min(1) });

function localeOf(value: string | null | undefined): Locale {
  return isLocale(value) ? value : DEFAULT_LOCALE;
}

function isBlocked(error: unknown): boolean {
  return error instanceof GrammyError && (error.error_code === 403 || (error.error_code === 400 && /chat not found|user is deactivated/i.test(error.description)));
}

async function report(deps: JobDeps, id: number | null | undefined, channel: 'telegram' | 'email', status: 'sent' | 'failed', ctx: JobContext, extra: { error?: string; provider_message_id?: string } = {}): Promise<void> {
  if (!id) return;
  await deps.web.reportDelivery({ reference: { type: 'sp_notification', id }, channel, status, ...extra }, ctx.requestId);
}

function services(deps: JobDeps): DomainJobServices {
  if (!deps.domain) throw permanent('domain services are not configured');
  return deps.domain;
}

async function sendTelegramSpec(
  deps: JobDeps,
  spec: { template: string; params: Record<string, unknown>; actions: DomainAction[] },
  target: { chatId: number | string; telegramUserId: number | null; userId: number | null; workspaceId: number; locale: Locale; replyTo?: number | null },
): Promise<{ message_id: number }> {
  const rendered = await renderSp({
    registry: deps.registry,
    translator: deps.translator,
    actions: deps.actions,
    ttlS: deps.settings.notificationActionTtlS,
    locale: target.locale,
    template: spec.template,
    params: spec.params,
    spActions: spec.actions,
    owner: {
      telegramUserId: target.telegramUserId,
      chatId: typeof target.chatId === 'number' ? target.chatId : Number(target.chatId) || null,
      workspaceId: target.workspaceId,
      userId: target.userId,
    },
  });
  return deps.telegram.sendMessage(target.chatId, rendered.html, rendered.keyboard, target.replyTo ? { replyToMessageId: target.replyTo } : {});
}

export function createApprovalDispatchHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const domain = services(deps);
    const job = parseOutboxJob(data, approvalRequestedSchema);
    const payload = job.payload;
    const queued: string[] = [];
    if (payload.telegram) {
      if (payload.recipient.telegram_user_id) {
        await domain.enqueuer.add(QUEUES.approvals, APPROVAL_JOBS.dispatchTelegram, { outbox_id: job.outbox_id, payload }, `${job.outbox_id}-tg`);
        queued.push('telegram');
      } else {
        await report(deps, payload.sp_notification_ids.telegram, 'telegram', 'failed', ctx, { error: 'telegram_not_linked' });
        domain.metrics.approvalNotifications.inc({ channel: 'telegram', status: 'not_linked' });
      }
    }
    if (payload.email && payload.recipient.email) {
      await domain.enqueuer.add(QUEUES.approvals, APPROVAL_JOBS.dispatchEmail, { outbox_id: job.outbox_id, payload }, `${job.outbox_id}-mail`);
      queued.push('email');
    }
    return { queued };
  };
}

function parseChild(data: unknown): z.infer<typeof childSchema> {
  const parsed = childSchema.safeParse(data);
  if (!parsed.success) throw permanent(`invalid approval dispatch job: ${z.prettifyError(parsed.error)}`);
  return parsed.data;
}

export function createApprovalTelegramHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const domain = services(deps);
    const { outbox_id: outboxId, payload } = parseChild(data);
    const notificationId = payload.sp_notification_ids.telegram ?? null;
    const chatId = payload.recipient.telegram_user_id;
    if (!payload.telegram || !chatId) return { skipped: 'no_telegram' };
    const spec = payload.telegram;
    const markerKey = `sent:sp-tg:${outboxId}`;
    return domainStep(
      ctx,
      domain.metrics,
      APPROVAL_JOBS.dispatchTelegram,
      async () => {
        const existing = await domain.api.getApprovalTelegramMessage(payload.approval_id, payload.workspace_id, ctx.requestId);
        if (existing) return { skipped: 'already_sent', message_id: existing.message_id };
        let messageId = Number((await deps.sentMarkers.get(markerKey)) ?? 0);
        if (!messageId) {
          try {
            const message = await sendTelegramSpec(deps, spec, {
              chatId,
              telegramUserId: chatId,
              userId: payload.recipient.user_id,
              workspaceId: payload.workspace_id,
              locale: localeOf(payload.recipient.locale),
            });
            messageId = message.message_id;
            await deps.sentMarkers.set(markerKey, String(messageId), SENT_MARKER_TTL_S);
          } catch (error) {
            if (isBlocked(error)) {
              await report(deps, notificationId, 'telegram', 'failed', ctx, { error: errorMessage(error) });
              domain.metrics.approvalNotifications.inc({ channel: 'telegram', status: 'failed' });
              return { failed: 'blocked' };
            }
            throw error;
          }
        }
        await domain.api.setApprovalTelegramMessage(
          payload.approval_id,
          { workspace_id: payload.workspace_id, chat_id: chatId, message_id: messageId, sp_notification_id: notificationId },
          ctx.requestId,
        );
        await report(deps, notificationId, 'telegram', 'sent', ctx, { provider_message_id: String(messageId) });
        domain.metrics.approvalNotifications.inc({ channel: 'telegram', status: 'sent' });
        return { message_id: messageId };
      },
      async (error) => {
        domain.metrics.approvalNotifications.inc({ channel: 'telegram', status: 'failed' });
        await report(deps, notificationId, 'telegram', 'failed', ctx, { error: errorMessage(error) });
      },
    );
  };
}

export function createApprovalEmailHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const domain = services(deps);
    const { outbox_id: outboxId, payload } = parseChild(data);
    const notificationId = payload.sp_notification_ids.email ?? null;
    const email = payload.email;
    const to = payload.recipient.email;
    if (!email || !to) return { skipped: 'no_email' };
    const markerKey = `sent:sp-mail:${outboxId}`;
    return domainStep(
      ctx,
      domain.metrics,
      APPROVAL_JOBS.dispatchEmail,
      async () => {
        let messageId = await deps.sentMarkers.get(markerKey);
        if (messageId === null) {
          const sent = await deps.mailer.send({
            from: deps.settings.mailFrom,
            to,
            subject: email.subject,
            ...(email.html ? { html: email.html } : {}),
            ...(email.text ? { text: email.text } : {}),
            messageId: `<${outboxId}.approval@${deps.settings.productSlug}>`,
          });
          messageId = sent.messageId;
          await deps.sentMarkers.set(markerKey, messageId, SENT_MARKER_TTL_S);
        }
        await report(deps, notificationId, 'email', 'sent', ctx, { provider_message_id: messageId });
        domain.metrics.approvalNotifications.inc({ channel: 'email', status: 'sent' });
        return { message_id: messageId };
      },
      async (error) => {
        domain.metrics.approvalNotifications.inc({ channel: 'email', status: 'failed' });
        await report(deps, notificationId, 'email', 'failed', ctx, { error: errorMessage(error) });
      },
    );
  };
}

export function createReminderHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const domain = services(deps);
    const job = parseOutboxJob(data, reminderDueSchema);
    const payload = job.payload;
    return domainStep(
      ctx,
      domain.metrics,
      APPROVAL_JOBS.reminder,
      async () => {
        const response = await domain.api.reminders(payload.approval_id, payload.workspace_id, payload.sequence, ctx.requestId);
        if (!response.send) return { sent: false, reason: response.reason };
        const channels: string[] = [];
        const telegram = response.telegram;
        if (telegram) {
          const markerKey = `sent:sp-remind-tg:${job.outbox_id}`;
          if ((await deps.sentMarkers.get(markerKey)) === null) {
            try {
              const numericChat = typeof telegram.chat_id === 'number' ? telegram.chat_id : Number(telegram.chat_id);
              const message = await sendTelegramSpec(deps, telegram, {
                chatId: telegram.chat_id,
                telegramUserId: Number.isSafeInteger(numericChat) && numericChat > 0 ? numericChat : null,
                userId: null,
                workspaceId: payload.workspace_id,
                locale: localeOf(typeof telegram.params.locale === 'string' ? telegram.params.locale : null),
                replyTo: telegram.reply_to_message_id ?? null,
              });
              await deps.sentMarkers.set(markerKey, String(message.message_id), SENT_MARKER_TTL_S);
              await report(deps, response.sp_notification_ids.telegram, 'telegram', 'sent', ctx, { provider_message_id: String(message.message_id) });
              channels.push('telegram');
            } catch (error) {
              if (!isBlocked(error)) throw error;
              await report(deps, response.sp_notification_ids.telegram, 'telegram', 'failed', ctx, { error: errorMessage(error) });
            }
          }
        }
        const email = response.email;
        if (email) {
          const markerKey = `sent:sp-remind-mail:${job.outbox_id}`;
          if ((await deps.sentMarkers.get(markerKey)) === null) {
            const sent = await deps.mailer.send({
              from: deps.settings.mailFrom,
              to: email.to,
              subject: email.subject,
              ...(email.html ? { html: email.html } : {}),
              ...(email.text ? { text: email.text } : {}),
              messageId: `<${job.outbox_id}.reminder@${deps.settings.productSlug}>`,
            });
            await deps.sentMarkers.set(markerKey, sent.messageId, SENT_MARKER_TTL_S);
            await report(deps, response.sp_notification_ids.email, 'email', 'sent', ctx, { provider_message_id: sent.messageId });
            channels.push('email');
          }
        }
        return { sent: true, channels };
      },
      async (error) => {
        ctx.logger.warn({ approvalId: payload.approval_id, error: errorMessage(error) }, 'approval reminder failed');
      },
    );
  };
}

export function createEscalationHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const domain = services(deps);
    const { payload } = parseOutboxJob(data, escalationDueSchema);
    return domainStep(ctx, domain.metrics, APPROVAL_JOBS.escalation, () => domain.api.escalate(payload.approval_route_id, payload.workspace_id, ctx.requestId), async () => undefined);
  };
}

export function createExpiryHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const domain = services(deps);
    const { payload } = parseOutboxJob(data, expiryDueSchema);
    return domainStep(ctx, domain.metrics, APPROVAL_JOBS.expiry, () => domain.api.expire(payload.approval_route_id, payload.workspace_id, ctx.requestId), async () => undefined);
  };
}

export function createSweepHandler(deps: JobDeps): JobHandler {
  return async (_data: unknown, ctx: JobContext) => {
    const domain = services(deps);
    return domainStep(ctx, domain.metrics, APPROVAL_JOBS.sweep, () => domain.api.sweepApprovals(ctx.requestId), async () => undefined);
  };
}
