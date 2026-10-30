import { expect, test } from '../../fixtures.ts';
import { harborRemittanceUpdate, variantFor } from '../../helpers/domain/documents.ts';
import { digits, openCase, PEOPLE, query, reference, signIn, uploadFiles, waitForStatus } from '../../helpers/domain/session.ts';

const FORBIDDEN_SAFE_COPY = /payment is safe|платёж безопасен|платеж безопасен|on hold|на удержании|stopped the payment|остановили платёж/i;

async function preventedTotal(page: import('@playwright/test').Page): Promise<number> {
  await page.goto('/reports?preset=30d');
  const tile = page.getByTestId('kpi-prevented-amount');
  await expect(tile).toBeVisible();
  const raw = await tile.locator('p.text-kpi').innerText();
  return Number(digits(raw));
}

test.describe('payment rejection after a denied callback', () => {
  test('J5 a denied callback rejects the request, blocks the account and counts the prevented risk', { tag: '@flow' }, async ({ page, app, mailpit }, testInfo) => {
    const fixture = harborRemittanceUpdate(variantFor(testInfo.project.name));

    await signIn(app, PEOPLE.maria);
    const before = await preventedTotal(page);

    await signIn(app, PEOPLE.irina);
    const id = await uploadFiles(page, [fixture.file]);
    await waitForStatus(page, id, ['review'], 60_000);
    await openCase(page, id);
    const ref = await reference(page);
    expect(digits(await page.getByTestId('case-amount').innerText())).toBe(String(fixture.amountMinor));
    await page.getByTestId('case-submit').click();
    const confirm = page.getByTestId('submit-confirm');
    if (await confirm.isVisible({ timeout: 2_000 }).catch(() => false)) {
      await page.getByTestId('submit-dialog').getByRole('checkbox').check();
      await confirm.click();
    }
    await waitForStatus(page, id, ['awaiting_verification'], 20_000);

    await signIn(app, PEOPLE.maria);
    await openCase(page, id, 'verification');
    await page.getByTestId('case-verify').click();
    const sheet = page.getByTestId('verification-sheet');
    await expect(sheet).toBeVisible();
    await sheet.locator('label').filter({ hasText: 'Tom Becker' }).first().click();
    await sheet.getByTestId('verification-spoken').fill('Tom Becker');
    await sheet.getByRole('radio', { name: /^(?:Denied|Не подтверждено|Опровергнуто|Отрицает|Отказ)/ }).click();
    await sheet.locator('#verification-reason').click();
    await page.getByRole('option', { name: /Fraud confirmed|Мошенничество подтверждено/ }).click();
    await app.shot('j5-denied-callback');
    await sheet.getByTestId('verification-submit').click();
    await expect(sheet).toBeHidden({ timeout: 20_000 });

    await waitForStatus(page, id, ['rejected'], 20_000);
    await openCase(page, id);
    const banner = page.getByTestId('prevented-banner');
    await expect(banner).toBeVisible();
    expect(digits(await banner.innerText())).toContain(String(fixture.amountMinor));
    await expect(page.getByTestId('decision-banner')).toContainText(/Fraud confirmed|Мошенничество подтверждено/);
    const visible = (await page.locator('body').innerText()).replace(/It does not mean the payment is safe\./g, '');
    expect(visible).not.toMatch(FORBIDDEN_SAFE_COPY);
    await app.axe('j5-rejected-case');
    await app.shot('j5-rejected-case');

    await page.goto('/blocked-accounts');
    const row = page.getByTestId('blocked-row').filter({ hasText: fixture.last4 });
    await expect(row).toBeVisible();
    await expect(row).toContainText(/Fraud confirmed|Мошенничество подтверждено/);
    await expect(page.getByTestId('blocked-page')).not.toContainText(fixture.account);
    await app.axe('j5-blocked');
    await app.shot('j5-blocked-accounts');

    const after = await preventedTotal(page);
    expect(after - before).toBe(fixture.amountMinor);
    await app.shot('j5-reports');

    await page.goto('/dashboard');
    await expect(page.getByTestId('dashboard-kpis')).toBeVisible();

    await signIn(app, PEOPLE.irina);
    await expect(async () => {
      const rows = await query<{ data: string }>(
        `select n.data::text as data from notifications n join users u on u.id = n.notifiable_id where u.email = $1 order by n.created_at desc limit 20`,
        [PEOPLE.irina.email],
      );
      expect(rows.some((row) => row.data.includes(ref)), `no in-app notification for ${ref}`).toBe(true);
    }).toPass({ timeout: 30_000, intervals: [1_000, 2_000] });

    await expect(async () => {
      const messages = await mailpit.search(PEOPLE.irina.email);
      expect(messages.some((message) => message.Subject.includes(ref)), `no rejection e-mail for ${ref}`).toBe(true);
    }).toPass({ timeout: 45_000, intervals: [1_000, 3_000] });
  });
});
