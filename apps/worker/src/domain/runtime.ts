import type { ConnectionOptions } from 'bullmq';
import type { Redis } from 'ioredis';
import type { AppConfig } from '../config.js';
import type { Logger } from '../logger.js';
import type { Metrics } from '../metrics.js';
import type { QueueRegistry } from '../queues/enqueuer.js';
import { TesseractOcr } from '../rag/ingest/ocr.js';
import { RagExtractAssist } from '../rag/domain/extractAssist.js';
import type { RagService } from '../rag/service.js';
import { DomainWebClient } from './client.js';
import { loadDomainConfig, type DomainConfig } from './config.js';
import { FakeDomainIntelDriver, LiveDomainIntelDriver } from './intel/drivers.js';
import type { DomainJobServices } from './jobs/deps.js';
import { registerDomainFlowsAndSchedulers, type DomainRuntime } from './jobs/flows.js';
import { RedisDomainLocks } from './locks.js';
import { DomainMetrics } from './metrics.js';
import { FileParser } from './parse/parseFile.js';
import { ZxingQrDecoder } from './parse/qrDecoder.js';

export interface DomainServicesInput {
  config: AppConfig;
  logger: Logger;
  redis: Redis;
  connection: ConnectionOptions;
  queues: QueueRegistry;
  metrics: Metrics;
  rag: RagService;
  env?: Record<string, string | undefined>;
}

export interface DomainServices {
  config: DomainConfig;
  api: DomainWebClient;
  jobs: DomainJobServices;
  runtime: DomainRuntime;
}

export function createDomainServices(input: DomainServicesInput): DomainServices {
  const config = loadDomainConfig(input.env ?? process.env, input.config.internal.webPublicUrl);
  const logger = input.logger.child({ component: 'domain' });
  const api = new DomainWebClient({ service: 'web', baseUrl: input.config.internal.webUrl, secret: input.config.internal.keys.web, timeoutMs: input.config.timeouts.webMs });
  const runtime = registerDomainFlowsAndSchedulers({ connection: input.connection, prefix: input.config.redis.queuePrefix, queues: input.queues.queues });
  const ocr = new TesseractOcr({ command: config.tesseractPath, languages: config.ocrLanguages, timeoutMs: config.pageTimeoutMs, tessdataPrefix: config.tessdataPrefix, logger });
  const now = (): Date => new Date();
  const jobs: DomainJobServices = {
    api,
    flows: runtime.flows,
    enqueuer: input.queues,
    locks: new RedisDomainLocks(input.redis, input.config.redis.keyPrefix),
    parser: new FileParser({ ocr, qr: new ZxingQrDecoder(), logger }),
    intel: { live: new LiveDomainIntelDriver({ rdapBaseUrl: config.rdapBaseUrl, now }), fake: new FakeDomainIntelDriver(now) },
    config,
    metrics: new DomainMetrics(input.metrics.registry),
    assist: new RagExtractAssist(input.rag.manager, logger),
    now,
  };
  logger.info({ intelDriver: config.intelDriver, appEnv: config.appEnv }, 'domain services configured');
  return { config, api, jobs, runtime };
}
