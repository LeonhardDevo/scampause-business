import { expect, test } from '../../fixtures.ts';
import { andesInvoice, variantFor } from '../../helpers/domain/documents.ts';
import {
  buttonsOf,
  openCase,
  PEOPLE,
  pressButton,
  reference,
  signIn,
  textOf,
  uploadFiles,
  waitForStatus,
  waitForTelegram,
  type Person,
  type TelegramCall,
} from '../../helpers/domain/session.ts';

const APPROVE = /^(?:Approve|Согласовать)$/;
const CONFIRM = /^(?:Confirm approval|Подтвердить согласование|Confirm|Подтвердить)$/;
const REJECT = /^(?:Reject|Отклонить)$/;

function button(call: TelegramCall, label: RegExp): string {
  const found = buttonsOf(call).find((candidate) => label.test(candidate.text) && candidate.callback_data);
  if (!found) throw new Error(`no "${label}" button in: ${buttonsOf(call).map((candidate) => candidate.text).join(', ')}`);
  return found.callback_data!;
}

test.describe('approval through the Telegram bot', () => {
  test('J4b the second approver signs a low-risk invoice in Telegram with a confirmation step', { tag: '@flow' }, async ({ page, app, request }, testInfo) => {
    const fixture = andesInvoice(variantFor(testInfo.project.name));

    await signIn(app, PEOPLE.irina);
    const id = await uploadFiles(page, [fixture.file]);
    await waitForStatus(page, id, ['review'], 60_000);
    await openCase(page, id);
    const ref = await reference(page);
    await expect(page.getByTestId('case-header')).toContainText(/Few signals|Мало сигналов/);
    await page.getByTestId('case-submit').click();
    await waitForStatus(page, id, ['awaiting_approval'], 20_000);

    await openCase(page, id, 'approvals');
    const slots = page.getByTestId('route-card').first().getByTestId('route-slot');
    await expect(slots).toHaveCount(2);
    const names = (await slots.locator('p.truncate').allInnerTexts()).map((name) => name.trim());
    const assignee = Object.values(PEOPLE).find((person) => person.key !== 'alex' && names.includes(person.name)) as Person | undefined;
    expect(assignee, `slot assignees: ${names.join(', ')}`).toBeTruthy();
    expect(names).toContain(PEOPLE.alex.name);

    const requestMessage = await waitForTelegram(request, assignee!.telegramId!, (call) => call.method === 'sendMessage' && textOf(call).includes(ref) && buttonsOf(call).some((candidate) => APPROVE.test(candidate.text)));
    expect(buttonsOf(requestMessage).some((candidate) => REJECT.test(candidate.text))).toBe(true);
    expect(textOf(requestMessage)).not.toContain('ES7921000813610123456789');
    expect(textOf(requestMessage)).toMatch(/does not mean the payment is safe|не гарантирует безопасность|не означает/);

    const approveData = button(requestMessage, APPROVE);
    const step = await pressButton(request, assignee!, approveData, app.locale);
    const confirmView = step.find((call) => (call.method === 'editMessageText' || call.method === 'sendMessage') && buttonsOf(call).some((candidate) => CONFIRM.test(candidate.text)));
    expect(confirmView, `no confirmation view: ${JSON.stringify(step.map((call) => call.method))}`).toBeTruthy();

    const done = await pressButton(request, assignee!, button(confirmView!, CONFIRM), app.locale);
    const edited = done.find((call) => call.method === 'editMessageText');
    expect(edited, 'the Telegram message was not edited after approval').toBeTruthy();
    expect(textOf(edited!)).toMatch(/Approved by you|Вы согласовали|согласовано вами/i);

    const stale = await pressButton(request, assignee!, approveData, app.locale);
    const staleAnswer = stale.find((call) => call.method === 'answerCallbackQuery');
    expect(String(staleAnswer?.payload.text ?? '')).toMatch(/no longer valid|больше не действует|already|уже/i);

    const foreign = await pressButton(request, PEOPLE.jordan, approveData, app.locale);
    const foreignAnswer = foreign.find((call) => call.method === 'answerCallbackQuery');
    expect(String(foreignAnswer?.payload.text ?? '')).toMatch(/another user|другому пользователю|no longer valid|больше не действует/i);

    await openCase(page, id, 'approvals');
    const decided = page.getByTestId('route-slot').filter({ hasText: assignee!.name });
    await expect(decided).toHaveAttribute('data-status', 'approved');
    await expect(decided).toContainText(/Telegram/);

    await signIn(app, PEOPLE.alex);
    await openCase(page, id, 'approvals');
    await page.getByTestId('case-decide-approve').click();
    const dialog = page.getByTestId('decision-dialog');
    await dialog.getByTestId('decision-confirm').click();
    await expect(dialog).toBeHidden({ timeout: 20_000 });
    await waitForStatus(page, id, ['approved'], 20_000);
    await app.shot('j4b-approved-via-telegram');
  });
});
