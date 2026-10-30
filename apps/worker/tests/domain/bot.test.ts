import type { FastifyInstance } from 'fastify';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type { OutboundCall } from '../../src/bot/capture.js';
import { defaultModules } from '../../src/bot/modules/index.js';
import { renderSp } from '../../src/bot/modules/scampause/render.js';
import { SP_TEMPLATES } from '../../src/bot/modules/scampause/templates.js';
import { MemoryActionStore, type StoredAction } from '../../src/bot/stores.js';
import { UpstreamHttpError } from '../../src/clients/errors.js';
import type { CallbackResponse } from '../../src/domain/client.js';
import { createTestBot, devServer, sendDev, sentMessages, type TestBot } from '../helpers/bot.js';
import { linkedContext } from '../helpers/fakes.js';
import { fakeDomainApi, type FakeDomainApi } from './helpers.js';

const MARIA = { id: 5001, username: 'maria', first_name: 'Maria', language_code: 'en' };
const OLGA = { id: 7001, username: 'olga', first_name: 'Ольга', language_code: 'ru' };
const STRANGER = { id: 9009, username: 'x', first_name: 'X', language_code: 'en' };
const PUBLIC = 'https://app.scampause.com';

let bot: TestBot;
let server: FastifyInstance;
let api: FakeDomainApi;

async function setup(webPublicUrl = PUBLIC): Promise<void> {
  const contexts = new Map([
    [MARIA.id, linkedContext({ user: { id: 2, name: 'Maria Lopez', locale: 'en' }, telegram: { user_id: MARIA.id, chat_id: MARIA.id, username: 'maria' } })],
    [OLGA.id, linkedContext({ user: { id: 11, name: 'Ольга', locale: 'ru' }, workspace: { id: 2, name: 'Кедр и Партнёры', plan: 'starter', ai_external_allowed: false }, telegram: { user_id: OLGA.id, chat_id: OLGA.id, username: 'olga' } })],
  ]);
  bot = createTestBot({ contexts, webPublicUrl });
  api = fakeDomainApi();
  bot.services.scampause = api;
  server = await devServer(bot.handle);
}

beforeEach(async () => {
  await setup();
});

afterEach(async () => {
  await server.close();
});

function calls(outbound: OutboundCall[], method: string): Array<Record<string, unknown>> {
  return outbound.filter((call) => call.method === method).map((call) => call.payload);
}

function keyboardTexts(payload: Record<string, unknown> | undefined): string[] {
  const markup = payload?.reply_markup as { inline_keyboard?: Array<Array<{ text: string }>> } | undefined;
  return (markup?.inline_keyboard ?? []).flat().map((button) => button.text);
}

function callbackIds(payload: Record<string, unknown> | undefined): string[] {
  const markup = payload?.reply_markup as { inline_keyboard?: Array<Array<{ callback_data?: string }>> } | undefined;
  return (markup?.inline_keyboard ?? []).flat().map((button) => button.callback_data ?? '').filter(Boolean);
}

async function storeAction(type: string, payload: Record<string, unknown>, telegramUserId = MARIA.id, workspaceId = 1): Promise<string> {
  const action: StoredAction = { type, payload, telegram_user_id: telegramUserId, chat_id: telegramUserId, workspace_id: workspaceId, user_id: 2, locale: 'en', created_at: Date.now() };
  return `a:${await bot.actions.put(action, 600)}`;
}

describe('scampause bot module', () => {
  it('is installed between core and the assistant catch-all', () => {
    expect(defaultModules().map((module) => module.name)).toEqual(['core', 'privacy', 'scampause', 'assistant']);
  });

  it('answers /privacy with the foundation notice followed by the payment-check paragraph', async () => {
    const [english] = sentMessages(await sendDev(server, { from: MARIA, text: '/privacy' }));
    expect(english?.text).toContain('What we store');
    expect(english?.text).toContain(`${PUBLIC}/legal/privacy`);
    expect(english?.text).toContain('Payments and checks');
    expect(english?.text).toContain('it is not a guarantee. Independent verification is required.');
    expect(english?.text.indexOf('What we store')).toBeLessThan(english?.text.indexOf('Payments and checks') ?? -1);

    const [russian] = sentMessages(await sendDev(server, { from: OLGA, text: '/privacy' }));
    expect(russian?.text).toContain('Платежи и проверки');
    expect(russian?.text).toContain('не гарантирует безопасность платежа. Требуется независимая проверка.');
    expect(russian?.text).not.toMatch(/\d{8,}/);
  });

  it('lists pending approvals across workspaces with plural headers and safe links', async () => {
    api.telegramPending.mockResolvedValueOnce([
      { workspace: { id: 1, name: 'Northwind' }, approval_id: 9, reference: 'SP-00038', vendor: 'Andes <Green>', amount_formatted: '€13,250.00', risk_level: 'low', risk_label: 'Low', expires_human: '20 h', url: `${PUBLIC}/payment-requests/38` },
      { workspace: { id: 2, name: 'Кедр и Партнёры' }, approval_id: 12, reference: 'SP-00013', vendor: 'Стройматериалы Север', amount_formatted: '248 300,00 ₽', risk_level: 'low', risk_label: 'Low', expires_human: '2 d', url: 'http://localhost:8300/payment-requests/13' },
    ]);
    const outbound = await sendDev(server, { from: MARIA, text: '/pending' });
    const [message] = sentMessages(outbound);
    expect(message?.text).toContain('Waiting for your decision: 2');
    expect(message?.text).toContain('Northwind');
    expect(message?.text).toContain('Кедр и Партнёры');
    expect(message?.text).toContain('Andes &lt;Green&gt;');
    expect(message?.text).toContain('Link: http://localhost:8300/payment-requests/13');
    expect(keyboardTexts(message as unknown as Record<string, unknown>)).toEqual(['Open SP-00038']);
    expect(api.telegramPending).toHaveBeenCalledWith(MARIA.id, expect.any(String));
  });

  it('uses Russian plural forms for the pending header', async () => {
    api.telegramPending.mockResolvedValueOnce(
      Array.from({ length: 5 }, (_, index) => ({ workspace: { id: 2, name: 'Кедр' }, approval_id: index + 1, reference: `SP-0001${index}`, url: null })),
    );
    const outbound = await sendDev(server, { from: OLGA, text: '/pending' });
    expect(sentMessages(outbound)[0]?.text).toContain('Ожидают вашего решения: 5 запросов');
    api.telegramPending.mockResolvedValueOnce([]);
    expect(sentMessages(await sendDev(server, { from: OLGA, text: '/pending' }))[0]?.text).toBe('Нет запросов, ожидающих вашего согласования.');
  });

  it('answers only in private chats and asks unlinked users to link', async () => {
    const group = await sendDev(server, { from: MARIA, chat_id: -100, text: '/pending' });
    expect(sentMessages(group)[0]?.text).toBe('Use me in a private chat.');
    expect(api.telegramPending).not.toHaveBeenCalled();
    const unlinked = await sendDev(server, { from: STRANGER, text: '/pending' });
    expect(sentMessages(unlinked)[0]?.text).toContain('not linked');
  });

  it('shows the Stop. Call. Confirm. card in both languages and in /help', async () => {
    expect(sentMessages(await sendDev(server, { from: MARIA, text: '/stop' }))[0]?.text).toContain('Stop. Call. Confirm.');
    expect(sentMessages(await sendDev(server, { from: OLGA, text: '/stop' }))[0]?.text).toContain('Стоп. Позвони. Подтверди.');
    const help = sentMessages(await sendDev(server, { from: MARIA, text: '/help' }))[0]?.text ?? '';
    expect(help).toContain('/pending — My approvals');
    expect(help).toContain('3. Confirm');
  });

  it('switches the workspace through web and refreshes the cached account', async () => {
    api.telegramWorkspace.mockResolvedValueOnce({ workspaces: [{ id: 1, name: 'Northwind', slug: 'northwind', current: true }, { id: 2, name: 'Кедр', slug: 'kedr', current: false }] });
    const list = await sendDev(server, { from: MARIA, text: '/workspace' });
    const message = calls(list, 'sendMessage')[0];
    expect(keyboardTexts(message)).toEqual(['Northwind (current)', 'Кедр']);
    const pressed = await sendDev(server, { from: MARIA, callback_data: callbackIds(message)[1]! });
    expect(api.telegramWorkspace).toHaveBeenLastCalledWith(MARIA.id, 2, expect.any(String));
    expect(calls(pressed, 'editMessageText')[0]?.text).toContain('Workspace switched to <b>Кедр</b>.');
  });

  it('shows a request status and a chooser when the reference is ambiguous', async () => {
    api.telegramRequest.mockResolvedValueOnce({ template: 'sp.request_status', params: { reference: 'SP-00042', status_label: 'Awaiting approval', vendor: 'Blue Ridge', amount: '$7,215.00', risk_label: 'Low', score: 0, url: `${PUBLIC}/payment-requests/42` }, actions: [] });
    const status = sentMessages(await sendDev(server, { from: MARIA, text: '/request sp-00042' }))[0];
    expect(api.telegramRequest).toHaveBeenLastCalledWith(MARIA.id, 'SP-00042', expect.any(String));
    expect(status?.text).toContain('SP-00042 · Awaiting approval');
    expect(status?.text).toContain('does not mean the payment is safe');

    api.telegramRequest.mockResolvedValueOnce({ ambiguous: [{ workspace: { id: 1, name: 'Northwind', slug: 'northwind' }, reference: 'SP-00042' }, { workspace: { id: 2, name: 'Кедр', slug: 'kedr' }, reference: 'SP-00042' }] });
    const chooser = calls(await sendDev(server, { from: MARIA, text: '/request SP-00042' }), 'sendMessage')[0];
    expect(keyboardTexts(chooser)).toEqual(['Northwind · SP-00042', 'Кедр · SP-00042']);
    api.telegramRequest.mockResolvedValueOnce(null);
    await sendDev(server, { from: MARIA, callback_data: callbackIds(chooser)[1]! });
    expect(api.telegramRequest).toHaveBeenLastCalledWith(MARIA.id, 'kedr/SP-00042', expect.any(String));

    expect(sentMessages(await sendDev(server, { from: MARIA, text: '/request 42' }))[0]?.text).toContain('/request SP-00042');
  });

  it('approves in two steps through web', async () => {
    const confirm: CallbackResponse = {
      answer: { text: '', show_alert: false },
      edit: {
        template: 'sp.approve_confirm',
        params: { reference: 'SP-00038', amount: '€13,250.00', vendor: 'Andes Green', payee_masked: 'CaixaBank · ES · •••• 6789' },
        actions: [{ type: 'sp.apv_confirm', subject_id: 9, step_token: 'tok2' }, { type: 'sp.cancel', subject_id: 9, step_token: 'tok2' }],
      },
    };
    api.telegramCallback.mockResolvedValueOnce(confirm);
    const first = await sendDev(server, { from: MARIA, callback_data: await storeAction('sp.apv', { subject_id: 9, step_token: 'tok1' }), message_id: 77 });
    expect(api.telegramCallback).toHaveBeenLastCalledWith(
      { action_type: 'sp.apv', subject_id: 9, workspace_id: 1, telegram_user_id: MARIA.id, chat_id: MARIA.id, message_id: 77, step_token: 'tok1', language_code: 'en' },
      expect.any(String),
    );
    const edit = calls(first, 'editMessageText')[0];
    expect(edit?.text).toContain('Confirm approval of SP-00038: €13,250.00 to Andes Green');
    expect(keyboardTexts(edit)).toEqual(['Confirm approval', 'Cancel']);

    api.telegramCallback.mockResolvedValueOnce({ answer: { text: 'Done', show_alert: false }, edit: { template: 'sp.approved_ack', params: { reference: 'SP-00038', time_local: '14:32', remaining: 0 }, actions: [] } });
    const second = await sendDev(server, { from: MARIA, callback_data: callbackIds(edit)[0]! });
    expect(api.telegramCallback).toHaveBeenLastCalledWith(expect.objectContaining({ action_type: 'sp.apv_confirm', step_token: 'tok2' }), expect.any(String));
    expect(calls(second, 'answerCallbackQuery')[0]).toMatchObject({ text: 'Done', show_alert: false });
    expect(calls(second, 'editMessageText')[0]?.text).toContain('Approved by you · 14:32. Approvals remaining: 0.');
    expect(keyboardTexts(calls(second, 'editMessageText')[0])).toEqual([]);
  });

  it('shows an alert when Telegram approval is not allowed and keeps Reject + Open', async () => {
    api.telegramCallback.mockResolvedValueOnce({
      answer: { text: 'This request can only be approved in the web app: high risk. You can still reject it here.', show_alert: true },
      edit: { template: 'sp.telegram_approve_blocked', params: { reason: 'high_risk', url: `${PUBLIC}/payment-requests/40` }, actions: [{ type: 'sp.rej', subject_id: 9, step_token: 'tok1' }] },
    });
    const outbound = await sendDev(server, { from: MARIA, callback_data: await storeAction('sp.apv', { subject_id: 9, step_token: 'tok1' }) });
    expect(calls(outbound, 'answerCallbackQuery')[0]).toMatchObject({ show_alert: true });
    expect(keyboardTexts(calls(outbound, 'editMessageText')[0])).toEqual(['Reject', 'Open case file']);
  });

  it('rejects with a reason chosen from the keyboard', async () => {
    const reasons = ['fraud_confirmed', 'details_mismatch', 'not_ordered', 'duplicate', 'other'];
    api.telegramCallback.mockResolvedValueOnce({
      answer: { text: '', show_alert: false },
      edit: { template: 'sp.reject_reason_prompt', params: { reference: 'SP-00040' }, actions: [...reasons.map((reason) => ({ type: 'sp.rej_reason', subject_id: 9, step_token: 'tok3', reason })), { type: 'sp.cancel', subject_id: 9, step_token: 'tok3' }] },
    });
    const prompt = await sendDev(server, { from: OLGA, callback_data: await storeAction('sp.rej', { subject_id: 9, step_token: 'tok1' }, OLGA.id, 2) });
    const edit = calls(prompt, 'editMessageText')[0];
    expect(edit?.text).toContain('Почему отклоняете SP-00040?');
    expect(keyboardTexts(edit)).toEqual(['Мошенничество подтверждено', 'Реквизиты не совпали', 'Не заказывали', 'Дубликат', 'Другое', 'Отмена']);
    api.telegramCallback.mockResolvedValueOnce({ answer: { text: 'Готово', show_alert: false }, edit: { template: 'sp.rejected_ack', params: { reference: 'SP-00040', reason_label: 'Мошенничество подтверждено', blocked: true }, actions: [] } });
    const done = await sendDev(server, { from: OLGA, callback_data: callbackIds(edit)[0]! });
    expect(api.telegramCallback).toHaveBeenLastCalledWith(expect.objectContaining({ action_type: 'sp.rej_reason', reason: 'fraud_confirmed', workspace_id: 2 }), expect.any(String));
    expect(calls(done, 'editMessageText')[0]?.text).toContain('Реквизиты добавлены в список заблокированных.');
  });

  it('turns web refusals into alerts: stale token, lost eligibility, someone else’s button', async () => {
    api.telegramCallback.mockRejectedValueOnce(new UpstreamHttpError('web', 'POST', '/x', 409, { error: 'stale_step_token' }));
    const stale = await sendDev(server, { from: MARIA, callback_data: await storeAction('sp.apv', { subject_id: 9, step_token: 'old' }) });
    expect(calls(stale, 'answerCallbackQuery')[0]).toMatchObject({ text: 'This button is no longer valid. Open the request in the web app.', show_alert: true });

    api.telegramCallback.mockRejectedValueOnce(new UpstreamHttpError('web', 'POST', '/x', 409, { error: 'approver_no_longer_eligible', message: 'You can no longer approve this request.' }));
    const lost = await sendDev(server, { from: MARIA, callback_data: await storeAction('sp.apv_confirm', { subject_id: 9, step_token: 'tok2' }) });
    expect(calls(lost, 'answerCallbackQuery')[0]).toMatchObject({ text: 'You can no longer approve this request.', show_alert: true });

    const foreign = await sendDev(server, { from: STRANGER, callback_data: await storeAction('sp.apv', { subject_id: 9, step_token: 'tok' }) });
    expect(calls(foreign, 'answerCallbackQuery')[0]).toMatchObject({ text: 'This button belongs to another user.', show_alert: true });
  });

  it('records payroll answers from foundation notification buttons', async () => {
    api.telegramCallback.mockResolvedValueOnce({ answer: { text: 'Recorded', show_alert: false }, edit: { template: 'sp.payroll_answer', params: { response: 'not_me' }, actions: [] } });
    const data = await storeAction('sp.pay_not_me', { value: 'sp.pay_not_me:31:tokP', arg: '31:tokP' });
    const outbound = await sendDev(server, { from: MARIA, callback_data: data });
    expect(api.telegramCallback).toHaveBeenLastCalledWith(expect.objectContaining({ action_type: 'sp.pay_not_me', subject_id: 31, step_token: 'tokP' }), expect.any(String));
    expect(calls(outbound, 'editMessageText')[0]?.text).toBe('Thanks. The request is flagged and your finance team has been alerted.');
  });
});

describe('scampause templates', () => {
  const PAYLOAD = '<a href=x>& <b';

  it('escapes every parameter of every template', async () => {
    const actions = new MemoryActionStore();
    for (const template of Object.keys(SP_TEMPLATES)) {
      const params: Record<string, unknown> = {};
      for (const key of ['workspace', 'reference', 'title', 'vendor', 'payee_masked', 'amount', 'due_date', 'risk_label', 'score', 'expires_local', 'expires_relative', 'verification_status', 'reason', 'time_local', 'remaining', 'reason_label', 'n', 'total', 'hours', 'approvers', 'reasons', 'callback_contact', 'source_ref', 'employee_first_name', 'company', 'bank', 'actor', 'prevented_amount', 'required', 'available', 'seq', 'status_label', 'verification']) {
        params[key] = PAYLOAD;
      }
      params.top_signals = [PAYLOAD, { title: PAYLOAD }];
      for (const locale of ['ru', 'en'] as const) {
        const bot = createTestBot();
        const rendered = await renderSp({ registry: bot.services.registry, translator: bot.services.translator, actions, ttlS: 60, locale, template, params, owner: { telegramUserId: 1, chatId: 1, workspaceId: 1, userId: 1 } });
        expect(rendered.html, template).not.toContain('<a href=x>');
        expect(rendered.html, template).not.toMatch(/<b(?!>)/);
        if (rendered.html.includes('&lt;a')) expect(rendered.html).toContain('&lt;a href=x&gt;&amp; &lt;b');
      }
    }
  });

  it('hides Approve on plans without Telegram approvals and degrades localhost links to text', async () => {
    const test = createTestBot();
    const rendered = await renderSp({
      registry: test.services.registry,
      translator: test.services.translator,
      actions: new MemoryActionStore(),
      ttlS: 60,
      locale: 'en',
      template: 'sp.approval_requested',
      params: { reference: 'SP-00046', title: 'Invoice', vendor: 'Blue Ridge', payee_masked: '•••• 4417', amount: '$7,215.00', risk_label: 'Low', score: 0, verification: { state: 'confirmed', verifier: 'Maria', when: 'Oct 20' }, url: 'http://127.0.0.1:8300/payment-requests/46' },
      spActions: [{ type: 'sp.rej', subject_id: 4, step_token: 't' }],
      owner: { telegramUserId: 1, chatId: 1, workspaceId: 1, userId: 1 },
    });
    expect(rendered.keyboard?.inline_keyboard.flat().map((button) => button.text)).toEqual(['Reject']);
    expect(rendered.html).toContain('Open: http://127.0.0.1:8300/payment-requests/46');
    expect(rendered.html).toContain('confirmed by Maria by phone, Oct 20');
  });
});
