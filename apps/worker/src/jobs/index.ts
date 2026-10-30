import { createDomainHandlers } from '../domain/jobs/index.js';
import { JOB_NAMES, QUEUES, type QueueName } from '../queues/names.js';
import {
  createBillingRenewalsHandler,
  createYooKassaNotificationHandler,
} from './billing.js';
import type { JobDeps } from './deps.js';
import { createDocumentDeletedHandler, createDocumentUploadedHandler, createWorkspaceReembedHandler } from './documents.js';
import { createEmailHandler } from './email.js';
import { createExportRequestedHandler, createPrivacyPurgeHandler, createUserPurgeHandler, createWorkspacePurgeHandler } from './privacy.js';
import { createStripeEventHandler } from './stripeEvents.js';
import { createTelegramNotifyHandler } from './telegramNotify.js';
import { createTelegramUpdateHandler } from './telegramUpdates.js';
import type { HandlerMap } from './types.js';

export function createHandlers(deps: JobDeps): Record<QueueName, HandlerMap> {
  return {
    [QUEUES.emails]: { 'email.send': createEmailHandler(deps) },
    [QUEUES.telegram]: { 'telegram.notify': createTelegramNotifyHandler(deps) },
    [QUEUES.documents]: {
      'document.uploaded': createDocumentUploadedHandler(deps),
      'document.deleted': createDocumentDeletedHandler(deps),
      'workspace.reembed': createWorkspaceReembedHandler(deps),
      'workspace.purge': createWorkspacePurgeHandler(deps),
    },
    [QUEUES.stripeEvents]: { [JOB_NAMES.stripeEvent]: createStripeEventHandler(deps) },
    [QUEUES.telegramUpdates]: { [JOB_NAMES.telegramUpdate]: createTelegramUpdateHandler(deps) },
    ...createDomainHandlers(deps),
    [QUEUES.privacy]: {
      'privacy.export.requested': createExportRequestedHandler(deps),
      'user.purge': createUserPurgeHandler(deps),
      [JOB_NAMES.privacyPurge]: createPrivacyPurgeHandler(deps),
    },
    [QUEUES.billing]: {
      [JOB_NAMES.yookassaNotification]: createYooKassaNotificationHandler(deps),
      [JOB_NAMES.billingRenewals]: createBillingRenewalsHandler(deps),
    },
  };
}

export type { JobDeps, TelegramSender } from './deps.js';
export type { HandlerMap, JobContext, JobHandler } from './types.js';
