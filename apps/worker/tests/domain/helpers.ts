import { vi } from 'vitest';
import { MemoryChatPreferences } from '../../src/db/telegramChats.js';
import { coreModule } from '../../src/bot/modules/core.js';
import { scampauseModule } from '../../src/bot/modules/scampause/index.js';
import { createRegistry } from '../../src/bot/registry.js';
import { MemoryActionStore, MemoryCache } from '../../src/bot/stores.js';
import type { DomainWebApi } from '../../src/domain/client.js';
import { loadDomainConfig } from '../../src/domain/config.js';
import { FakeDomainIntelDriver } from '../../src/domain/intel/drivers.js';
import type { DomainIntelDriver } from '../../src/domain/intel/types.js';
import type { DomainJobServices, IntakeFlowSpec } from '../../src/domain/jobs/deps.js';
import { MemoryDomainLocks } from '../../src/domain/locks.js';
import { DomainMetrics } from '../../src/domain/metrics.js';
import type { ParsedFile, ParseFileInput } from '../../src/domain/parse/parseFile.js';
import { MemoryWebhookEventStore } from '../../src/db/webhookEvents.js';
import { Translator } from '../../src/i18n/index.js';
import type { JobDeps, TelegramSender } from '../../src/jobs/deps.js';
import type { JobContext } from '../../src/jobs/types.js';
import { createSilentLogger } from '../../src/logger.js';
import type { Mailer } from '../../src/mail/mailer.js';
import { MemoryEnqueuer } from '../../src/queues/enqueuer.js';
import { fakeAi, fakeWeb } from '../helpers/fakes.js';

export const OUTBOX_ID = '1f9a4a0e-2a54-4e8e-bb0b-0d3c2f1e9a22';

export function jobCtx(overrides: Partial<JobContext> = {}): JobContext {
  return {
    queue: 'q',
    name: 'n',
    jobId: OUTBOX_ID,
    attempt: 1,
    maxAttempts: 5,
    isFinalAttempt: false,
    requestId: 'req-domain',
    logger: createSilentLogger(),
    ...overrides,
  };
}

export function envelope(topic: string, payload: unknown, workspaceId = 1): Record<string, unknown> {
  return { outbox_id: OUTBOX_ID, topic, workspace_id: workspaceId, payload };
}

export function fakeDomainApi() {
  return {
    intake: vi.fn<DomainWebApi['intake']>(async (id) => ({
      payment_request_id: id,
      revision: 2,
      locale: 'en' as const,
      country: 'US',
      currency: 'USD',
      vendor_currency: null,
      ai_extract_assist: false,
      ai_external_allowed: false,
      domains: [],
      files: [],
    })),
    downloadFile: vi.fn<DomainWebApi['downloadFile']>(async () => ({ bytes: Buffer.from(''), sha256: null, contentType: 'application/octet-stream' })),
    uploadAttachment: vi.fn<DomainWebApi['uploadAttachment']>(async () => ({ file_id: 900 })),
    uploadPreview: vi.fn<DomainWebApi['uploadPreview']>(async () => ({ file_id: 901 })),
    pipeline: vi.fn<DomainWebApi['pipeline']>(async () => undefined),
    submitExtraction: vi.fn<DomainWebApi['submitExtraction']>(async () => ({ proceed: true, domains: ['blueridge-pack.com'] })),
    domainIntel: vi.fn<DomainWebApi['domainIntel']>(async () => ({ mode: 'fake' as const, facts: [], stale: [] })),
    storeDomainIntel: vi.fn<DomainWebApi['storeDomainIntel']>(async (_ws, facts) => ({ stored: facts.length })),
    bankComparison: vi.fn<DomainWebApi['bankComparison']>(async () => ({ outcome: 'changed' })),
    assess: vi.fn<DomainWebApi['assess']>(async () => ({ score: 100, level: 'critical', status: 'review' })),
    getApprovalTelegramMessage: vi.fn<DomainWebApi['getApprovalTelegramMessage']>(async () => null),
    setApprovalTelegramMessage: vi.fn<DomainWebApi['setApprovalTelegramMessage']>(async () => undefined),
    reminders: vi.fn<DomainWebApi['reminders']>(async () => ({ send: false as const, reason: 'decided' })),
    escalate: vi.fn<DomainWebApi['escalate']>(async () => ({ escalated: true })),
    expire: vi.fn<DomainWebApi['expire']>(async () => ({ expired: true })),
    sweepApprovals: vi.fn<DomainWebApi['sweepApprovals']>(async () => ({ expired_route_ids: [] })),
    exportData: vi.fn<DomainWebApi['exportData']>(async () => ({})),
    uploadExport: vi.fn<DomainWebApi['uploadExport']>(async () => undefined),
    exportFailed: vi.fn<DomainWebApi['exportFailed']>(async () => undefined),
    verifyAllChains: vi.fn<DomainWebApi['verifyAllChains']>(async () => ({ workspaces: 2, broken: 0 })),
    telegramCallback: vi.fn<DomainWebApi['telegramCallback']>(async () => ({ answer: { text: '', show_alert: false } })),
    telegramWorkspace: vi.fn<DomainWebApi['telegramWorkspace']>(async () => ({ ok: true as const })),
    telegramPending: vi.fn<DomainWebApi['telegramPending']>(async () => []),
    telegramRequest: vi.fn<DomainWebApi['telegramRequest']>(async () => null),
  } satisfies DomainWebApi;
}

export type FakeDomainApi = ReturnType<typeof fakeDomainApi>;

export function fakeParser(result: Partial<ParsedFile> = {}) {
  return {
    parse: vi.fn(async (input: ParseFileInput): Promise<ParsedFile> => ({
      mime: input.mime,
      pages: [{ page: 1, text: input.bytes.toString('utf8'), ocr_used: false }],
      qr_codes: [],
      previews: [],
      ocr_used: false,
      ocr_languages_used: [],
      missing_languages: [],
      page_count: 1,
      ...result,
    })),
  };
}

export interface DomainTestDeps {
  deps: JobDeps;
  domain: DomainJobServices;
  api: FakeDomainApi;
  flows: Array<IntakeFlowSpec>;
  enqueuer: MemoryEnqueuer;
  telegram: { sendMessage: ReturnType<typeof vi.fn<TelegramSender['sendMessage']>>; editMessageText: ReturnType<typeof vi.fn<NonNullable<TelegramSender['editMessageText']>>> };
  mailer: { send: ReturnType<typeof vi.fn<Mailer['send']>>; verify: ReturnType<typeof vi.fn<Mailer['verify']>>; close: ReturnType<typeof vi.fn<Mailer['close']>> };
  web: ReturnType<typeof fakeWeb>;
  parser: ReturnType<typeof fakeParser>;
  locks: MemoryDomainLocks;
  markers: MemoryCache;
  actions: MemoryActionStore;
  liveIntel: DomainIntelDriver & { lookup: ReturnType<typeof vi.fn<DomainIntelDriver['lookup']>> };
}

export function domainDeps(options: { env?: Record<string, string>; parser?: ReturnType<typeof fakeParser> } = {}): DomainTestDeps {
  const api = fakeDomainApi();
  const flows: IntakeFlowSpec[] = [];
  const enqueuer = new MemoryEnqueuer();
  const parser = options.parser ?? fakeParser();
  const locks = new MemoryDomainLocks();
  const now = (): Date => new Date('2026-10-23T12:00:00.000Z');
  const fake = new FakeDomainIntelDriver(now);
  const liveIntel = { source: 'live' as const, lookup: vi.fn<DomainIntelDriver['lookup']>(async (domain) => ({ ...(await fake.lookup(domain)), source: 'live' as const })) };
  const domain: DomainJobServices = {
    api,
    flows: { startIntakeFlow: async (spec) => void flows.push(spec) },
    enqueuer,
    locks,
    parser,
    intel: { live: liveIntel, fake },
    config: loadDomainConfig({ NODE_ENV: 'test', ...(options.env ?? {}) }, 'https://app.scampause.test'),
    metrics: new DomainMetrics(),
    assist: null,
    now,
  };
  const translator = new Translator('ScamPause Business');
  const telegram = {
    sendMessage: vi.fn<TelegramSender['sendMessage']>(async () => ({ message_id: 555 })),
    editMessageText: vi.fn<NonNullable<TelegramSender['editMessageText']>>(async () => undefined),
  };
  const mailer = { send: vi.fn<Mailer['send']>(async () => ({ messageId: '<smtp-9@mailpit>' })), verify: vi.fn<Mailer['verify']>(async () => true), close: vi.fn<Mailer['close']>() };
  const web = fakeWeb();
  const markers = new MemoryCache();
  const actions = new MemoryActionStore();
  const deps: JobDeps = {
    web,
    ai: fakeAi(),
    telegramChats: new MemoryChatPreferences(),
    mailer,
    telegram,
    sentMarkers: markers,
    webhookEvents: new MemoryWebhookEventStore(),
    bot: { handleUpdate: async () => undefined },
    registry: createRegistry([coreModule, scampauseModule], translator),
    translator,
    actions,
    settings: { productSlug: 'scampause', mailFrom: 'ScamPause <no-reply@scampause.test>', notificationActionTtlS: 864000 },
    domain,
  };
  return { deps, domain, api, flows, enqueuer, telegram, mailer, web, parser, locks, markers, actions, liveIntel };
}
