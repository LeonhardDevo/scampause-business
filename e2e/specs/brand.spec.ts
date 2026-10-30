import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import type { Browser, Page } from '@playwright/test';
import { config, expect, test, type AppLocale, type AppTheme } from '../fixtures.ts';
import { isExternalRequest } from '../support/network.ts';

const BRAND_DIR = join(config.artifactsDir, 'brand');

const THEMES: AppTheme[] = ['light', 'dark'];
const LOCALES: AppLocale[] = ['ru', 'en'];
const WIDTHS = [1440, 375] as const;

interface Screen {
  name: string;
  path: string;
  auth: boolean;
  ready: (page: Page) => Promise<void>;
}

const SCREENS: Screen[] = [
  { name: 'landing', path: '/', auth: false, ready: (page) => expect(page.getByTestId('landing-hero')).toBeVisible() },
  { name: 'register', path: '/register', auth: false, ready: (page) => expect(page.getByTestId('register-user-button')).toBeVisible() },
  { name: 'dashboard', path: '/dashboard', auth: true, ready: (page) => expect(page.getByTestId('dashboard-kpis')).toBeVisible() },
  { name: 'assistant', path: '/assistant', auth: true, ready: (page) => expect(page.getByTestId('assistant-send')).toBeVisible() },
  { name: 'knowledge', path: '/knowledge', auth: true, ready: (page) => expect(page.locator('h1').first()).toBeVisible() },
  { name: 'billing', path: '/settings/billing', auth: true, ready: (page) => expect(page.getByTestId('subscription-status')).toBeVisible() },
  { name: 'settings', path: '/settings/workspace', auth: true, ready: (page) => expect(page.locator('h1').first()).toBeVisible() },
];

type StorageState = Awaited<ReturnType<import('@playwright/test').BrowserContext['storageState']>>;

let memberState: StorageState | null = null;

async function openVariant(browser: Browser, theme: AppTheme, locale: AppLocale, width: number, storageState?: StorageState) {
  const context = await browser.newContext({
    ...(storageState ? { storageState } : {}),
    baseURL: config.baseURL,
    viewport: { width, height: width < 768 ? 812 : 900 },
    deviceScaleFactor: 1,
    colorScheme: theme,
    locale: locale === 'ru' ? 'ru-RU' : 'en-US',
    reducedMotion: 'reduce',
    serviceWorkers: 'block',
  });
  await context.addCookies([
    { name: 'appearance', value: theme, url: config.baseURL, sameSite: 'Lax' },
    { name: 'cookie_notice', value: '1', url: config.baseURL, sameSite: 'Lax' },
  ]);
  await context.addInitScript((value) => {
    try {
      window.localStorage.setItem('appearance', value);
    } catch {
      return;
    }
  }, theme);
  const external: string[] = [];
  await context.route(
    (url) => isExternalRequest(url.toString(), config.allowedHosts),
    async (route) => {
      external.push(route.request().url());
      await route.abort('blockedbyclient');
    },
  );
  return { context, page: await context.newPage(), external };
}

async function signIn(page: Page): Promise<void> {
  await page.goto('/login');
  await page.locator('input[name="email"]').fill(config.credentials.demo);
  await page.locator('input[name="password"]').fill(config.credentials.password);
  await page.getByTestId('login-button').click();
  await expect(page).not.toHaveURL(/\/login(?:[?#]|$)/, { timeout: 20_000 });
}

test.describe('brand gallery', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium-en-light', 'the gallery covers every theme, locale and width itself');
  });

  for (const theme of THEMES) {
    for (const locale of LOCALES) {
      test(`captures key screens in ${theme} / ${locale} at 1440 and 375`, { tag: ['@visual', '@brand'] }, async ({ browser }) => {
        test.setTimeout(240_000);
        mkdirSync(BRAND_DIR, { recursive: true });

        for (const width of WIDTHS) {
          const guest = await openVariant(browser, theme, locale, width);
          if (!memberState) {
            const login = await openVariant(browser, theme, locale, width);
            await signIn(login.page);
            memberState = await login.context.storageState();
            await login.context.close();
          }
          const member = await openVariant(browser, theme, locale, width, memberState);

          for (const screen of SCREENS) {
            const { page } = screen.auth ? member : guest;
            await page.goto(`${screen.path}?lang=${locale}`);
            await screen.ready(page);
            await expect(page.locator('html')).toHaveAttribute('lang', new RegExp(`^${locale}`));
            if (theme === 'dark') await expect(page.locator('html')).toHaveClass(/(?:^|\s)dark(?:\s|$)/);
            else await expect(page.locator('html')).not.toHaveClass(/(?:^|\s)dark(?:\s|$)/);
            await page.evaluate(() => document.fonts.ready);
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            expect(overflow, `${screen.name} is wider than ${width}px`).toBeLessThanOrEqual(1);
            await page.screenshot({
              path: join(BRAND_DIR, `${screen.name}-${theme}-${locale}-${width}.png`),
              fullPage: true,
              animations: 'disabled',
              caret: 'hide',
            });
          }

          expect([...guest.external, ...member.external], 'requests outside localhost').toEqual([]);
          await guest.context.close();
          await member.context.close();
        }
      });
    }
  }
});
