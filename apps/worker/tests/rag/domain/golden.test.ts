import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { createOutputGuard } from '../../../src/rag/domain/outputGuard.js';
import { localeDedupe } from '../../../src/rag/domain/localeDedupe.js';
import { MockProvider } from '../../../src/rag/providers/mock.js';
import { INTENT_RULES } from '../../../src/rag/mock/intents.js';
import type { ProviderBuilder } from '../../../src/rag/providers/factory.js';
import type { AiProvider, ChatRequest, ChatResult } from '../../../src/rag/providers/types.js';
import { forgetKnownIndexes } from '../../../src/rag/retrieval/indexes.js';
import type { ChatBody, RagService } from '../../../src/rag/service.js';
import { registerDomainTools } from '../../../src/rag/tools/domain/index.js';
import { announceSkip, testDatabaseUrl } from '../../helpers/infra.js';
import { createRag, ragDatabase, type RagDatabase } from '../support/harness.js';
import { ALL_PERMISSIONS, DomainFakeWeb } from './support.js';

const database = await ragDatabase();
const skip = database ? '' : announceSkip('Postgres (RAG golden set)', testDatabaseUrl());
const SEED_DIR = resolve(import.meta.dirname, '..', '..', '..', '..', '..', 'docs', 'seed-documents');
const FULL_NUMBERS = ['000483924417', '021000021000483924417', 'ES7921000813610123456789', '40702810938000012345'];

interface SeedDoc {
  id: number;
  family: string;
  title: string;
  department: string | null;
  roles: string[];
  tags: string[];
  version: string | null;
  effective_date: string | null;
  body: string;
}

function frontMatter(raw: string): { meta: Record<string, string>; body: string } {
  const match = /^---\r?\n([\s\S]*?)\r?\n---\r?\n([\s\S]*)$/.exec(raw);
  if (!match) return { meta: {}, body: raw };
  const meta: Record<string, string> = {};
  for (const line of (match[1] ?? '').split(/\r?\n/)) {
    const index = line.indexOf(':');
    if (index > 0) meta[line.slice(0, index).trim()] = line.slice(index + 1).trim();
  }
  return { meta, body: match[2] ?? '' };
}

function list(value: string | undefined): string[] {
  if (!value) return [];
  try {
    return JSON.parse(value) as string[];
  } catch {
    return [];
  }
}

function seedDocs(): SeedDoc[] {
  return readdirSync(SEED_DIR)
    .filter((file) => file.endsWith('.md'))
    .sort()
    .map((file, index) => {
      const { meta, body } = frontMatter(readFileSync(resolve(SEED_DIR, file), 'utf8'));
      return {
        id: index + 1,
        family: meta.family ?? file,
        title: (meta.title ?? file).replace(/^"|"$/g, ''),
        department: meta.department ?? null,
        roles: list(meta.roles),
        tags: list(meta.tags),
        version: meta.version ? meta.version.replace(/^"|"$/g, '') : null,
        effective_date: meta.effective_date ?? null,
        body,
      };
    });
}

class CapturingExternal implements AiProvider {
  readonly id = 'deepseek';
  readonly prompts: string[] = [];
  private readonly inner = new MockProvider(INTENT_RULES);

  async chat(request: ChatRequest): Promise<ChatResult> {
    this.prompts.push(request.messages.map((message) => message.content).join('\n'));
    return this.inner.chat(request);
  }
}

describe.skipIf(!database)(`RAG golden set (mock provider, hash embeddings)${skip}`, () => {
  let rag: RagService;
  const docs = seedDocs();
  const familyOf = new Map(docs.map((doc) => [doc.id, doc.family]));
  const external = new CapturingExternal();
  const mock = new MockProvider(INTENT_RULES);

  function ask(message: string, locale: 'ru' | 'en'): ChatBody {
    return { workspace_id: 100, user: { id: 2, role: 'bookkeeper', locale, permissions: ALL_PERMISSIONS }, channel: 'web', conversation_id: null, message };
  }

  beforeAll(async () => {
    const web = new DomainFakeWeb();
    const builder: ProviderBuilder = { mock, build: async (id) => (id === 'mock' ? mock : external) };
    ({ rag } = createRag(database!, {
      web,
      providerBuilder: builder,
      toolRegistrars: [registerDomainTools],
      outputGuards: [createOutputGuard({ publicWebUrl: 'http://app.test' })],
      postFusion: localeDedupe,
    }));
    await rag.migrate();
    for (const doc of docs) {
      web.addFile(doc.id, doc.body, 'text/markdown', `${doc.family}.md`);
      await rag.ingest(doc.id, {
        workspace_id: 100,
        title: doc.title,
        mime: 'text/markdown',
        metadata: { department: doc.department, roles: doc.roles, tags: doc.tags, version: doc.version, effective_date: doc.effective_date },
      });
    }
  }, 120_000);

  afterAll(async () => {
    forgetKnownIndexes();
    await (database as RagDatabase).drop();
  });

  const rows: Array<{ locale: 'ru' | 'en'; question: string; tools: string[]; family: string | null; contains: string[] }> = [
    { locale: 'ru', question: 'Нужен ли второй согласующий для суммы $4 000?', tools: ['evaluate_approval_policy'], family: 'payment-approval-policy', contains: ['2', '4\u00A0000,00\u00A0$'] },
    { locale: 'en', question: 'Do we need a second approver for $4,000?', tools: ['evaluate_approval_policy'], family: 'payment-approval-policy', contains: ['2 approvers'] },
    { locale: 'ru', question: 'Какой официальный номер поставщика Blue Ridge Packaging указан в договоре?', tools: ['find_vendor', 'get_vendor_official_contacts'], family: 'supply-agreement-blue-ridge', contains: ['+1 828 555 0142', '§9.2'] },
    { locale: 'en', question: "What is Blue Ridge Packaging's official phone number in the contract?", tools: ['find_vendor', 'get_vendor_official_contacts'], family: 'supply-agreement-blue-ridge', contains: ['+1 828 555 0142', '§9.2'] },
    { locale: 'ru', question: 'Какие реквизиты были у Northstar Equipment Leasing раньше?', tools: ['find_vendor', 'get_vendor_bank_history'], family: 'vendor-master-register', contains: ['5512', '8830'] },
    { locale: 'en', question: 'What bank details did Northstar Equipment Leasing have before?', tools: ['find_vendor', 'get_vendor_bank_history'], family: 'vendor-master-register', contains: ['5512', '8830'] },
    { locale: 'ru', question: 'Какая процедура для нового банковского счёта поставщика?', tools: ['search_documents'], family: 'bank-details-change-procedure', contains: ['Стоп', 'Позвони', 'Подтверди'] },
    { locale: 'en', question: 'What is the procedure for a new vendor bank account?', tools: ['search_documents'], family: 'bank-details-change-procedure', contains: ['Stop', 'Call', 'Confirm'] },
  ];

  it.each(rows)('$locale: $question', async (row) => {
    await rag.settings.update({ provider: 'mock', model: 'mock-extractive-1' });
    const output = await rag.chat(ask(row.question, row.locale));
    expect(output.refused, output.answer_markdown).toBe(false);
    for (const tool of row.tools) expect(output.tools_used).toContain(tool);
    if (row.family) expect(output.citations.map((citation) => familyOf.get(citation.document_id)), output.answer_markdown).toContain(row.family);
    for (const text of row.contains) expect(output.answer_markdown).toContain(text);
    if (row.locale === 'ru') expect(output.answer_markdown).toMatch(/[а-яё]/i);
    for (const number of FULL_NUMBERS) {
      expect(output.answer_markdown).not.toContain(number);
      for (const citation of output.citations) expect(citation.snippet).not.toContain(number);
    }
  });

  it('refuses out-of-scope questions', async () => {
    const output = await rag.chat(ask('Какая погода в Москве?', 'ru'));
    expect(output.refused).toBe(true);
  });

  it('does not surface a phone number injected through an e-mail subject', async () => {
    const output = await rag.chat(ask('What is the status of SP-00042?', 'en'));
    expect(output.tools_used).toContain('get_payment_request');
    expect(output.answer_markdown).not.toMatch(/646\D*555\D*0199/);
  });

  it('keeps E.164 numbers away from an external model but restores the official one', async () => {
    await rag.settings.update({ provider: 'deepseek', model: 'deepseek-chat', fallback_model: null });
    external.prompts.length = 0;
    const output = await rag.chat({ ...ask("What is Blue Ridge Packaging's official phone number in the contract?", 'en'), ai_external_allowed: true });
    await rag.settings.update({ provider: 'mock', model: 'mock-extractive-1' });
    expect(external.prompts.length).toBeGreaterThan(0);
    const toolPrompts = external.prompts.join('\n').split('<documents>')[0] ?? '';
    expect(external.prompts.some((prompt) => prompt.includes('[[PHONE_1]]'))).toBe(true);
    expect(toolPrompts).not.toMatch(/\+18285550142/);
    expect(output.answer_markdown).toContain('+1 828 555 0142');
  });
});
