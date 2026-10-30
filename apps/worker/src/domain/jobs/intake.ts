import { z } from 'zod';
import { errorMessage, parseOutboxJob, permanent, type JobContext, type JobHandler } from '../../jobs/types.js';
import type { IntakeFile, IntakeInfo } from '../client.js';
import { looksLikeEml, parseEml, EmlParseError } from '../extract/eml.js';
import { extractFields, type ExtractOutcome } from '../extract/extractFields.js';
import {
  EXTRACTOR_VERSION,
  type ExtractionDocument,
  type ExtractionFileSummary,
  type ExtractionResult,
  type SenderFields,
} from '../extract/types.js';
import type { DomainFacts } from '../intel/types.js';
import type { ParsedFile } from '../parse/parseFile.js';
import type { DomainJobServices } from './deps.js';
import { domainStep, flowDataSchema, secondsSince } from './support.js';

export const INTAKE_JOBS = {
  received: 'intake.received',
  domainCheck: 'intake.domain-check',
  bankCompare: 'intake.bank-compare',
  score: 'intake.score',
} as const;

export const PIPELINE_STAGES = { extract: 'extract', domain: 'domain', bank: 'bank', score: 'score' } as const;

export const intakePayloadSchema = z.object({
  payment_request_id: z.number().int().positive(),
  workspace_id: z.number().int().positive(),
  revision: z.number().int().nonnegative(),
  file_ids: z.array(z.number().int().positive()).default([]),
  locale: z.enum(['ru', 'en']).catch('en'),
  country: z.string().default('US'),
  skip_extract: z.boolean().default(false),
});
export type IntakePayload = z.infer<typeof intakePayloadSchema>;

const LOCK_TTL_MS = 300_000;
const SEMAPHORE_TTL_MS = 300_000;
const BUSY_DELAY_MS = 3000;
const PARSEABLE_ROLES = new Set(['original', 'attachment']);

interface Collected {
  documents: ExtractionDocument[];
  qr: Array<{ page: number | null; payload: string }>;
  sender: SenderFields | null;
  files: ExtractionFileSummary[];
  warnings: string[];
  errors: string[];
}

function addWarning(list: string[], code: string): void {
  if (!list.includes(code)) list.push(code);
}

function errorCodeOf(error: unknown): string {
  const code = (error as { code?: unknown } | null)?.code;
  if (typeof code === 'string' && /^[a-z_]+$/.test(code)) return code;
  if (error instanceof EmlParseError) return 'eml_invalid';
  return 'parse_failed';
}

async function parseInto(
  services: DomainJobServices,
  input: { workspaceId: number; paymentRequestId: number; revision: number; fileId: number; mime: string; bytes: Buffer },
  collected: Collected,
  ctx: JobContext,
): Promise<void> {
  let parsed: ParsedFile;
  try {
    parsed = await services.parser.parse({
      workspace_id: input.workspaceId,
      file_id: input.fileId,
      mime: input.mime,
      bytes: input.bytes,
      ocr_langs: services.config.ocrLanguages,
      detect_qr: true,
      max_pages: 50,
      render_previews: 10,
    });
  } catch (error) {
    const code = errorCodeOf(error);
    ctx.logger.warn({ fileId: input.fileId, code, error: errorMessage(error) }, 'file could not be parsed');
    addWarning(collected.warnings, code.toUpperCase());
    collected.errors.push(code);
    collected.files.push({ file_id: input.fileId, pages: 0, ocr_used: false });
    return;
  }
  collected.documents.push({ file_id: input.fileId, role: 'attachment', pages: parsed.pages });
  for (const preview of parsed.previews) {
    await services.api
      .uploadPreview(input.paymentRequestId, { workspace_id: input.workspaceId, revision: input.revision, file_id: input.fileId, page: preview.page }, preview.png, ctx.requestId)
      .catch((error: unknown) => ctx.logger.warn({ fileId: input.fileId, page: preview.page, error: errorMessage(error) }, 'preview upload failed'));
  }
  collected.qr.push(...parsed.qr_codes);
  collected.files.push({ file_id: input.fileId, pages: parsed.pages.length, ocr_used: parsed.ocr_used });
  if (parsed.ocr_used && parsed.missing_languages.length > 0) addWarning(collected.warnings, 'OCR_LANGUAGE_MISSING');
  if (parsed.page_count > parsed.pages.length) addWarning(collected.warnings, 'PAGES_TRUNCATED');
}

async function collect(services: DomainJobServices, payload: IntakePayload, intake: IntakeInfo, ctx: JobContext): Promise<Collected> {
  const collected: Collected = { documents: [], qr: [], sender: null, files: [], warnings: [], errors: [] };
  const wanted = new Set(payload.file_ids);
  const files = intake.files.filter(
    (file: IntakeFile) => PARSEABLE_ROLES.has(file.role) && !file.parent_file_id && (wanted.size === 0 || wanted.has(file.id)),
  );
  for (const file of files) {
    const download = await services.api.downloadFile(file.id, payload.workspace_id, ctx.requestId);
    if (looksLikeEml(download.bytes, file.original_name, file.mime)) {
      let eml;
      try {
        eml = await parseEml(download.bytes);
      } catch (error) {
        addWarning(collected.warnings, 'EML_INVALID');
        collected.errors.push(errorCodeOf(error));
        collected.files.push({ file_id: file.id, pages: 0, ocr_used: false });
        continue;
      }
      collected.sender ??= eml.sender;
      for (const warning of eml.warnings) addWarning(collected.warnings, warning);
      if (eml.skipped.length > 0) addWarning(collected.warnings, 'EML_ATTACHMENTS_SKIPPED');
      const bodies = [eml.bodyText, ...eml.nestedBodies].filter((text) => text.trim() !== '');
      collected.documents.push({ file_id: file.id, role: 'body', pages: bodies.map((text, index) => ({ page: index + 1, text, ocr_used: false })) });
      collected.files.push({ file_id: file.id, pages: bodies.length, ocr_used: false });
      for (const attachment of eml.attachments) {
        const uploaded = await services.api.uploadAttachment(
          payload.payment_request_id,
          { workspace_id: payload.workspace_id, revision: payload.revision, parent_file_id: file.id },
          { filename: attachment.filename, contentType: attachment.contentType, bytes: attachment.content },
          ctx.requestId,
        );
        await parseInto(services, { workspaceId: payload.workspace_id, paymentRequestId: payload.payment_request_id, revision: payload.revision, fileId: uploaded.file_id, mime: attachment.contentType, bytes: attachment.content }, collected, ctx);
      }
      continue;
    }
    await parseInto(services, { workspaceId: payload.workspace_id, paymentRequestId: payload.payment_request_id, revision: payload.revision, fileId: file.id, mime: file.mime, bytes: download.bytes }, collected, ctx);
  }
  return collected;
}

async function assistIfNeeded(services: DomainJobServices, intake: IntakeInfo, payload: IntakePayload, outcome: ExtractOutcome, ctx: JobContext): Promise<boolean> {
  if (!services.assist || !intake.ai_extract_assist) return false;
  const missing: Array<'vendor_name' | 'amount'> = [];
  if (!outcome.fields.vendor_name) missing.push('vendor_name');
  if (!outcome.fields.amount_minor) missing.push('amount');
  if (missing.length === 0 || outcome.text.trim() === '') return false;
  try {
    const fields = await services.assist.assist({
      workspace_id: payload.workspace_id,
      ai_external_allowed: intake.ai_external_allowed,
      locale: payload.locale,
      text: outcome.text,
      missing,
      currency: intake.currency ?? 'USD',
    });
    let changed = false;
    if (!outcome.fields.vendor_name && fields.vendor_name) {
      outcome.fields.vendor_name = fields.vendor_name;
      changed = true;
    }
    if (!outcome.fields.amount_minor && fields.amount_minor) {
      outcome.fields.amount_minor = fields.amount_minor;
      if (fields.currency) outcome.fields.currency = fields.currency;
      changed = true;
    }
    if (!outcome.fields.invoice_number && fields.invoice_number) outcome.fields.invoice_number = fields.invoice_number;
    if (!outcome.fields.due_date && fields.due_date) outcome.fields.due_date = fields.due_date;
    if (changed) addWarning(outcome.warnings, 'LLM_ASSISTED_FIELDS');
    return changed;
  } catch (error) {
    ctx.logger.warn({ error: errorMessage(error) }, 'extraction assist failed; continuing with rule-based fields');
    return false;
  }
}

export function buildExtractionResult(
  payload: IntakePayload,
  collected: Collected,
  outcome: ExtractOutcome,
  durationMs: number,
  llmAssisted: boolean,
): ExtractionResult {
  const warnings = [...collected.warnings];
  for (const warning of outcome.warnings) addWarning(warnings, warning);
  const failedOnlyFiles = collected.errors.length > 0 && outcome.status === 'failed';
  return {
    workspace_id: payload.workspace_id,
    payment_request_id: payload.payment_request_id,
    revision: payload.revision,
    status: outcome.status,
    extractor_version: EXTRACTOR_VERSION,
    ocr_used: outcome.ocrUsed,
    pages: outcome.pages,
    text: outcome.text,
    fields: outcome.fields,
    warnings,
    error_code: failedOnlyFiles ? (collected.errors[0] ?? 'parse_failed') : outcome.status === 'failed' ? 'no_text' : null,
    duration_ms: Math.round(durationMs),
    llm_assisted: llmAssisted,
    files: collected.files,
  };
}

export function createIntakeExtractHandler(services: DomainJobServices): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const job = parseOutboxJob(data, intakePayloadSchema);
    const payload = job.payload;
    const { api, locks, config, metrics } = services;
    const pr = payload.payment_request_id;
    const ws = payload.workspace_id;
    const semaphoreKey = `sp:intake:sem:${ws}`;
    const slot = await locks.acquireSlot(semaphoreKey, config.intakeWorkspaceConcurrency, SEMAPHORE_TTL_MS);
    if (!slot) {
      ctx.logger.info({ workspaceId: ws }, 'workspace intake concurrency reached; deferring');
      if (ctx.defer) await ctx.defer(BUSY_DELAY_MS + Math.floor(Math.random() * 1000));
      throw Object.assign(new Error('workspace intake is busy'), { retryable: true });
    }
    const lockKey = `extract:${pr}:${payload.revision}`;
    const lock = await locks.acquire(lockKey, LOCK_TTL_MS);
    if (!lock) {
      await locks.releaseSlot(semaphoreKey, slot);
      ctx.logger.info({ paymentRequestId: pr, revision: payload.revision }, 'extraction already running for this revision');
      return { skipped: 'in_progress' };
    }
    const started = Date.now();
    try {
      return await domainStep(
        ctx,
        metrics,
        'intake.extract',
        async () => {
          const stage = { workspace_id: ws, revision: payload.revision, stage: PIPELINE_STAGES.extract };
          if (payload.skip_extract) {
            await api.pipeline(pr, { ...stage, state: 'skipped' }, ctx.requestId);
            const intake = await api.intake(pr, ws, ctx.requestId);
            await services.flows.startIntakeFlow({ paymentRequestId: pr, workspaceId: ws, revision: payload.revision, domains: intake.domains });
            return { skipped_extract: true, flow: true };
          }
          await api.pipeline(pr, { ...stage, state: 'running' }, ctx.requestId);
          const intake = await api.intake(pr, ws, ctx.requestId);
          if (intake.revision !== payload.revision) return { noop: 'stale_revision' };
          const collected = await collect(services, payload, intake, ctx);
          const outcome = extractFields(
            {
              documents: collected.documents,
              qr_payloads: collected.qr,
              sender: collected.sender,
              country: intake.country || payload.country,
              locale: payload.locale,
              default_currency: intake.currency ?? null,
              vendor_currency: intake.vendor_currency ?? null,
            },
            config.extractMaxTextChars,
          );
          const llmAssisted = await assistIfNeeded(services, intake, payload, outcome, ctx);
          const result = buildExtractionResult(payload, collected, outcome, Date.now() - started, llmAssisted);
          const response = await api.submitExtraction(pr, result, ctx.requestId);
          metrics.pipelineDuration.observe({ stage: PIPELINE_STAGES.extract }, secondsSince(started));
          ctx.logger.info({ paymentRequestId: pr, status: result.status, warnings: result.warnings, proceed: response.proceed }, 'extraction submitted');
          if (response.proceed) {
            const domains = response.domains.length > 0 ? response.domains : outcome.fields.domains;
            await services.flows.startIntakeFlow({ paymentRequestId: pr, workspaceId: ws, revision: payload.revision, domains });
          }
          return { status: result.status, proceed: response.proceed };
        },
        async (error) => {
          await api.pipeline(pr, { workspace_id: ws, revision: payload.revision, stage: PIPELINE_STAGES.extract, state: 'failed', error: errorMessage(error) }, ctx.requestId);
        },
      );
    } finally {
      await locks.release(lockKey, lock).catch(() => undefined);
      await locks.releaseSlot(semaphoreKey, slot).catch(() => undefined);
    }
  };
}

function parseFlowData(data: unknown): z.infer<typeof flowDataSchema> {
  const parsed = flowDataSchema.safeParse(data);
  if (!parsed.success) throw permanent(`invalid intake flow data: ${z.prettifyError(parsed.error)}`);
  return parsed.data;
}

function factsFailure(domain: string, now: Date, source: 'live' | 'fake'): DomainFacts {
  return {
    domain,
    unicode_form: null,
    has_mx: null,
    has_spf: null,
    dmarc_policy: null,
    registered_at: null,
    registrar: null,
    rdap_status: 'error',
    source,
    checked_at: now.toISOString(),
  };
}

export function createDomainCheckHandler(services: DomainJobServices): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const flow = parseFlowData(data);
    const { api, metrics } = services;
    const stage = { workspace_id: flow.workspace_id, revision: flow.revision, stage: PIPELINE_STAGES.domain };
    const started = Date.now();
    return domainStep(
      ctx,
      metrics,
      INTAKE_JOBS.domainCheck,
      async () => {
        const domains = [...new Set(flow.domains.map((domain) => domain.toLowerCase()))];
        if (domains.length === 0) {
          await api.pipeline(flow.payment_request_id, { ...stage, state: 'skipped' }, ctx.requestId);
          return { mode: 'none', looked_up: 0 };
        }
        await api.pipeline(flow.payment_request_id, { ...stage, state: 'running' }, ctx.requestId);
        const intel = await api.domainIntel(flow.workspace_id, domains, ctx.requestId);
        if (intel.mode === 'cache_only') {
          await api.pipeline(flow.payment_request_id, { ...stage, state: 'done' }, ctx.requestId);
          return { mode: intel.mode, looked_up: 0 };
        }
        const useFake = services.config.intelDriver === 'fake' && services.config.appEnv !== 'production';
        const driver = useFake ? services.intel.fake : services.intel.live;
        const known = new Set(intel.facts.map((fact) => fact.domain.toLowerCase()));
        const stale = new Set(intel.stale.map((domain) => domain.toLowerCase()));
        const todo = domains.filter((domain) => !known.has(domain) || stale.has(domain));
        const facts = await Promise.all(todo.map((domain) => driver.lookup(domain).catch(() => factsFailure(domain, services.now(), driver.source))));
        if (facts.length > 0) await api.storeDomainIntel(flow.workspace_id, facts, ctx.requestId);
        await api.pipeline(flow.payment_request_id, { ...stage, state: 'done' }, ctx.requestId);
        metrics.pipelineDuration.observe({ stage: PIPELINE_STAGES.domain }, secondsSince(started));
        return { mode: intel.mode, looked_up: facts.length };
      },
      async (error) => {
        await api.pipeline(flow.payment_request_id, { ...stage, state: 'failed', error: errorMessage(error) }, ctx.requestId);
      },
    );
  };
}

export function createBankCompareHandler(services: DomainJobServices): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const flow = parseFlowData(data);
    const started = Date.now();
    return domainStep(
      ctx,
      services.metrics,
      INTAKE_JOBS.bankCompare,
      async () => {
        const response = await services.api.bankComparison(flow.payment_request_id, flow.workspace_id, flow.revision, ctx.requestId);
        services.metrics.pipelineDuration.observe({ stage: PIPELINE_STAGES.bank }, secondsSince(started));
        return { outcome: response.outcome };
      },
      async (error) => {
        await services.api.pipeline(
          flow.payment_request_id,
          { workspace_id: flow.workspace_id, revision: flow.revision, stage: PIPELINE_STAGES.bank, state: 'failed', error: errorMessage(error) },
          ctx.requestId,
        );
      },
    );
  };
}

export function createScoreHandler(services: DomainJobServices): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const flow = parseFlowData(data);
    const started = Date.now();
    return domainStep(
      ctx,
      services.metrics,
      INTAKE_JOBS.score,
      async () => {
        const response = await services.api.assess(flow.payment_request_id, flow.workspace_id, flow.revision, ctx.requestId);
        services.metrics.pipelineDuration.observe({ stage: PIPELINE_STAGES.score }, secondsSince(started));
        return response;
      },
      async (error) => {
        await services.api.pipeline(
          flow.payment_request_id,
          { workspace_id: flow.workspace_id, revision: flow.revision, stage: PIPELINE_STAGES.score, state: 'failed', error: errorMessage(error) },
          ctx.requestId,
        );
      },
    );
  };
}
