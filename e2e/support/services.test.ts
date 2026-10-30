import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { AI_OFFLINE_ENV, e2eOverrides, rewriteDatabaseUrl, type E2EContext } from './services.ts';

const ctx: E2EContext = {
  devDb: 'scampause',
  e2eDb: 'scampause_e2e',
  baseURL: 'http://127.0.0.1:8300',
  workerURL: 'http://127.0.0.1:8301',
  aiURL: 'http://127.0.0.1:8301',
  slug: 'scampause-business',
};

describe('rewriteDatabaseUrl', () => {
  it('rewrites every postgres driver prefix and keeps query strings', () => {
    assert.equal(rewriteDatabaseUrl('postgres://app:app@127.0.0.1:5443/scampause', 'scampause', 'scampause_e2e'), 'postgres://app:app@127.0.0.1:5443/scampause_e2e');
    assert.equal(
      rewriteDatabaseUrl('postgresql+psycopg://app:app@127.0.0.1:5443/scampause?sslmode=disable', 'scampause', 'scampause_e2e'),
      'postgresql+psycopg://app:app@127.0.0.1:5443/scampause_e2e?sslmode=disable',
    );
  });

  it('leaves other databases and non-postgres URLs alone', () => {
    assert.equal(rewriteDatabaseUrl('postgres://app:app@127.0.0.1:5443/scampause_test', 'scampause', 'scampause_e2e'), null);
    assert.equal(rewriteDatabaseUrl('redis://127.0.0.1:6393/0', 'scampause', 'scampause_e2e'), null);
    assert.equal(rewriteDatabaseUrl('http://127.0.0.1:8300/scampause', 'scampause', 'scampause_e2e'), null);
  });
});

describe('e2eOverrides', () => {
  it('web: isolated database, cache prefixes, fake billing and the e2e origin', () => {
    const overrides = e2eOverrides('web', { DB_DATABASE: 'scampause', DB_URL: '' }, ctx);
    assert.equal(overrides.APP_ENV, 'e2e');
    assert.equal(overrides.DB_DATABASE, 'scampause_e2e');
    assert.equal(overrides.APP_URL, ctx.baseURL);
    assert.equal(overrides.BILLING_DRIVER, 'fake');
    assert.match(overrides.REDIS_PREFIX!, /e2e/);
    assert.equal(overrides.DB_URL, undefined);
  });

  it('worker: rewrites DATABASE_URL, enables dev routes and keeps Telegram offline', () => {
    const overrides = e2eOverrides('worker', { DATABASE_URL: 'postgres://app:app@127.0.0.1:5443/scampause', TELEGRAM_BOT_TOKEN: 'real' }, ctx);
    assert.equal(overrides.DATABASE_URL, 'postgres://app:app@127.0.0.1:5443/scampause_e2e');
    assert.equal(overrides.DEV_ROUTES, '1');
    assert.equal(overrides.TELEGRAM_BOT_TOKEN, '');
    assert.equal(overrides.TELEGRAM_MODE, 'disabled');
    assert.equal(overrides.WEB_URL, ctx.baseURL);
    assert.notEqual(overrides.QUEUE_PREFIX, 'bull');
    assert.match(overrides.REDIS_KEY_PREFIX!, /e2e/);
    assert.equal(overrides.DOMAIN_INTEL_DRIVER, 'fake');
  });

  it('worker: hosts the RAG service, so it gets the offline providers and its own AI_URL', () => {
    const overrides = e2eOverrides('worker', { RAG_DATABASE_URL: 'postgres://app:app@127.0.0.1:5443/scampause' }, ctx);
    assert.equal(overrides.RAG_DATABASE_URL, 'postgres://app:app@127.0.0.1:5443/scampause_e2e');
    assert.equal(overrides.AI_URL, ctx.workerURL);
    for (const [key, value] of Object.entries(AI_OFFLINE_ENV)) assert.equal(overrides[key], value);
  });
});
