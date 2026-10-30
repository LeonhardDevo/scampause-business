import { createHash } from 'node:crypto';
import { expect, test } from '../../fixtures.ts';
import { appRequest } from '../../support/http.ts';
import { blueRidgeChangedInvoice, variantFor, type BlueRidgeChange } from '../../helpers/domain/documents.ts';
import {
  buttonsOf,
  digits,
  openCase,
  PEOPLE,
  query,
  reference,
  signalCodes,
  signIn,
  telegramOutbox,
  textOf,
  uploadFiles,
  waitForStatus,
  type Person,
} from '../../helpers/domain/session.ts';

const APPROVE_LABEL = /^(?:Approve|Согласовать)$/;
const REJECT_LABEL = /^(?:Reject|Отклонить)$/;

interface FlowState {
  fixture: BlueRidgeChange;
  requestId: number;
  reference: string;
  approvers: Person[];
}

const state: Partial<FlowState> = {};

function need<K extends keyof FlowState>(key: K): FlowState[K] {
  const value = state[key];
  if (value === undefined) throw new Error(`the previous journey did not produce ${key}`);
  return value as FlowState[K];
}

test.describe.serial('payment request: upload, bank change, second-person confirmation, audit', () => {
  test('J1 a bookkeeper uploads a suspicious invoice and the pipeline scores it', { tag: ['@flow', '@visual'] }, async ({ page, app }, testInfo) => {
    const fixture = blueRidgeChangedInvoice(variantFor(testInfo.project.name));
    state.fixture = fixture;

    await signIn(app, PEOPLE.irina);
    const id = await uploadFiles(page, [fixture.file]);
    state.requestId = id;

    const caseFile = page.getByTestId('case-file');
    await expect(caseFile).toBeVisible();
    const stepper = page.getByTestId('pipeline-stepper');
    if (await stepper.isVisible().catch(() => false)) await app.shot('j1-pipeline');

    await waitForStatus(page, id, ['review'], 60_000);
    await openCase(page, id);
    await expect(caseFile).toHaveAttribute('data-status', 'review');
    state.reference = await reference(page);
    expect(state.reference).toMatch(/^SP-\d{5}$/);

    expect(digits(await page.getByTestId('case-amount').innerText())).toBe(String(fixture.amountMinor));
    await expect(page.getByTestId('case-header')).toContainText('Blue Ridge Packaging Co.');
    await expect(page.getByText(/does not mean the payment is safe|не гарантирует безопасность/).first()).toBeVisible();

    await app.axe('j1-case-file');
    await app.expectNoHorizontalScroll();
    await app.shot('j1-case-file');
  });

  test('J2 the bank-details change is detected, revealed with an audit trail and submitted', { tag: '@flow' }, async ({ page, app }) => {
    const id = need('requestId');
    const fixture = need('fixture');
    await signIn(app, PEOPLE.irina);
    await openCase(page, id);

    const gauge = page.getByTestId('risk-gauge').first();
    await expect(gauge).toBeVisible();
    await expect(page.getByTestId('case-header')).toContainText(/Critical risk|Критический риск/);

    const codes = await signalCodes(page);
    expect(codes).toEqual(expect.arrayContaining(['BANK_DETAILS_CHANGED', 'BANK_COUNTRY_CHANGED']));
    await page.getByTestId('case-tab-bank').click();

    await expect(page.getByTestId('bank-diff-outcome')).toHaveAttribute('data-outcome', 'changed');
    const diff = page.getByTestId('bank-diff');
    await expect(diff).toContainText('4417');
    await expect(diff).toContainText(fixture.account.last4);
    await expect(diff).not.toContainText(fixture.account.iban);

    const revealed = page.waitForResponse((response) => /\/reveal$/.test(new URL(response.url()).pathname) && response.request().method() === 'POST');
    await page.getByTestId('reveal-bank').click();
    const revealResponse = await revealed;
    expect(revealResponse.status()).toBe(200);
    expect(revealResponse.headers()['cache-control'] ?? '').toMatch(/no-store/);
    const dialog = page.getByTestId('reveal-dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.getByTestId('char-diff')).toContainText(fixture.account.iban.slice(-8));
    await app.axe('j2-reveal');
    await app.shot('j2-reveal');
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();

    await page.getByTestId('case-submit').click();
    const confirm = page.getByTestId('submit-confirm');
    if (await confirm.isVisible({ timeout: 2_000 }).catch(() => false)) {
      await page.getByTestId('submit-dialog').getByRole('checkbox').check();
      await confirm.click();
    }
    await waitForStatus(page, id, ['awaiting_verification'], 20_000);
    await openCase(page, id);
    await expect(page.getByTestId('status-chip').first()).toHaveAttribute('data-status', 'awaiting_verification');
    await expect(page.getByTestId('case-decide-approve')).toHaveCount(0);
    await app.shot('j2-awaiting-verification');
  });

  test('J4 a second person confirms by callback and two approvers sign off', { tag: '@flow' }, async ({ page, app, request, openSession }, testInfo) => {
    const id = need('requestId');
    const fixture = need('fixture');
    const replaces = variantFor(testInfo.project.name) === 'b';

    await signIn(app, PEOPLE.maria);
    await openCase(page, id, 'verification');
    await page.getByTestId('case-verify').click();
    const sheet = page.getByTestId('verification-sheet');
    await expect(sheet).toBeVisible();
    await sheet.locator('label').filter({ hasText: 'Karen Whitfield' }).first().click();
    await sheet.getByTestId('verification-spoken').fill('Karen Whitfield');
    await sheet.getByRole('radio', { name: /^(?:Confirmed|Подтверждено|Подтвердил[аи]?)$/ }).click();
    for (const key of ['account_last4', 'amount', 'change_initiated_by_vendor']) {
      const box = sheet.getByTestId(`check-${key}`);
      if ((await box.count()) > 0) await box.check();
    }
    if (replaces) {
      await sheet.locator('#verification-replaces').click();
      await page.getByRole('option', { name: /4417/ }).click();
    }
    await app.axe('j4-verification-sheet');
    await app.shot('j4-verification-sheet');
    await sheet.getByTestId('verification-submit').click();
    await expect(sheet).toBeHidden({ timeout: 20_000 });

    await waitForStatus(page, id, ['awaiting_approval'], 30_000);
    await openCase(page, id, 'approvals');
    const card = page.getByTestId('route-card').first();
    await expect(card).toBeVisible();
    const slots = card.getByTestId('route-slot');
    await expect(slots).toHaveCount(2);
    const names = await slots.locator('p.truncate').allInnerTexts();
    const byName = (name: string): Person => {
      const person = Object.values(PEOPLE).find((candidate) => candidate.name === name.trim());
      if (!person) throw new Error(`unknown approver "${name}"`);
      return person;
    };
    const approvers = names.map(byName);
    state.approvers = approvers;
    expect(approvers.map((person) => person.key)).toContain('alex');
    const second = approvers.find((person) => person.key !== 'alex')!;
    expect(['maria', 'sam']).toContain(second.key);
    await app.shot('j4-route-card');

    if (second.telegramId !== null) {
      const ref = need('reference');
      await expect(async () => {
        const calls = await telegramOutbox(request, second.telegramId!);
        const message = [...calls].reverse().find((call) => textOf(call).includes(ref));
        expect(message, `no Telegram approval request for ${ref}`).toBeTruthy();
        const labels = buttonsOf(message!).map((button) => button.text);
        expect(labels.some((label) => APPROVE_LABEL.test(label)), `critical request offered Approve in Telegram: ${labels.join(', ')}`).toBe(false);
        expect(labels.some((label) => REJECT_LABEL.test(label)), `Reject missing: ${labels.join(', ')}`).toBe(true);
      }).toPass({ timeout: 30_000, intervals: [1_000, 2_000] });
    }

    await signIn(app, PEOPLE.irina);
    await openCase(page, id, 'approvals');
    await expect(page.getByTestId('case-decide-approve')).toHaveCount(0);
    await expect(page.getByTestId('route-approve')).toHaveCount(0);

    await signIn(app, second);
    await page.goto('/approvals');
    await expect(page.getByTestId('approvals-page')).toBeVisible();
    await expect(page.getByTestId('approval-card').filter({ hasText: need('reference') })).toBeVisible();
    await app.axe('j4-approvals');
    await openCase(page, id, 'approvals');
    await page.getByTestId('case-decide-approve').click();
    const dialog = page.getByTestId('decision-dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText(fixture.account.last4);
    await app.shot('j4-decision-dialog');
    await dialog.getByTestId('decision-confirm').click();
    await expect(dialog).toBeHidden({ timeout: 20_000 });

    const owner = await openSession();
    await signIn(owner.app, PEOPLE.alex);
    await openCase(owner.page, id, 'approvals');
    await owner.page.getByTestId('case-decide-approve').click();
    const ownerDialog = owner.page.getByTestId('decision-dialog');
    await ownerDialog.getByTestId('decision-confirm').click();
    await expect(ownerDialog).toBeHidden({ timeout: 20_000 });

    await waitForStatus(owner.page, id, ['approved'], 20_000);
    await openCase(owner.page, id, 'timeline');
    await expect(owner.page.getByTestId('decision-banner')).toContainText(/Approved by your team|Согласовано людьми/);
    const events = await owner.page.getByTestId('timeline-item').evaluateAll((nodes) => nodes.map((node) => node.getAttribute('data-event') ?? ''));
    expect(events.filter((event) => event === 'verification.recorded').length).toBeGreaterThanOrEqual(1);
    expect(events.filter((event) => event === 'approval.approved').length).toBe(2);
    await owner.app.shot('j4-approved-timeline');

    const vendorRows = await query<{ last4: string; status: string }>(
      `select a.last4, a.status from vendor_bank_accounts a join vendors v on v.id = a.vendor_id
        join workspaces w on w.id = v.workspace_id where w.slug = $1 and v.name = $2`,
      [`northwind-${testInfo.project.name.replace(/^chromium-/, '')}`, 'Blue Ridge Packaging Co.'],
    );
    const statuses = Object.fromEntries(vendorRows.map((row) => [row.last4, row.status]));
    expect(statuses[fixture.account.last4]).toBe('verified');
    expect(statuses['4417']).toBe(replaces ? 'retired' : 'verified');
  });

  test('J6 the auditor sees the trail, verifies the chain and downloads the evidence packet', { tag: '@flow' }, async ({ page, app }) => {
    const id = need('requestId');
    const ref = need('reference');
    await signIn(app, PEOPLE.pat);

    await page.goto('/audit');
    const rows = page.getByTestId('audit-row');
    await expect(rows.first()).toBeVisible();
    const actions = await rows.evaluateAll((nodes) => nodes.map((node) => node.getAttribute('data-action') ?? ''));
    expect(actions).toEqual(expect.arrayContaining(['payment_request.bank_details_revealed']));
    await app.axe('j6-audit');
    await app.shot('j6-audit');

    const forbidden = await appRequest(page, `/payment-requests/${id}/reveal`, { method: 'POST' });
    expect(forbidden.status()).toBe(403);

    await openCase(page, id, 'bank');
    await expect(page.getByTestId('reveal-bank')).toHaveCount(0);
    await expect(page.getByTestId('case-decide-approve')).toHaveCount(0);

    await page.goto('/reports');
    await expect(page.getByTestId('reports-page')).toBeVisible();
    await page.getByTestId('verify-chain').click();
    await expect(page.getByTestId('chain-status')).toHaveAttribute('data-ok', 'true', { timeout: 30_000 });
    await app.axe('j6-reports');
    await app.shot('j6-reports-chain');

    const verify = await appRequest(page, '/evidence/verify', { method: 'POST' });
    expect(verify.status()).toBe(200);
    const chain = (await verify.json()) as { ok: boolean; checked: number; entries?: number };
    expect(chain.ok).toBe(true);
    expect(chain.checked).toBeGreaterThan(0);
    if (typeof chain.entries === 'number') expect(chain.checked).toBeGreaterThanOrEqual(chain.entries);

    await openCase(page, id, 'timeline');
    const packet = page.getByTestId('evidence-packet');
    await expect(packet).toBeVisible();
    await packet.getByRole('button').last().click();
    let href: string | null = null;
    await expect(async () => {
      await page.reload();
      const link = page.getByTestId('evidence-packet').locator('a[href]');
      await expect(link).toHaveCount(1, { timeout: 2_000 });
      href = await link.getAttribute('href');
    }).toPass({ timeout: 90_000, intervals: [2_000, 3_000] });
    await app.shot('j6-evidence-packet');

    const download = await page.request.get(href!);
    expect(download.status()).toBe(200);
    expect(download.headers()['content-type']).toContain('application/pdf');
    const bytes = await download.body();
    expect(bytes.subarray(0, 5).toString('latin1')).toBe('%PDF-');
    expect(bytes.byteLength).toBeGreaterThan(10_000);
    const exportId = Number(/\/reports\/exports\/(\d+)\/download/.exec(href!)?.[1]);
    expect(exportId, `export id in ${href}`).toBeGreaterThan(0);
    const stored = await query<{ sha256: string }>(
      `select e.sha256 from report_exports e
        where e.id = $1 and e.payment_request_id = $2 and e.kind = 'evidence_packet' and e.status = 'ready'`,
      [exportId, id],
    );
    expect(createHash('sha256').update(bytes).digest('hex')).toBe(stored[0]?.sha256);
    expect(ref).toMatch(/^SP-/);
  });
});
