import { GrammyError } from 'grammy';
import { describe, expect, it } from 'vitest';
import { createHandlers } from '../../src/jobs/index.js';
import { domainDeps, envelope, jobCtx } from './helpers.js';

const BASE = {
  chat_id: 5003,
  locale: 'en',
  template: 'sp.request_expired',
  params: { workspace: 'Northwind', reference: 'SP-00042', url: 'http://localhost:8300/payment-requests/42' },
  reference: { type: 'sp_notification', id: 91 },
};

describe('telegram.notify with sp templates', () => {
  it('renders sp templates and degrades localhost links to text', async () => {
    const t = domainDeps();
    await createHandlers(t.deps).telegram['telegram.notify']!(envelope('telegram.notify', BASE), jobCtx());
    const [, html, keyboard] = t.telegram.sendMessage.mock.calls[0]!;
    expect(html).toContain('The approval window for SP-00042 has expired.');
    expect(html).toContain('Reopen: http://localhost:8300/payment-requests/42');
    expect(keyboard).toBeUndefined();
  });

  it('edits the original message when asked and falls back to a new one', async () => {
    const t = domainDeps();
    const handlers = createHandlers(t.deps);
    await handlers.telegram['telegram.notify']!(envelope('telegram.notify', { ...BASE, edit: { chat_id: 5003, message_id: 555 } }), jobCtx());
    expect(t.telegram.editMessageText).toHaveBeenCalledWith(5003, 555, expect.stringContaining('SP-00042'), undefined);
    expect(t.telegram.sendMessage).not.toHaveBeenCalled();

    const other = domainDeps();
    other.telegram.editMessageText.mockRejectedValueOnce(new GrammyError('Bad Request: message to edit not found', { ok: false, error_code: 400, description: 'Bad Request: message to edit not found' }, 'editMessageText', {}));
    await createHandlers(other.deps).telegram['telegram.notify']!(envelope('telegram.notify', { ...BASE, edit: { chat_id: 5003, message_id: 556 } }), jobCtx());
    expect(other.telegram.sendMessage).toHaveBeenCalledTimes(1);
  });
});
