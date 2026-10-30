import { describe, expect, it, vi } from 'vitest';
import { MemoryChatPreferences } from '../../src/db/telegramChats.js';
import { createRegistry } from '../../src/bot/registry.js';
import { coreModule, scampauseModule } from '../../src/bot/modules/index.js';
import { MemoryActionStore, MemoryCache } from '../../src/bot/stores.js';
import { MemoryWebhookEventStore } from '../../src/db/webhookEvents.js';
import { Translator } from '../../src/i18n/index.js';
import type { JobDeps, TelegramSender } from '../../src/jobs/deps.js';
import { createHandlers } from '../../src/jobs/index.js';
import { createSilentLogger } from '../../src/logger.js';
import { fakeAi, fakeWeb, linkedContext } from '../helpers/fakes.js';

describe('sp.payroll_employee_alert', () => {
  it('turns the web actions into Yes/No buttons', async () => {
    const telegram = { sendMessage: vi.fn<TelegramSender['sendMessage']>(async () => ({ message_id: 9 })) };
    const translator = new Translator('ScamPause');
    const deps: JobDeps = {
      web: fakeWeb(new Map([[5001, linkedContext()]])),
      ai: fakeAi(),
      telegramChats: new MemoryChatPreferences(),
      mailer: { send: vi.fn(async () => ({ messageId: 'x' })), verify: vi.fn(async () => true), close: vi.fn() },
      telegram,
      sentMarkers: new MemoryCache(),
      webhookEvents: new MemoryWebhookEventStore(),
      bot: { handleUpdate: vi.fn(async () => undefined) },
      registry: createRegistry([coreModule, scampauseModule], translator),
      translator,
      actions: new MemoryActionStore(),
      settings: { productSlug: 'scampause', mailFrom: 'x@y.test', notificationActionTtlS: 3600 },
    };
    const handlers = createHandlers(deps);
    const payload = {
      user_id: 7,
      locale: 'en',
      template: 'sp.payroll_employee_alert',
      params: { employee_first_name: 'Jordan', company: 'Northwind', payee_masked: '•••• 7321 (US)', bank: 'Wells Fargo Bank' },
      actions: [
        { type: 'sp.pay_me', subject_id: 12, step_token: 'tok', text: 'Yes, it was me' },
        { type: 'sp.pay_not_me', subject_id: 12, step_token: 'tok', text: "No, it wasn't me" },
      ],
      buttons: [],
      reference: { type: 'sp_notification', id: '5' },
    };
    await handlers.telegram['telegram.notify']!(
      { v: 1, outbox_id: '8f9a4a0e-2a54-4e8e-bb0b-0d3c2f1e9a12', topic: 'telegram.notify', workspace_id: 1, occurred_at: new Date().toISOString(), payload },
      { queue: 'q', name: 'n', jobId: 'j', attempt: 1, maxAttempts: 3, isFinalAttempt: false, requestId: 'r', logger: createSilentLogger() },
    );
    const markup = telegram.sendMessage.mock.calls[0]![2];
    expect(markup?.inline_keyboard.flat()).toHaveLength(2);
  });
});
