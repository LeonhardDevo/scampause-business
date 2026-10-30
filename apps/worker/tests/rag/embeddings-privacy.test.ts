import { describe, expect, it } from 'vitest';
import { createSilentLogger } from '../../src/logger.js';
import { SecretBox } from '../../src/rag/crypto.js';
import { HashEmbedder } from '../../src/rag/embeddings/hash.js';
import { LocalEmbedder, wordWindows, type FeatureExtractor } from '../../src/rag/embeddings/local.js';
import { EmbeddingsRegistry } from '../../src/rag/embeddings/registry.js';
import { RemoteEmbedder } from '../../src/rag/embeddings/remote.js';
import { calibrate, l2Normalize } from '../../src/rag/embeddings/types.js';
import { redactPii } from '../../src/rag/privacy/redact.js';
import { ProviderKeys } from '../../src/rag/providers/keys.js';
import { AiSettingsService, MemorySettingsStore } from '../../src/rag/settings/store.js';
import { ragConfig } from './support/harness.js';

const logger = createSilentLogger();

function cosine(a: number[], b: number[]): number {
  return a.reduce((sum, value, index) => sum + value * (b[index] ?? 0), 0);
}

function registryFor(env: Record<string, string>, options: ConstructorParameters<typeof EmbeddingsRegistry>[4] = {}) {
  const config = ragConfig(env);
  const store = new MemorySettingsStore();
  const settings = new AiSettingsService(store, config);
  const keys = new ProviderKeys(store, config.providerKeys, new SecretBox('k'), logger);
  return { registry: new EmbeddingsRegistry(config, settings, keys, logger, options), settings };
}

const fakeExtractor = (dims: number, calls: string[][]): FeatureExtractor => {
  const extractor = async (texts: string[]) => {
    calls.push(texts);
    return { tolist: () => texts.map((text) => Array.from({ length: dims }, (_, index) => (index === text.length % dims ? 1 : 0.01))) };
  };
  return extractor;
};

describe('embeddings', () => {
  it('hash embeddings are deterministic, normalized and lexical', async () => {
    const embedder = new HashEmbedder();
    expect(embedder.modelId).toBe('hash:hash-384');
    const [a, b, c] = await embedder.embedDocuments(['Call the vendor back on the contract number', 'call vendor contract number', 'Kitchen cleaning on Fridays']);
    expect(a).toHaveLength(384);
    expect(Math.hypot(...a!)).toBeCloseTo(1, 6);
    expect(await embedder.embedQuery('Call the vendor back on the contract number')).toEqual(a);
    expect(cosine(a!, b!)).toBeGreaterThan(cosine(a!, c!));
    expect(new HashEmbedder(64).modelId).toBe('hash:hash-64');
  });

  it('normalizes and calibrates', () => {
    expect(l2Normalize([3, 4])).toEqual([0.6, 0.8]);
    expect(l2Normalize([0, 0])).toEqual([0, 0]);
    expect(calibrate(0.3, [0.05, 0.55])).toBeCloseTo(0.5);
    expect(calibrate(0.9, [0.05, 0.55])).toBe(1);
    expect(calibrate(0.5, [0.5, 0.5])).toBe(0.5);
  });

  it('splits long texts into overlapping word windows', () => {
    const text = Array.from({ length: 10 }, (_, index) => `w${index}`).join(' ');
    expect(wordWindows(text, 20, 5)).toEqual([text]);
    expect(wordWindows(text, 4, 1)).toEqual(['w0 w1 w2 w3', 'w3 w4 w5 w6', 'w6 w7 w8 w9']);
  });

  it('mean-pools local model windows and loads the model lazily once', async () => {
    const calls: string[][] = [];
    let loads = 0;
    const embedder = new LocalEmbedder('Xenova/paraphrase-multilingual-MiniLM-L12-v2', 'C:/tmp/cache', 2, async () => {
      loads += 1;
      return fakeExtractor(384, calls);
    });
    expect(loads).toBe(0);
    const long = Array.from({ length: 130 }, (_, index) => `word${index}`).join(' ');
    const [vector] = await embedder.embedDocuments([long]);
    await embedder.embedQuery('short');
    expect(loads).toBe(1);
    expect(calls.flat().length).toBe(4);
    expect(Math.hypot(...vector!)).toBeCloseTo(1, 6);
  });

  it('rejects a local model returning the wrong dimensions', async () => {
    const embedder = new LocalEmbedder('Xenova/paraphrase-multilingual-MiniLM-L12-v2', 'C:/tmp/cache', 8, async () => fakeExtractor(10, []));
    await expect(embedder.embedQuery('x')).rejects.toThrow(/10 dims/);
  });

  it('falls back to hash when the local model cannot load and builds a model once under concurrency', async () => {
    let loads = 0;
    const { registry } = registryFor(
      { EMBEDDINGS_PROVIDER: 'local' },
      {
        localLoader: async () => {
          loads += 1;
          await new Promise((resolve) => setTimeout(resolve, 20));
          throw new Error('offline');
        },
      },
    );
    const results = await Promise.all([registry.active(), registry.active(), registry.status()]);
    expect(loads).toBe(1);
    expect(results[0].modelId).toBe('hash:hash-384');
    expect(results[2]).toMatchObject({ degraded: true, configured: 'local:Xenova/paraphrase-multilingual-MiniLM-L12-v2' });
    expect(await registry.peek()).toMatchObject({ state: 'degraded' });
  });

  it('uses the settings choice and gives interactive callers hash while a model loads', async () => {
    let release!: () => void;
    const { registry, settings } = registryFor(
      {},
      {
        localLoader: () =>
          new Promise((resolve) => {
            release = () => resolve(fakeExtractor(384, []));
          }),
      },
    );
    expect((await registry.active()).modelId).toBe('hash:hash-384');
    await settings.update({ embeddings: { provider: 'local' } });
    const interactive = await registry.active(10);
    expect(interactive.modelId).toBe('hash:hash-384');
    expect((await registry.peek()).state).toBe('loading');
    release();
    expect((await registry.active()).modelId).toBe('local:Xenova/paraphrase-multilingual-MiniLM-L12-v2');
    expect((await registry.peek()).state).toBe('ready');
  });

  it('keeps embeddings on the device when the workspace forbids external providers', async () => {
    const fetchImpl = (async () => {
      throw new Error('external embeddings must not be called');
    }) as typeof fetch;
    const { registry, settings } = registryFor({ EMBEDDINGS_BASE_URL: 'http://emb.test/v1', EMBEDDINGS_API_KEY: 'k' }, { fetch: fetchImpl });
    await settings.update({ embeddings: { provider: 'openai-compatible', model: 'text-embedding-3-small' } });
    expect(await registry.isExternal()).toBe(true);
    const blocked = await registry.active(undefined, { externalAllowed: false });
    expect(blocked.modelId).toBe('hash:hash-384');
    expect(await blocked.embedQuery('Call the vendor on the contract number')).toHaveLength(384);
    expect((await registry.active(undefined, { externalAllowed: true })).modelId).toBe('openai-compatible:text-embedding-3-small');

    await settings.update({ embeddings: { provider: 'hash' } });
    expect(await registry.isExternal()).toBe(false);
    expect((await registry.active(undefined, { externalAllowed: false })).modelId).toBe('hash:hash-384');
  });

  it('remote embedder batches requests and validates dimensions', async () => {
    const bodies: Array<{ input: string[] }> = [];
    const fetchImpl = (async (_url: string | URL | Request, init?: RequestInit) => {
      const body = JSON.parse(typeof init?.body === 'string' ? init.body : '{}') as { input: string[] };
      bodies.push(body);
      const data = body.input.map((_, index) => ({ index, embedding: [1, 0, 0, 0] }));
      return new Response(JSON.stringify({ data: data.reverse() }), { status: 200, headers: { 'content-type': 'application/json' } });
    }) as typeof fetch;
    const embedder = new RemoteEmbedder({ baseUrl: 'http://emb.test/v1', model: 'text-embedding-3-small', apiKey: 'k', dims: 4, batchSize: 2, fetch: fetchImpl });
    expect(embedder.modelId).toBe('openai-compatible:text-embedding-3-small');
    expect(await embedder.embedDocuments(['a', 'b', 'c'])).toHaveLength(3);
    expect(bodies.map((body) => body.input.length)).toEqual([2, 1]);
    const wrong = new RemoteEmbedder({ baseUrl: 'http://emb.test/v1', model: 'm', apiKey: null, dims: 8, fetch: fetchImpl });
    await expect(wrong.embedQuery('x')).rejects.toThrow(/EMBEDDINGS_DIMS/);
  });
});

describe('PII redaction', () => {
  it('masks e-mails, phones, IBANs, cards and account numbers to the last 4', () => {
    const text = [
      'E-mail karen.whitfield.ar@gmail.com today.',
      'Call +1 828 555 0199 or (646) 555-0142.',
      'IBAN LT09 1234 5678 9012 8839 BANK.',
      'Card 4111 1111 1111 1111.',
      'Account 123456789012 and routing 021000021.',
    ].join(' ');
    const redacted = redactPii(text);
    expect(redacted).toContain('•••@gmail.com');
    expect(redacted).toContain('•••0199');
    expect(redacted).toContain('•••0142');
    expect(redacted).toContain('LT•••8839 BANK');
    expect(redacted).toContain('•••• 1111');
    expect(redacted).toContain('•••9012');
    expect(redacted).toContain('•••0021');
    expect(redacted).not.toMatch(/\d{5,}/);
  });

  it('keeps amounts, dates, thousands and labelled registry numbers', () => {
    const text = 'Pay $18,400.00 or 10 000 000 RUB by 2026-10-22, invoice SP-00042, ИНН 7701234567, БИК 044525225, ref 2026.';
    expect(redactPii(text)).toBe(text);
  });
});
