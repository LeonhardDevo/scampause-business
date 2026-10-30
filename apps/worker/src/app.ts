import { setTimeout as sleep } from 'node:timers/promises';
import type { Worker } from 'bullmq';
import type { FastifyInstance } from 'fastify';
import type { Redis } from 'ioredis';
import type pg from 'pg';
import { AccountResolver } from './bot/accounts.js';
import { createBot, type BotHandle } from './bot/createBot.js';
import { defaultModules } from './bot/modules/index.js';
import { createRegistry } from './bot/registry.js';
import { TelegramRuntime } from './bot/runtime.js';
import { RedisActionStore, RedisCache, RedisRateLimiter } from './bot/stores.js';
import type { BotServices } from './bot/types.js';
import { WebClient } from './clients/web.js';
import type { AppConfig } from './config.js';
import { migrate } from './db/migrate.js';
import { createPool } from './db/pool.js';
import { PgChatPreferences } from './db/telegramChats.js';
import { PgWebhookEventStore } from './db/webhookEvents.js';
import { LocalAiApi } from './rag/localApi.js';
import { RagService } from './rag/service.js';
import { buildServer } from './http/server.js';
import { Translator } from './i18n/index.js';
import { createHandlers, type JobDeps, type TelegramSender } from './jobs/index.js';
import { errorFields, type Logger } from './logger.js';
import { SmtpMailer } from './mail/mailer.js';
import { createDomainServices } from './domain/runtime.js';
import { createOutputGuard } from './rag/domain/outputGuard.js';
import { localeDedupe } from './rag/domain/localeDedupe.js';
import { registerDomainTools } from './rag/tools/domain/index.js';
import { Metrics } from './metrics.js';
import { OutboxRelay } from './outbox/relay.js';
import { QueueRegistry } from './queues/enqueuer.js';
import { QUEUES } from './queues/names.js';
import { scheduleBilling, schedulePrivacyPurge } from './queues/scheduler.js';
import { createRedis, redisOptionsFromUrl } from './queues/redis.js';
import { closeWorkers, startWorkers } from './queues/workers.js';

const NOTIFICATION_ACTION_TTL_S = 10 * 24 * 3600;

export interface WorkerApp {
  readonly config: AppConfig;
  readonly server: FastifyInstance;
  readonly bot: BotHandle;
  readonly metrics: Metrics;
  start(): Promise<void>;
  stop(): Promise<void>;
}

export async function createApp(config: AppConfig, logger: Logger): Promise<WorkerApp> {
  const metrics = new Metrics();
  const pool: pg.Pool = createPool(config.database.url, config.database.poolMax, logger);
  const redis: Redis = createRedis(config.redis.url, `${config.product.slug}-worker`);
  redis.on('error', (error) => logger.warn(errorFields(error), 'redis connection error'));
  const connection = redisOptionsFromUrl(config.redis.url);
  const queues = new QueueRegistry(connection, config.redis.queuePrefix);
  metrics.setQueueDepthSource(async () =>
    Promise.all(
      [...queues.queues.values()].map(async (queue) => ({
        queue: queue.name,
        counts: await queue.getJobCounts('waiting', 'active', 'delayed', 'failed', 'completed', 'prioritized', 'paused'),
      })),
    ),
  );

  const web = new WebClient({ service: 'web', baseUrl: config.internal.webUrl, secret: config.internal.keys.web, timeoutMs: config.timeouts.webMs });
  const rag = new RagService({
    config: config.rag,
    productName: config.product.name,
    publicWebUrl: config.internal.webPublicUrl,
    webUrl: config.internal.webUrl,
    webAiKey: config.internal.keys.webAi,
    sharedSecret: config.internal.secret,
    isProduction: config.isProduction,
    webTimeoutMs: config.timeouts.webMs,
    pool,
    logger: logger.child({ component: 'rag' }),
    metricsRegistry: metrics.registry,
    toolRegistrars: [registerDomainTools],
    outputGuards: [createOutputGuard({ publicWebUrl: config.internal.webPublicUrl, logger: logger.child({ component: 'rag-guard' }) })],
    postFusion: localeDedupe,
  });
  const ai = new LocalAiApi(rag);

  const translator = new Translator(config.product.name);
  const registry = createRegistry(defaultModules(), translator);
  const cache = new RedisCache(redis, config.redis.keyPrefix);
  const actions = new RedisActionStore(redis, config.redis.keyPrefix);
  const chatPreferences = new PgChatPreferences(pool);
  const services: BotServices = {
    settings: {
      productName: config.product.name,
      botUsername: config.telegram.botUsername,
      webPublicUrl: config.internal.webPublicUrl,
      rateLimit: config.telegram.rateLimit,
      rateWindowS: config.telegram.rateWindowS,
      actionTtlS: config.telegram.actionTtlS,
      logAiQueries: config.logAiQueries,
    },
    translator,
    web,
    ai,
    actions,
    rateLimiter: new RedisRateLimiter(redis, config.redis.keyPrefix),
    preferences: chatPreferences,
    accounts: new AccountResolver(web, cache, config.telegram.accountCacheTtlS),
    logger: logger.child({ component: 'bot' }),
    metrics,
    registry,
  };
  const domain = createDomainServices({ config, logger, redis, connection, queues, metrics, rag });
  services.scampause = domain.api;
  const bot = createBot(services, {
    token: config.telegram.token,
    apiRoot: config.telegram.apiRoot,
    botUsername: config.telegram.botUsername,
    productName: config.product.name,
  });

  const webhookEvents = new PgWebhookEventStore(pool);
  const mailer = new SmtpMailer(config.smtp);
  const telegram: TelegramSender = {
    sendMessage: (chatId, html, replyMarkup, options) =>
      bot.bot.api.sendMessage(chatId, html, {
        parse_mode: 'HTML',
        link_preview_options: { is_disabled: true },
        ...(replyMarkup ? { reply_markup: replyMarkup } : {}),
        ...(options?.replyToMessageId ? { reply_parameters: { message_id: options.replyToMessageId, allow_sending_without_reply: true } } : {}),
      }),
    editMessageText: async (chatId, messageId, html, replyMarkup) => {
      await bot.bot.api.editMessageText(chatId, messageId, html, {
        parse_mode: 'HTML',
        link_preview_options: { is_disabled: true },
        ...(replyMarkup ? { reply_markup: replyMarkup } : {}),
      });
    },
  };
  const jobDeps: JobDeps = {
    web,
    ai,
    mailer,
    telegram,
    sentMarkers: cache,
    webhookEvents,
    telegramChats: chatPreferences,
    bot,
    registry,
    translator,
    actions,
    settings: { productSlug: config.product.slug, mailFrom: config.smtp.from, notificationActionTtlS: NOTIFICATION_ACTION_TTL_S },
    domain: domain.jobs,
  };
  const handlers = createHandlers(jobDeps);

  const relay = new OutboxRelay({
    pool,
    enqueuer: queues,
    logger: logger.child({ component: 'outbox' }),
    metrics,
    table: config.outbox.table,
    channel: config.outbox.channel,
    pollMs: config.outbox.pollMs,
    batchSize: config.outbox.batchSize,
    listenConnectionString: config.database.url,
  });
  const runtime = new TelegramRuntime(bot, config.telegram, queues, registry, translator, logger.child({ component: 'telegram' }));

  const server = await buildServer({
    logger,
    metrics,
    enqueuer: queues,
    webhookEvents,
    product: config.product,
    stripeWebhookSecret: config.stripe.webhookSecret,
    yookassaWebhook: { allowedIps: config.webhooks.yookassaAllowedIps, trustedProxyHops: config.webhooks.trustedProxyHops },
    telegramWebhook: { enabled: config.telegram.mode === 'webhook', secret: config.telegram.webhookSecret },
    devBot: config.devRoutes ? bot : null,
    rag: { service: rag, peerSecrets: { web: config.internal.keys.webAi, worker: config.internal.keys.ai } },
    dashboard: config.dashboard ? { queues: [...queues.queues.values()], ...config.dashboard } : null,
    readiness: {
      postgres: async () => {
        await pool.query('SELECT 1');
      },
      redis: async () => {
        await redis.ping();
      },
    },
  });
  if (!config.dashboard) logger.warn('QUEUE_DASHBOARD_USER/PASSWORD not set; /admin/queues is disabled');

  let workers: Worker[] = [];

  return {
    config,
    server,
    bot,
    metrics,
    async start() {
      await retry(() => migrate(pool, logger), logger, 'postgres migrations');
      await retry(() => rag.migrate(), logger, 'rag migrations');
      rag.warmUp();
      if (config.workersEnabled) {
        await retry(() => domain.runtime.startSchedulers(), logger, 'domain schedulers');
        workers = startWorkers(handlers, { connection, prefix: config.redis.queuePrefix, logger, metrics });
        await schedulePrivacyPurge(queues.get(QUEUES.privacy), config.privacyPurgeCron, logger).catch((error: unknown) =>
          logger.error(errorFields(error), 'privacy purge scheduler registration failed'),
        );
        await scheduleBilling(
          queues.get(QUEUES.billing),
          { renewals: config.billingRenewalsCron },
          logger,
        ).catch((error: unknown) => logger.error(errorFields(error), 'billing scheduler registration failed'));
      }
      if (config.outbox.enabled) await relay.start();
      if (config.telegram.mode === 'disabled') await bot.init(logger);
      else await runtime.start();
      await server.listen({ host: config.host, port: config.port });
      logger.info(
        {
          port: config.port,
          telegram: runtime.mode,
          devRoutes: config.devRoutes,
          dashboard: Boolean(config.dashboard),
          product: config.product.slug,
        },
        'worker ready',
      );
    },
    async stop() {
      await server.close().catch(() => undefined);
      await rag.flush().catch(() => undefined);
      await runtime.stop();
      await relay.stop();
      await closeWorkers(workers);
      await domain.runtime.close().catch(() => undefined);
      await queues.close();
      mailer.close();
      await redis.quit().catch(() => undefined);
      await pool.end().catch(() => undefined);
    },
  };
}

async function retry<T>(fn: () => Promise<T>, logger: Logger, what: string, attempts = 15, delayMs = 2000): Promise<T> {
  for (let attempt = 1; ; attempt++) {
    try {
      return await fn();
    } catch (error) {
      if (attempt >= attempts) throw error;
      logger.warn({ attempt, ...errorFields(error) }, `${what} failed; retrying`);
      await sleep(delayMs);
    }
  }
}
