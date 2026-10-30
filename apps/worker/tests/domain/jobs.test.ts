import { GrammyError } from 'grammy';
import { describe, expect, it } from 'vitest';
import { UpstreamHttpError } from '../../src/clients/errors.js';
import { intakeFlowDefinition, intakeFlowJobIds } from '../../src/domain/jobs/flows.js';
import { createDomainHandlers } from '../../src/domain/jobs/index.js';
import type { ExtractionResult } from '../../src/domain/extract/types.js';
import { GENESIS_HASH } from '../../src/domain/evidence/canonical.js';
import { domainDeps, envelope, fakeParser, jobCtx, OUTBOX_ID } from './helpers.js';
import { BEC_EMAIL, eml, US_INVOICE, vectorEntries } from './fixtures.js';

const INTAKE = { payment_request_id: 42, workspace_id: 1, revision: 2, file_ids: [], locale: 'en', country: 'US', skip_extract: false };

function conflict(code: string): UpstreamHttpError {
  return new UpstreamHttpError('web', 'POST', '/x', 409, { error: code });
}

describe('intake.extract', () => {
  it('parses files, submits the extraction and starts the scoring flow', async () => {
    const t = domainDeps();
    t.api.intake.mockResolvedValueOnce({
      payment_request_id: 42, revision: 2, locale: 'en', country: 'US', currency: 'USD', vendor_currency: null,
      ai_extract_assist: false, ai_external_allowed: false, domains: [],
      files: [{ id: 7, role: 'original', mime: 'application/pdf', original_name: 'invoice.pdf', size_bytes: 10, sha256: 'x', parent_file_id: null }],
    });
    t.api.downloadFile.mockResolvedValueOnce({ bytes: Buffer.from(US_INVOICE), sha256: null, contentType: 'application/pdf' });
    const handlers = createDomainHandlers(t.deps);
    const result = await handlers.intake['intake.received']!(envelope('intake.received', INTAKE), jobCtx());
    expect(result).toEqual({ status: 'succeeded', proceed: true });
    expect(t.api.pipeline.mock.calls[0]?.[1]).toEqual({ workspace_id: 1, revision: 2, stage: 'extract', state: 'running' });
    const submitted = t.api.submitExtraction.mock.calls[0]?.[1] as ExtractionResult;
    expect(submitted).toMatchObject({ workspace_id: 1, payment_request_id: 42, revision: 2, status: 'succeeded', extractor_version: 'worker-extract@1.0.0', llm_assisted: false, files: [{ file_id: 7, pages: 1, ocr_used: false }] });
    expect(submitted.fields.amount_minor?.value).toBe(721500);
    expect(t.flows).toEqual([{ paymentRequestId: 42, workspaceId: 1, revision: 2, domains: ['blueridge-pack.com'] }]);
  });

  it('parses .eml originals, uploads attachments and keeps sender facts', async () => {
    const t = domainDeps();
    const message = eml({ replyTo: 'karen.whitfield.ar@gmail.com', authResults: 'spf=fail; dkim=none; dmarc=fail', attachments: [{ filename: 'inv.pdf', contentType: 'application/pdf', content: Buffer.from(US_INVOICE) }] });
    t.api.intake.mockResolvedValueOnce({
      payment_request_id: 42, revision: 2, locale: 'en', country: 'US', currency: 'USD', vendor_currency: null,
      ai_extract_assist: false, ai_external_allowed: false, domains: [],
      files: [
        { id: 7, role: 'original', mime: 'message/rfc822', original_name: 'mail.eml', size_bytes: 10, sha256: 'x', parent_file_id: null },
        { id: 8, role: 'attachment', mime: 'application/pdf', original_name: 'inv.pdf', size_bytes: 10, sha256: 'y', parent_file_id: 7 },
      ],
    });
    t.api.downloadFile.mockResolvedValueOnce({ bytes: message, sha256: null, contentType: 'message/rfc822' });
    const handlers = createDomainHandlers(t.deps);
    await handlers.intake['intake.received']!(envelope('intake.received', INTAKE), jobCtx());
    expect(t.api.downloadFile).toHaveBeenCalledTimes(1);
    expect(t.api.uploadAttachment).toHaveBeenCalledWith(42, { workspace_id: 1, revision: 2, parent_file_id: 7 }, expect.objectContaining({ filename: 'inv.pdf', contentType: 'application/pdf' }), 'req-domain');
    const submitted = t.api.submitExtraction.mock.calls[0]?.[1] as ExtractionResult;
    expect(submitted.fields.sender).toMatchObject({ from_email: 'billing@blueridge-pack.com', reply_to: 'karen.whitfield.ar@gmail.com', auth: { spf: 'fail', dkim: 'none', dmarc: 'fail' } });
    expect(submitted.fields.amount_minor?.value).toBe(721500);
    expect(submitted.files.map((file) => file.file_id)).toEqual([7, 900]);
    expect(submitted.fields.domains).toEqual(expect.arrayContaining(['blueridge-pack.com', 'gmail.com']));
  });

  it('adds OCR_LANGUAGE_MISSING when Russian OCR data is absent', async () => {
    const parser = fakeParser({ ocr_used: true, missing_languages: ['rus'], pages: [{ page: 1, text: BEC_EMAIL, ocr_used: true }] });
    const t = domainDeps({ parser });
    t.api.intake.mockResolvedValueOnce({
      payment_request_id: 42, revision: 2, locale: 'ru', country: 'RU', currency: 'RUB', vendor_currency: null, ai_extract_assist: false, ai_external_allowed: false, domains: [],
      files: [{ id: 7, role: 'original', mime: 'image/png', original_name: 'scan.png', size_bytes: 10, sha256: 'x', parent_file_id: null }],
    });
    await createDomainHandlers(t.deps).intake['intake.received']!(envelope('intake.received', INTAKE), jobCtx());
    const submitted = t.api.submitExtraction.mock.calls[0]?.[1] as ExtractionResult;
    expect(submitted.warnings).toContain('OCR_LANGUAGE_MISSING');
    expect(submitted.ocr_used).toBe(true);
  });

  it('uploads rendered page previews', async () => {
    const parser = fakeParser({ previews: [{ page: 1, png: Buffer.from('png1') }, { page: 2, png: Buffer.from('png2') }] });
    const t = domainDeps({ parser });
    t.api.intake.mockResolvedValueOnce({
      payment_request_id: 42, revision: 2, locale: 'en', country: 'US', currency: 'USD', vendor_currency: null, ai_extract_assist: false, ai_external_allowed: false, domains: [],
      files: [{ id: 7, role: 'original', mime: 'application/pdf', original_name: 'inv.pdf', size_bytes: 10, sha256: 'x', parent_file_id: null }],
    });
    t.api.uploadPreview.mockRejectedValueOnce(new Error('storage down'));
    await createDomainHandlers(t.deps).intake['intake.received']!(envelope('intake.received', INTAKE), jobCtx());
    expect(t.api.uploadPreview.mock.calls.map((call) => call[1])).toEqual([
      { workspace_id: 1, revision: 2, file_id: 7, page: 1 },
      { workspace_id: 1, revision: 2, file_id: 7, page: 2 },
    ]);
    expect(t.api.submitExtraction).toHaveBeenCalledTimes(1);
  });

  it('reports a file that cannot be parsed as a warning and a failed extraction', async () => {
    const parser = fakeParser();
    parser.parse.mockRejectedValueOnce(Object.assign(new Error('too big'), { code: 'file_too_complex', retryable: false }));
    const t = domainDeps({ parser });
    t.api.intake.mockResolvedValueOnce({
      payment_request_id: 42, revision: 2, locale: 'en', country: 'US', currency: 'USD', vendor_currency: null, ai_extract_assist: false, ai_external_allowed: false, domains: [],
      files: [{ id: 7, role: 'original', mime: 'image/png', original_name: 'bomb.png', size_bytes: 10, sha256: 'x', parent_file_id: null }],
    });
    await createDomainHandlers(t.deps).intake['intake.received']!(envelope('intake.received', INTAKE), jobCtx());
    const submitted = t.api.submitExtraction.mock.calls[0]?.[1] as ExtractionResult;
    expect(submitted).toMatchObject({ status: 'failed', error_code: 'file_too_complex' });
    expect(submitted.warnings).toContain('FILE_TOO_COMPLEX');
  });

  it('is a no-op for a stale revision and does not start the flow when web says stop', async () => {
    const t = domainDeps();
    t.api.intake.mockResolvedValueOnce({ payment_request_id: 42, revision: 3, locale: 'en', country: 'US', currency: 'USD', vendor_currency: null, ai_extract_assist: false, ai_external_allowed: false, domains: [], files: [] });
    const handlers = createDomainHandlers(t.deps);
    expect(await handlers.intake['intake.received']!(envelope('intake.received', INTAKE), jobCtx())).toEqual({ noop: 'stale_revision' });
    t.api.pipeline.mockRejectedValueOnce(conflict('stale_revision'));
    expect(await handlers.intake['intake.received']!(envelope('intake.received', INTAKE), jobCtx())).toEqual({ noop: 'stale_revision' });
    t.api.submitExtraction.mockResolvedValueOnce({ proceed: false, domains: [] });
    await handlers.intake['intake.received']!(envelope('intake.received', INTAKE), jobCtx());
    expect(t.flows).toEqual([]);
  });

  it('marks the stage failed after the last attempt', async () => {
    const t = domainDeps();
    t.api.intake.mockRejectedValue(new UpstreamHttpError('web', 'GET', '/x', 503, null));
    const handlers = createDomainHandlers(t.deps);
    await expect(handlers.intake['intake.received']!(envelope('intake.received', INTAKE), jobCtx())).rejects.toThrow();
    expect(t.api.pipeline).not.toHaveBeenCalledWith(42, expect.objectContaining({ state: 'failed' }), expect.anything());
    await expect(handlers.intake['intake.received']!(envelope('intake.received', INTAKE), jobCtx({ attempt: 5, isFinalAttempt: true }))).rejects.toThrow();
    expect(t.api.pipeline).toHaveBeenLastCalledWith(42, expect.objectContaining({ stage: 'extract', state: 'failed' }), 'req-domain');
  });

  it('skips extraction for manual entries but still runs the flow', async () => {
    const t = domainDeps();
    t.api.intake.mockResolvedValueOnce({ payment_request_id: 42, revision: 2, locale: 'en', country: 'US', currency: 'USD', vendor_currency: null, ai_extract_assist: false, ai_external_allowed: false, domains: ['outlook.com'], files: [] });
    await createDomainHandlers(t.deps).intake['intake.received']!(envelope('intake.received', { ...INTAKE, skip_extract: true }), jobCtx());
    expect(t.api.pipeline).toHaveBeenCalledWith(42, { workspace_id: 1, revision: 2, stage: 'extract', state: 'skipped' }, 'req-domain');
    expect(t.api.submitExtraction).not.toHaveBeenCalled();
    expect(t.flows[0]?.domains).toEqual(['outlook.com']);
  });

  it('defers when the workspace already runs two extractions and ignores a concurrent duplicate', async () => {
    const t = domainDeps();
    await t.locks.acquireSlot('sp:intake:sem:1', 2, 60_000);
    await t.locks.acquireSlot('sp:intake:sem:1', 2, 60_000);
    const deferred: number[] = [];
    const defer = async (ms: number): Promise<never> => {
      deferred.push(ms);
      throw new Error('deferred');
    };
    const handlers = createDomainHandlers(t.deps);
    await expect(handlers.intake['intake.received']!(envelope('intake.received', INTAKE), jobCtx({ defer }))).rejects.toThrow('deferred');
    expect(deferred).toHaveLength(1);

    const other = domainDeps();
    await other.locks.acquire('extract:42:2', 60_000);
    expect(await createDomainHandlers(other.deps).intake['intake.received']!(envelope('intake.received', INTAKE), jobCtx())).toEqual({ skipped: 'in_progress' });
  });

  it('rejects malformed payloads permanently', async () => {
    const t = domainDeps();
    await expect(createDomainHandlers(t.deps).intake['intake.received']!(envelope('intake.received', { workspace_id: 1 }), jobCtx())).rejects.toThrow(/Invalid payload/);
  });
});

describe('intake flow', () => {
  it('builds the score flow with idempotent ids and failure semantics', () => {
    const flow = intakeFlowDefinition({ paymentRequestId: 42, workspaceId: 1, revision: 2, domains: ['A.com', 'a.com'] });
    expect(intakeFlowJobIds({ paymentRequestId: 42, revision: 2 })).toEqual({ score: 'score:42:2', domain: 'domain:42:2', bank: 'bank:42:2' });
    expect(flow).toMatchObject({ name: 'intake.score', queueName: 'intake', opts: { jobId: 'score:42:2', attempts: 5 } });
    expect(flow.children?.map((child) => [child.name, child.queueName, child.opts?.jobId])).toEqual([
      ['intake.domain-check', 'domain-intel', 'domain:42:2'],
      ['intake.bank-compare', 'intake', 'bank:42:2'],
    ]);
    expect(flow.children?.[0]?.opts?.ignoreDependencyOnFailure).toBe(true);
    expect(flow.children?.[1]?.opts?.failParentOnFailure).toBe(true);
    expect(flow.data).toMatchObject({ domains: ['a.com'] });
  });

  it('domain-check looks up stale and missing domains with the fake driver outside production', async () => {
    const t = domainDeps();
    t.api.domainIntel.mockResolvedValueOnce({
      mode: 'fake',
      facts: [{ domain: 'gmail.com', unicode_form: null, has_mx: true, has_spf: true, dmarc_policy: 'none', registered_at: '1995-08-13', registrar: null, rdap_status: 'ok', source: 'fake', checked_at: '2026-10-22T00:00:00Z' }],
      stale: [],
    });
    const data = { payment_request_id: 42, workspace_id: 1, revision: 2, domains: ['blueridge-pack.com', 'gmail.com'] };
    const result = await createDomainHandlers(t.deps)['domain-intel']['intake.domain-check']!(data, jobCtx());
    expect(result).toEqual({ mode: 'fake', looked_up: 1 });
    expect(t.api.storeDomainIntel).toHaveBeenCalledWith(1, [expect.objectContaining({ domain: 'blueridge-pack.com', source: 'fake', registered_at: '2026-10-08' })], 'req-domain');
    expect(t.api.pipeline).toHaveBeenLastCalledWith(42, { workspace_id: 1, revision: 2, stage: 'domain', state: 'done' }, 'req-domain');
  });

  it('domain-check uses the live driver when configured and stores nothing in cache-only mode', async () => {
    const live = domainDeps({ env: { DOMAIN_INTEL_DRIVER: 'live' } });
    live.api.domainIntel.mockResolvedValueOnce({ mode: 'live', facts: [], stale: [] });
    await createDomainHandlers(live.deps)['domain-intel']['intake.domain-check']!({ payment_request_id: 42, workspace_id: 1, revision: 2, domains: ['harbor-lg.com'] }, jobCtx());
    expect(live.liveIntel.lookup).toHaveBeenCalledWith('harbor-lg.com');

    const cache = domainDeps();
    cache.api.domainIntel.mockResolvedValueOnce({ mode: 'cache_only', facts: [], stale: [] });
    expect(await createDomainHandlers(cache.deps)['domain-intel']['intake.domain-check']!({ payment_request_id: 42, workspace_id: 1, revision: 2, domains: ['x.com'] }, jobCtx())).toEqual({ mode: 'cache_only', looked_up: 0 });
    expect(cache.api.storeDomainIntel).not.toHaveBeenCalled();
  });

  it('bank-compare and score report final failures to web', async () => {
    const t = domainDeps();
    const handlers = createDomainHandlers(t.deps);
    const data = { payment_request_id: 42, workspace_id: 1, revision: 2 };
    expect(await handlers.intake['intake.bank-compare']!(data, jobCtx())).toEqual({ outcome: 'changed' });
    t.api.bankComparison.mockRejectedValueOnce(new UpstreamHttpError('web', 'POST', '/x', 500, null));
    await expect(handlers.intake['intake.bank-compare']!(data, jobCtx({ isFinalAttempt: true }))).rejects.toThrow();
    expect(t.api.pipeline).toHaveBeenLastCalledWith(42, expect.objectContaining({ stage: 'bank', state: 'failed' }), 'req-domain');
    expect(await handlers.intake['intake.score']!(data, jobCtx())).toEqual({ score: 100, level: 'critical', status: 'review' });
    t.api.assess.mockRejectedValueOnce(conflict('invalid_transition'));
    expect(await handlers.intake['intake.score']!(data, jobCtx())).toEqual({ noop: 'invalid_transition' });
  });
});

const APPROVAL = {
  approval_id: 9,
  payment_request_id: 42,
  workspace_id: 1,
  sp_notification_ids: { telegram: 71, email: 72 },
  recipient: { user_id: 3, locale: 'ru', email: 'alex@northwindroasters.com', telegram_user_id: 5003 },
  telegram: {
    template: 'sp.approval_requested',
    params: { workspace: 'Northwind', reference: 'SP-00042', title: 'Invoice <b>88390</b>', vendor: 'Blue Ridge', payee_masked: 'JPMorgan · US · •••• 4417', amount: '$7,215.00', due_date: '19.11.2026', risk_label: 'Низкий', score: 0, top_signals: [], verification: { state: 'not_required' }, expires_local: '24.10 12:00', expires_relative: 'через 24 ч', url: 'https://app.scampause.com/payment-requests/42' },
    actions: [{ type: 'sp.apv', subject_id: 9, step_token: 'tok1' }, { type: 'sp.rej', subject_id: 9, step_token: 'tok1' }],
  },
  email: { subject: 'Нужно ваше согласование', html: '<p>x</p>', text: 'x' },
};

describe('approvals', () => {
  it('fans out per channel with derived job ids', async () => {
    const t = domainDeps();
    const handlers = createDomainHandlers(t.deps);
    expect(await handlers.approvals['approval.requested']!(envelope('approval.requested', APPROVAL), jobCtx())).toEqual({ queued: ['telegram', 'email'] });
    expect(t.enqueuer.jobs.map((job) => [job.name, job.jobId])).toEqual([
      ['approvals.dispatch.telegram', `${OUTBOX_ID}-tg`],
      ['approvals.dispatch.email', `${OUTBOX_ID}-mail`],
    ]);
    await handlers.approvals['approval.requested']!(envelope('approval.requested', { ...APPROVAL, recipient: { ...APPROVAL.recipient, telegram_user_id: null, email: null } }), jobCtx());
    expect(t.web.reportDelivery).toHaveBeenCalledWith({ reference: { type: 'sp_notification', id: 71 }, channel: 'telegram', status: 'failed', error: 'telegram_not_linked' }, 'req-domain');
  });

  it('sends the Telegram approval once, persists the message id and escapes params', async () => {
    const t = domainDeps();
    const handlers = createDomainHandlers(t.deps);
    const child = { outbox_id: OUTBOX_ID, payload: APPROVAL };
    expect(await handlers.approvals['approvals.dispatch.telegram']!(child, jobCtx())).toEqual({ message_id: 555 });
    const [chatId, html, keyboard] = t.telegram.sendMessage.mock.calls[0]!;
    expect(chatId).toBe(5003);
    expect(html).toContain('Нужно ваше согласование · SP-00042');
    expect(html).toContain('Invoice &lt;b&gt;88390&lt;/b&gt;');
    expect(html).toContain('не гарантирует безопасность платежа');
    const rows = (keyboard as { inline_keyboard: Array<Array<{ text: string; url?: string; callback_data?: string }>> }).inline_keyboard;
    expect(rows.flat().map((button) => button.text)).toEqual(['Согласовать', 'Отклонить', 'Открыть']);
    const stored = await t.actions.get(rows[0]![0]!.callback_data!.slice(2));
    expect(stored).toMatchObject({ type: 'sp.apv', payload: { subject_id: 9, step_token: 'tok1' }, telegram_user_id: 5003, workspace_id: 1 });
    expect(t.api.setApprovalTelegramMessage).toHaveBeenCalledWith(9, { workspace_id: 1, chat_id: 5003, message_id: 555, sp_notification_id: 71 }, 'req-domain');
    expect(t.web.reportDelivery).toHaveBeenCalledWith({ reference: { type: 'sp_notification', id: 71 }, channel: 'telegram', status: 'sent', provider_message_id: '555' }, 'req-domain');

    t.api.setApprovalTelegramMessage.mockRejectedValueOnce(new UpstreamHttpError('web', 'POST', '/x', 503, null));
    const second = domainDeps();
    second.api.setApprovalTelegramMessage.mockRejectedValueOnce(new UpstreamHttpError('web', 'POST', '/x', 503, null));
    const h2 = createDomainHandlers(second.deps);
    await expect(h2.approvals['approvals.dispatch.telegram']!(child, jobCtx())).rejects.toThrow();
    await h2.approvals['approvals.dispatch.telegram']!(child, jobCtx({ attempt: 2 }));
    expect(second.telegram.sendMessage).toHaveBeenCalledTimes(1);

    second.api.getApprovalTelegramMessage.mockResolvedValueOnce({ chat_id: 5003, message_id: 555 });
    expect(await h2.approvals['approvals.dispatch.telegram']!(child, jobCtx({ attempt: 3 }))).toEqual({ skipped: 'already_sent', message_id: 555 });
  });

  it('does not retry when the user blocked the bot', async () => {
    const t = domainDeps();
    t.telegram.sendMessage.mockRejectedValueOnce(new GrammyError('Forbidden: bot was blocked by the user', { ok: false, error_code: 403, description: 'Forbidden: bot was blocked by the user' }, 'sendMessage', {}));
    const result = await createDomainHandlers(t.deps).approvals['approvals.dispatch.telegram']!({ outbox_id: OUTBOX_ID, payload: APPROVAL }, jobCtx());
    expect(result).toEqual({ failed: 'blocked' });
    expect(t.web.reportDelivery).toHaveBeenCalledWith(expect.objectContaining({ status: 'failed', channel: 'telegram' }), 'req-domain');
  });

  it('sends the e-mail once', async () => {
    const t = domainDeps();
    const handlers = createDomainHandlers(t.deps);
    const child = { outbox_id: OUTBOX_ID, payload: APPROVAL };
    await handlers.approvals['approvals.dispatch.email']!(child, jobCtx());
    await handlers.approvals['approvals.dispatch.email']!(child, jobCtx());
    expect(t.mailer.send).toHaveBeenCalledTimes(1);
    expect(t.mailer.send.mock.calls[0]?.[0]).toMatchObject({ to: 'alex@northwindroasters.com', subject: 'Нужно ваше согласование' });
    expect(t.web.reportDelivery).toHaveBeenCalledWith({ reference: { type: 'sp_notification', id: 72 }, channel: 'email', status: 'sent', provider_message_id: '<smtp-9@mailpit>' }, 'req-domain');
  });

  it('renders reminders as replies and skips decided approvals', async () => {
    const t = domainDeps();
    const handlers = createDomainHandlers(t.deps);
    const due = envelope('approval.reminder.due', { approval_id: 9, payment_request_id: 42, workspace_id: 1, sequence: 2 });
    expect(await handlers.approvals['approval.reminder.due']!(due, jobCtx())).toEqual({ sent: false, reason: 'decided' });
    t.api.reminders.mockResolvedValueOnce({
      send: true,
      telegram: { chat_id: 5003, template: 'sp.approval_reminder', params: { locale: 'en', n: 2, total: 3, reference: 'SP-00042', expires_relative: '4 h' }, actions: [], reply_to_message_id: 555 },
      email: { to: 'alex@northwindroasters.com', subject: 'Reminder', html: null, text: 'Reminder' },
      sp_notification_ids: { telegram: 81, email: 82 },
    });
    expect(await handlers.approvals['approval.reminder.due']!(due, jobCtx())).toEqual({ sent: true, channels: ['telegram', 'email'] });
    expect(t.telegram.sendMessage.mock.calls[0]?.[1]).toContain('Reminder (2 of 3): SP-00042');
    expect(t.telegram.sendMessage.mock.calls[0]?.[3]).toEqual({ replyToMessageId: 555 });
  });

  it('escalates, expires and sweeps through web', async () => {
    const t = domainDeps();
    const handlers = createDomainHandlers(t.deps);
    expect(await handlers.approvals['approval.escalation.due']!(envelope('approval.escalation.due', { approval_route_id: 5, payment_request_id: 42, workspace_id: 1 }), jobCtx())).toEqual({ escalated: true });
    expect(await handlers.approvals['approval.expiry.due']!(envelope('approval.expiry.due', { approval_route_id: 5, payment_request_id: 42, workspace_id: 1, expires_at: '2026-10-24T09:00:00Z' }), jobCtx())).toEqual({ expired: true });
    t.api.expire.mockRejectedValueOnce(conflict('already_processed'));
    expect(await handlers.approvals['approval.expiry.due']!(envelope('approval.expiry.due', { approval_route_id: 5, payment_request_id: 42, workspace_id: 1, expires_at: 'x' }), jobCtx())).toEqual({ noop: 'already_processed' });
    expect(await handlers.approvals['approvals.sweep']!({}, jobCtx())).toEqual({ expired_route_ids: [] });
  });
});

describe('audit', () => {
  it('verifies the chain segment, renders the packet and uploads it', async () => {
    const t = domainDeps();
    const entries = vectorEntries().map((entry) => ({ ...entry, event_label: 'event', actor: 'Maria', channel: 'web', occurred_at_local: '22.10.2026' }));
    t.api.exportData.mockResolvedValueOnce({
      workspace: { name: 'Northwind', timezone: 'America/New_York' },
      locale: 'en',
      generated_at: '2026-10-22T10:30:00Z',
      request: { reference: 'SP-00042', title: 'x', kind_label: 'Invoice', status_label: 'Approved', amount_formatted: '$7,215.00', vendor_name: 'Blue Ridge', invoice_number: '88390', due_date: '2026-11-19', payee_masked: '•••• 4417', risk: { score: 0, level_label: 'Low', signals: [] } },
      entries,
      disclaimer: 'ScamPause highlights risk signals; it does not guarantee that a payment is safe.',
    });
    const result = (await createDomainHandlers(t.deps).audit['evidence.packet.requested']!(envelope('evidence.packet.requested', { export_id: 3, payment_request_id: 42, workspace_id: 1, locale: 'en' }), jobCtx())) as { chain_verified: boolean; sha256: string };
    expect(result.chain_verified).toBe(true);
    const [exportId, ws, file, digest, verified] = t.api.uploadExport.mock.calls[0]!;
    expect([exportId, ws, file.filename, file.contentType, verified]).toEqual([3, 1, 'evidence-SP-00042.pdf', 'application/pdf', true]);
    expect(digest).toBe(result.sha256);
    expect(GENESIS_HASH).toHaveLength(64);
  });

  it('marks the export failed when data is invalid', async () => {
    const t = domainDeps();
    t.api.exportData.mockResolvedValueOnce({ nope: true });
    await expect(createDomainHandlers(t.deps).audit['evidence.packet.requested']!(envelope('evidence.packet.requested', { export_id: 3, payment_request_id: 42, workspace_id: 1, locale: 'en' }), jobCtx())).rejects.toThrow();
    expect(t.api.exportFailed).toHaveBeenCalledWith(3, 1, expect.stringContaining('invalid evidence packet data'), 'req-domain');
  });

  it('pages through the journal and writes CSV with injection protection', async () => {
    const t = domainDeps();
    const [first, second] = vectorEntries();
    t.api.exportData
      .mockResolvedValueOnce({ entries: [{ ...first, event: '=HYPERLINK("http://x","Click")' }], next_page: 2, labels: { 'column.seq': '№' } })
      .mockResolvedValueOnce({ entries: [second], next_page: null, labels: {} });
    const payload = { export_id: 4, workspace_id: 1, from: '2026-10-01', to: '2026-10-22', format: 'csv', kind: 'audit_journal', locale: 'ru' };
    const result = await createDomainHandlers(t.deps).audit['audit.journal.requested']!(envelope('audit.journal.requested', payload), jobCtx());
    expect(result).toMatchObject({ entries: 2, chain_verified: true });
    const file = t.api.uploadExport.mock.calls[0]![2];
    const text = file.bytes.subarray(3).toString('utf8');
    expect(file.filename).toBe('audit-journal-2026-10-01-2026-10-22.csv');
    expect(text.startsWith('"№"')).toBe(true);
    expect(text).toContain('"\'=HYPERLINK(""http://x"",""Click"")"');
    expect(t.api.exportData).toHaveBeenCalledTimes(2);
  });

  it('runs the nightly chain verification', async () => {
    const t = domainDeps();
    expect(await createDomainHandlers(t.deps).audit['audit.chain-verify']!({}, jobCtx())).toEqual({ workspaces: 2, broken: 0 });
  });
});
