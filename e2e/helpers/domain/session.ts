import { expect, type APIRequestContext, type Locator, type Page, type TestInfo } from '@playwright/test';
import pg from 'pg';
import type { AppHelpers } from '../../fixtures.ts';
import { loadConfig, RESOLVED_MODE_ENV } from '../../support/config.ts';
import { appRequest } from '../../support/http.ts';
import type { FixtureFile } from './documents.ts';

export type PersonKey = 'alex' | 'maria' | 'sam' | 'irina' | 'jordan' | 'pat';

export interface Person {
  key: PersonKey;
  email: string;
  name: string;
  telegramId: number | null;
}

const cfg = loadConfig();

export const PEOPLE: Readonly<Record<PersonKey, Person>> = {
  alex: { key: 'alex', email: `demo@${cfg.slug}.test`, name: 'Alex Morgan', telegramId: 900001 },
  maria: { key: 'maria', email: `maria@${cfg.slug}.test`, name: 'Мария Ковалёва', telegramId: 900002 },
  sam: { key: 'sam', email: `sam@${cfg.slug}.test`, name: 'Sam Patel', telegramId: 900003 },
  irina: { key: 'irina', email: `irina@${cfg.slug}.test`, name: 'Ирина Соколова', telegramId: 900004 },
  jordan: { key: 'jordan', email: `jordan@${cfg.slug}.test`, name: 'Jordan Lee', telegramId: 900005 },
  pat: { key: 'pat', email: `auditor@${cfg.slug}.test`, name: 'Pat Kim', telegramId: null },
};

export const CLONE_PROJECTS = ['en-light', 'ru-dark', 'en-dark', 'ru-light'] as const;

export function cloneSlug(projectName: string): string {
  const variant = projectName.replace(/^chromium-(?:mobile-)?/, '');
  return `northwind-${(CLONE_PROJECTS as readonly string[]).includes(variant) ? variant : 'en-light'}`;
}

function databaseName(): string {
  if (process.env.E2E_DB_NAME) return process.env.E2E_DB_NAME;
  return process.env[RESOLVED_MODE_ENV] === 'reuse' ? cfg.database.devName : cfg.database.e2eName;
}

export async function query<T extends pg.QueryResultRow>(sql: string, params: unknown[] = []): Promise<T[]> {
  const client = new pg.Client({
    host: cfg.database.host,
    port: cfg.database.port,
    user: cfg.database.user,
    password: cfg.database.password,
    database: databaseName(),
    connectionTimeoutMillis: 5_000,
  });
  await client.connect();
  try {
    return (await client.query<T>(sql, params)).rows;
  } finally {
    await client.end();
  }
}

const workspaceIds = new Map<string, number>();

export async function workspaceId(slug: string): Promise<number> {
  const cached = workspaceIds.get(slug);
  if (cached !== undefined) return cached;
  const rows = await query<{ id: number }>('select id from workspaces where slug = $1', [slug]);
  const id = rows[0]?.id;
  if (id === undefined) throw new Error(`workspace "${slug}" does not exist — globalSetup creates one clone per project`);
  workspaceIds.set(slug, Number(id));
  return Number(id);
}

export async function switchWorkspace(page: Page, slug: string): Promise<void> {
  const id = await workspaceId(slug);
  const response = await appRequest(page, `/workspaces/${id}/switch`, { method: 'POST' });
  expect(response.status(), `switch to ${slug}`).toBeLessThan(400);
}

export async function signIn(app: AppHelpers, person: Person): Promise<void> {
  await app.page.context().clearCookies();
  await app.page.context().addCookies([{ name: 'appearance', value: app.theme, url: cfg.baseURL, sameSite: 'Lax' }]);
  await app.login(person.email);
  await switchWorkspace(app.page, cloneSlug(app.testInfo.project.name));
  await app.setLocale(app.locale);
}

export function digits(value: string | null): string {
  return (value ?? '').replace(/\D+/g, '');
}

export async function uploadFiles(page: Page, files: FixtureFile[]): Promise<number> {
  await page.goto('/dashboard');
  const entry = page.getByTestId('header-check-payment');
  await entry.click();
  await expect(page).toHaveURL(/\/payment-requests\/create/);
  await page.getByTestId('tab-upload').click();
  await page.getByTestId('file-input').setInputFiles(files);
  await expect(page.getByTestId('selected-file').first()).toBeVisible();
  await page.getByTestId('upload-submit').click();
  await expect(page).toHaveURL(/\/payment-requests\/\d+(?:[?#]|$)/, { timeout: 30_000 });
  return requestIdFrom(page);
}

export function requestIdFrom(page: Page): number {
  const match = /\/payment-requests\/(\d+)/.exec(new URL(page.url()).pathname);
  if (!match) throw new Error(`not on a case file: ${page.url()}`);
  return Number(match[1]);
}

export async function hydrated(page: Page): Promise<void> {
  await page.waitForFunction(() => Boolean((document.getElementById('app') as (HTMLElement & { __vue_app__?: unknown }) | null)?.__vue_app__));
}

export async function openCase(page: Page, id: number, tab?: string): Promise<Locator> {
  await page.goto(`/payment-requests/${id}${tab ? `?tab=${tab}` : ''}`);
  const caseFile = page.getByTestId('case-file');
  await expect(caseFile).toBeVisible();
  return caseFile;
}

export async function waitForStatus(page: Page, id: number, statuses: readonly string[], timeoutMs = 60_000): Promise<string> {
  let seen = '';
  await expect(async () => {
    const response = await page.request.get(`/payment-requests/${id}/status`, { headers: { Accept: 'application/json' } });
    expect(response.ok(), `status endpoint HTTP ${response.status()}`).toBeTruthy();
    const body = (await response.json()) as { status: string };
    seen = body.status;
    expect(statuses, `status is ${seen}`).toContain(seen);
  }).toPass({ timeout: timeoutMs, intervals: [500, 1_000, 2_000] });
  return seen;
}

export async function reference(page: Page): Promise<string> {
  return (await page.getByTestId('case-reference').innerText()).trim();
}

export async function signalCodes(page: Page): Promise<string[]> {
  return page.getByTestId('signal').evaluateAll((nodes) => nodes.map((node) => node.getAttribute('data-code') ?? ''));
}

export interface TelegramButton {
  text: string;
  callback_data?: string;
  url?: string;
}

export interface TelegramCall {
  method: string;
  payload: Record<string, unknown>;
}

export async function telegramOutbox(request: APIRequestContext, chatId: number): Promise<TelegramCall[]> {
  const response = await request.get(`${cfg.workerURL}/dev/telegram/outbox`);
  expect(response.ok(), `GET /dev/telegram/outbox → HTTP ${response.status()}`).toBeTruthy();
  const { outbound } = (await response.json()) as { outbound: TelegramCall[] };
  return outbound.filter((call) => String(call.payload.chat_id ?? '') === String(chatId));
}

export function buttonsOf(call: TelegramCall): TelegramButton[] {
  const markup = call.payload.reply_markup as { inline_keyboard?: TelegramButton[][] } | string | undefined;
  const parsed = typeof markup === 'string' ? (JSON.parse(markup) as { inline_keyboard?: TelegramButton[][] }) : markup;
  return (parsed?.inline_keyboard ?? []).flat();
}

export async function waitForTelegram(
  request: APIRequestContext,
  chatId: number,
  match: (call: TelegramCall) => boolean,
  timeoutMs = 45_000,
): Promise<TelegramCall> {
  let found: TelegramCall | undefined;
  await expect(async () => {
    const calls = await telegramOutbox(request, chatId);
    found = [...calls].reverse().find(match);
    const seen = calls.map((call) => `${call.method}: ${textOf(call).slice(0, 80)} {${Object.keys(call.payload).join(',')}} ${JSON.stringify(call.payload.reply_markup ?? null).slice(0, 200)} [${buttonsOf(call).map((button) => button.text).join(' | ')}]`);
    expect(found, `no matching Telegram message for chat ${chatId} among ${calls.length}: ${seen.join(' || ')}`).toBeTruthy();
  }).toPass({ timeout: timeoutMs, intervals: [500, 1_000, 2_000] });
  return found!;
}

export async function pressButton(request: APIRequestContext, person: Person, callbackData: string, locale: 'en' | 'ru'): Promise<TelegramCall[]> {
  if (person.telegramId === null) throw new Error(`${person.key} has no Telegram link`);
  const response = await request.post(`${cfg.workerURL}/dev/telegram/update`, {
    data: { from: { id: person.telegramId, first_name: person.name.split(' ')[0], language_code: locale }, callback_data: callbackData },
  });
  expect(response.ok(), `dev/telegram/update → HTTP ${response.status()}: ${await response.text()}`).toBeTruthy();
  return ((await response.json()) as { outbound: TelegramCall[] }).outbound;
}

export function textOf(call: TelegramCall): string {
  return String(call.payload.text ?? '');
}

export async function screenshot(app: AppHelpers, journey: string, step: string): Promise<void> {
  await app.shot(`${journey}-${step}`);
}

export function projectVariantName(testInfo: TestInfo): string {
  return testInfo.project.name.replace(/^chromium-/, '');
}
