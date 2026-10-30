import { expect, test } from '../../fixtures.ts';
import { jordanDirectDeposit, variantFor } from '../../helpers/domain/documents.ts';
import {
  buttonsOf,
  openCase,
  PEOPLE,
  pressButton,
  reference,
  signalCodes,
  signIn,
  textOf,
  uploadFiles,
  waitForStatus,
  waitForTelegram,
} from '../../helpers/domain/session.ts';

const NOT_ME = /^(?:No, it wasn't me|Нет, это не я)$/;
const IT_WAS_ME = /^(?:Yes, it was me|Да, это я)$/;

test.describe('payroll change: the employee is asked through the known channel', () => {
  test('J3 Jordan denies the direct-deposit change in Telegram and the case turns critical', { tag: '@flow' }, async ({ page, app, request, mailpit }, testInfo) => {
    const fixture = jordanDirectDeposit(variantFor(testInfo.project.name));
    const jordan = PEOPLE.jordan;

    await signIn(app, PEOPLE.maria);
    const id = await uploadFiles(page, [fixture.file]);
    await waitForStatus(page, id, ['review'], 60_000);
    await openCase(page, id);
    const ref = await reference(page);

    const alert = await waitForTelegram(request, jordan.telegramId!, (call) => call.method === 'sendMessage' && buttonsOf(call).some((button) => NOT_ME.test(button.text)) && textOf(call).includes(fixture.last4));
    const labels = buttonsOf(alert).map((button) => button.text);
    expect(labels.some((label) => IT_WAS_ME.test(label))).toBe(true);
    expect(textOf(alert)).not.toContain(fixture.account);
    expect(textOf(alert)).not.toMatch(/\p{Extended_Pictographic}/u);

    await openCase(page, id, 'timeline');
    const delivery = page
      .getByTestId('evidence-timeline')
      .locator('li')
      .filter({ hasText: 'Jordan Lee' })
      .filter({ has: page.locator('svg[aria-label="Telegram"]') });
    await expect(delivery.first()).toBeVisible();
    expect(await mailpit.search(jordan.email).then((messages) => messages.filter((message) => message.Subject.includes(ref)))).toEqual([]);

    const notMe = buttonsOf(alert).find((button) => NOT_ME.test(button.text))!;
    const reply = await pressButton(request, jordan, notMe.callback_data!, app.locale);
    const answer = reply.find((call) => call.method === 'answerCallbackQuery');
    expect(answer, 'the bot did not answer the callback').toBeTruthy();
    expect(String(answer!.payload.text ?? '')).toMatch(/flagged|подозрительн/i);
    expect(String(answer!.payload.text ?? '')).not.toMatch(/on hold|stopped|safe|удержан|остановлен|безопасен/i);

    await expect(async () => {
      await openCase(page, id, 'overview');
      await expect(page.getByTestId('case-header')).toContainText(/Critical risk|Критический риск/, { timeout: 2_000 });
    }).toPass({ timeout: 30_000, intervals: [1_000, 2_000] });
    await expect(page.getByText(/wasn.t me|не я|Not me/i).first()).toBeVisible();
    expect(await signalCodes(page)).toEqual(expect.arrayContaining(['EMPLOYEE_DENIED_CHANGE', 'PAYROLL_ACCOUNT_CHANGE']));
    await app.axe('j3-case-file');
    await app.shot('j3-employee-denied');

    const replay = await pressButton(request, jordan, notMe.callback_data!, app.locale);
    const second = replay.find((call) => call.method === 'answerCallbackQuery');
    expect(String(second?.payload.text ?? '')).toMatch(/no longer valid|больше не действует|already|уже/i);

    await signIn(app, PEOPLE.alex);
    await page.goto('/dashboard');
    const bell = page.getByTestId('notifications-trigger').or(page.getByRole('button', { name: /notifications|уведомления/i })).first();
    await bell.click();
    await expect(page.getByText(ref).first()).toBeVisible();
    await app.shot('j3-owner-notification');
  });
});
