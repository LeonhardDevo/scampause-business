import { expect, test } from '../../fixtures.ts';
import { hydrated } from '../../helpers/domain/session.ts';
import { appRequest } from '../../support/http.ts';
import type { Page } from '@playwright/test';

const FREE_MONTHLY_REQUESTS = 15;

function manualRequest(index: number): Record<string, unknown> {
  return {
    title: `Office supplies order ${index + 1}`,
    kind: 'invoice',
    amount: `${120 + index}.00`,
    currency: 'USD',
    invoice_number: `OS-${1000 + index}`,
    payee: { scheme: 'us_ach', account_number: `55001${String(2000 + index)}`, routing_code: '021000021', account_holder: 'Summit Office Supply' },
  };
}

async function createManual(page: Page, index: number): Promise<number> {
  const response = await appRequest(page, '/payment-requests/manual', { method: 'POST', data: manualRequest(index) });
  return response.status();
}

async function simulate(page: Page, event: string): Promise<void> {
  const response = await appRequest(page, `/dev/billing/simulate/${event}`, { method: 'POST' });
  expect(response.status(), `simulate ${event}: ${await response.text()}`).toBe(200);
}

async function expectSubscription(page: Page, status: string, plan?: string): Promise<void> {
  const badge = page.getByTestId('subscription-status');
  await expect(async () => {
    await page.goto('/settings/billing');
    await expect(badge).toHaveAttribute('data-status', status, { timeout: 2_000 });
  }).toPass({ timeout: 45_000, intervals: [1_000, 2_000] });
  if (plan) await expect(badge).toHaveAttribute('data-plan', plan);
}

test.describe('subscription lifecycle with the fake Stripe gateway', () => {
  test('J7 free limit, Starter checkout, trial, renewal, cancellation, failed payment and period end', { tag: '@flow' }, async ({ page, app, owner: _owner }) => {
    await page.goto('/settings/billing');
    await expect(page.getByTestId('subscription-status')).toHaveAttribute('data-status', 'free');
    await app.shot('j7-billing-free');

    for (let index = 0; index < FREE_MONTHLY_REQUESTS; index++) {
      const status = await createManual(page, index);
      expect(status, `manual request ${index + 1}`).toBeLessThan(400);
    }
    const blocked = await createManual(page, FREE_MONTHLY_REQUESTS);
    expect(blocked === 402 || blocked === 422 || blocked === 429 || blocked === 403 || (blocked >= 300 && blocked < 400)).toBe(true);
    const count = await page.request.get('/payment-requests', { headers: { Accept: 'text/html' } });
    expect(count.ok()).toBeTruthy();

    await page.goto('/payment-requests/create');
    await expect(page.getByTestId('limit-card')).toBeVisible();
    await expect(page.getByTestId('stop-call-confirm').first()).toBeVisible();
    await app.axe('j7-limit');
    await app.shot('j7-limit-reached');

    await page.goto('/settings/billing');
    await page.getByTestId('plan-subscribe-starter').click();
    await expect(page).toHaveURL(/\/dev\/fake-checkout\//, { timeout: 20_000 });
    await hydrated(page);
    await page.getByTestId('fake-checkout-pay').click();
    await expect(page).toHaveURL(/\/settings\/billing/, { timeout: 20_000 });
    await expectSubscription(page, 'active', 'starter');

    await simulate(page, 'trial');
    await expectSubscription(page, 'trialing', 'starter');
    await app.shot('j7-trialing');

    await simulate(page, 'invoice-paid');
    await expectSubscription(page, 'active', 'starter');

    expect(await createManual(page, FREE_MONTHLY_REQUESTS + 1)).toBeLessThan(400);
    await page.goto('/payment-requests/create');
    await expect(page.getByTestId('limit-card')).toHaveCount(0);

    await simulate(page, 'cancel-at-period-end');
    await expect(async () => {
      await page.goto('/settings/billing');
      await expect(page.getByTestId('subscription-status').locator('xpath=..')).toContainText(/Cancels on|Отключится/, { timeout: 2_000 });
    }).toPass({ timeout: 45_000, intervals: [1_000, 2_000] });
    await app.shot('j7-cancels');

    await simulate(page, 'payment-failed');
    await expectSubscription(page, 'past_due', 'starter');
    await expect(page.getByRole('alert').first()).toBeVisible();
    await app.axe('j7-past-due');
    await app.shot('j7-past-due');

    await simulate(page, 'period-end');
    await expect(async () => {
      await page.goto('/settings/billing');
      await expect(page.getByTestId('subscription-status')).toHaveAttribute('data-plan', 'free', { timeout: 2_000 });
    }).toPass({ timeout: 45_000, intervals: [1_000, 2_000] });
    await page.goto('/payment-requests/create');
    await expect(page.getByTestId('limit-card')).toBeVisible();
    await app.shot('j7-canceled-free');
  });
});
