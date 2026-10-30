export const QUEUES = {
  emails: 'emails',
  telegram: 'telegram',
  documents: 'documents',
  stripeEvents: 'stripe-events',
  telegramUpdates: 'telegram-updates',
  intake: 'intake',
  domainIntel: 'domain-intel',
  approvals: 'approvals',
  audit: 'audit',
  privacy: 'privacy',
  billing: 'billing',
} as const;

export type QueueName = (typeof QUEUES)[keyof typeof QUEUES];

export const ALL_QUEUES: readonly QueueName[] = Object.values(QUEUES);

export const JOB_NAMES = {
  stripeEvent: 'stripe.event',
  telegramUpdate: 'telegram.update',
  privacyPurge: 'privacy.purge.due',
  yookassaNotification: 'yookassa.notification',
  billingRenewals: 'billing.renewals.due',
} as const;

export const QUEUE_SETTINGS: Record<QueueName, { concurrency: number; limiter?: { max: number; duration: number } }> = {
  emails: { concurrency: 5 },
  telegram: { concurrency: 5, limiter: { max: 25, duration: 1000 } },
  documents: { concurrency: 2 },
  'stripe-events': { concurrency: 5 },
  'telegram-updates': { concurrency: 10 },
  intake: { concurrency: 4 },
  'domain-intel': { concurrency: 8, limiter: { max: 5, duration: 1000 } },
  approvals: { concurrency: 8 },
  audit: { concurrency: 2 },
  privacy: { concurrency: 1 },
  billing: { concurrency: 2 },
};
