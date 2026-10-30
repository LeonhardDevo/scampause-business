import { expect, test } from '../../fixtures.ts';
import type { Page } from '@playwright/test';
import { openCase, PEOPLE, query, signIn, cloneSlug } from '../../helpers/domain/session.ts';

const DOMAIN_PAGES = ['/dashboard', '/payment-requests', '/approvals', '/vendors', '/blocked-accounts', '/policies', '/reports', '/payment-requests/create'];

const RAW_KEY = /(?<![\w./-])(?:scampause|domain|ui|risk|marketing|billing)\.[a-z_]+\.[a-z_.]+(?![\w/-])/;
const EMOJI = /[\p{Extended_Pictographic}\u{FE0F}]/u;
const SAFE_CLAIM = /(?<!not mean the )payment is safe|(?<!не )гарантирует безопасность|платёж безопасен|платеж безопасен|safe to pay|можно безопасно оплатить/i;

async function visibleText(page: Page): Promise<string> {
  return page.evaluate(() => document.body.innerText);
}

async function assertCopy(page: Page, path: string): Promise<void> {
  const text = await visibleText(page);
  expect(text.match(RAW_KEY)?.[0] ?? null, `raw translation key on ${path}`).toBeNull();
  expect(text.match(EMOJI)?.[0] ?? null, `emoji on ${path}`).toBeNull();
  expect(text.match(SAFE_CLAIM)?.[0] ?? null, `"safe payment" claim on ${path}`).toBeNull();
}

test.describe('domain screens', () => {
  test('J10 domain pages render localized, themed, accessible and without overflow', { tag: ['@visual', '@flow'] }, async ({ page, app }, testInfo) => {
    test.setTimeout(360_000);
    const errors: string[] = [];
    page.on('console', (message) => {
      if (message.type() === 'error') errors.push(message.text());
    });
    page.on('pageerror', (error) => errors.push(error.message));

    await signIn(app, PEOPLE.alex);
    await app.expectTheme(app.theme);
    for (const path of DOMAIN_PAGES) {
      await test.step(path, async () => {
        const response = await page.goto(path);
        expect(response?.status(), path).toBe(200);
        await app.expectLocale(app.locale);
        await app.expectTheme(app.theme);
        await assertCopy(page, path);
        await app.expectNoHorizontalScroll();
        await app.axe(`domain${path.replace(/\//g, '-')}`);
        await app.shot(`j10${path.replace(/\//g, '-')}-1440`);
      });
    }

    const slug = cloneSlug(testInfo.project.name);
    const rows = await query<{ id: number; reference: string }>(
      `select p.id, p.reference from payment_requests p join workspaces w on w.id = p.workspace_id where w.slug = $1 and p.reference in ('SP-00031', 'SP-00047', 'SP-00040') order by p.reference`,
      [slug],
    );
    expect(rows.length).toBe(3);
    for (const row of rows) {
      for (const tab of ['overview', 'bank', 'sender', 'verification', 'approvals', 'timeline', 'files']) {
        await test.step(`${row.reference} ${tab}`, async () => {
          await openCase(page, row.id, tab === 'overview' ? undefined : tab);
          await assertCopy(page, `${row.reference}/${tab}`);
          await app.expectNoHorizontalScroll();
          if (tab === 'overview' || tab === 'bank') await app.axe(`case-${row.reference}-${tab}`);
        });
      }
    }
    await app.shot('j10-case-file');

    await page.goto('/dashboard');
    const charts = page.getByTestId('dashboard-charts');
    await expect(charts).toBeVisible();
    await expect(charts.locator('canvas').first()).toBeVisible({ timeout: 20_000 });
    const toggles = page.getByTestId('chart-table-toggle');
    if ((await toggles.count()) > 0) {
      await toggles.first().click();
      await expect(charts.locator('table').first()).toBeVisible();
    }

    await page.setViewportSize({ width: 375, height: 812 });
    for (const path of ['/dashboard', '/payment-requests', '/approvals', '/vendors', '/reports', '/policies']) {
      await test.step(`375 ${path}`, async () => {
        await page.goto(path);
        await app.expectNoHorizontalScroll();
        await app.shot(`j10${path.replace(/\//g, '-')}-375`);
      });
    }
    await openCase(page, rows[0]!.id, 'bank');
    await app.expectNoHorizontalScroll();
    await app.shot('j10-case-file-375');

    const relevant = errors.filter((message) => !/favicon|ResizeObserver loop/i.test(message));
    expect(relevant, `console errors:\n${relevant.join('\n')}`).toEqual([]);
  });

  test('J10 the theme is applied before first paint and persists after reload', { tag: ['@visual', '@flow'] }, async ({ page, app }) => {
    await page.addInitScript(() => {
      document.addEventListener('DOMContentLoaded', () => {
        Reflect.set(window, '__darkAtDomReady', document.documentElement.classList.contains('dark'));
      });
    });
    await page.goto('/');
    await app.setLocale(app.locale);
    expect(await page.evaluate(() => Reflect.get(window, '__darkAtDomReady'))).toBe(app.theme === 'dark');
    await page.reload();
    expect(await page.evaluate(() => Reflect.get(window, '__darkAtDomReady'))).toBe(app.theme === 'dark');
    await app.expectTheme(app.theme);
  });
});
