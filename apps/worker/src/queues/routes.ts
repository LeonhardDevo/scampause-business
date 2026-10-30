import { QUEUES, type QueueName } from './names.js';

export const TOPIC_ROUTES: Readonly<Record<string, QueueName>> = Object.freeze({
  'email.send': QUEUES.emails,
  'telegram.notify': QUEUES.telegram,
  'document.uploaded': QUEUES.documents,
  'document.deleted': QUEUES.documents,
  'workspace.reembed': QUEUES.documents,
  'intake.received': QUEUES.intake,
  'approval.requested': QUEUES.approvals,
  'approval.reminder.due': QUEUES.approvals,
  'approval.escalation.due': QUEUES.approvals,
  'approval.expiry.due': QUEUES.approvals,
  'evidence.packet.requested': QUEUES.audit,
  'audit.journal.requested': QUEUES.audit,
  'workspace.purge': QUEUES.documents,
  'privacy.export.requested': QUEUES.privacy,
  'privacy.purge.due': QUEUES.privacy,
  'user.purge': QUEUES.privacy,
});

export function resolveQueue(topic: string): QueueName | null {
  return Object.hasOwn(TOPIC_ROUTES, topic) ? (TOPIC_ROUTES[topic] ?? null) : null;
}

export function topicsForQueue(queue: QueueName): string[] {
  return Object.entries(TOPIC_ROUTES)
    .filter(([, target]) => target === queue)
    .map(([topic]) => topic);
}
