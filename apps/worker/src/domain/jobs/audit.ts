import { createHash } from 'node:crypto';
import { z } from 'zod';
import type { JobDeps } from '../../jobs/deps.js';
import { errorMessage, parseOutboxJob, permanent, type JobContext, type JobHandler } from '../../jobs/types.js';
import { toCsv, toNdjson } from '../audit/csv.js';
import { evidencePacketSchema, label, renderEvidencePacket, renderJournalPdf } from '../audit/packet.js';
import { journalPageSchema } from '../client.js';
import { verifyChain, verifyEntries, type ChainEntry, type ChainVerification } from '../evidence/canonical.js';
import type { DomainJobServices } from './deps.js';
import { domainStep } from './support.js';

export const AUDIT_JOBS = {
  packet: 'evidence.packet.requested',
  journal: 'audit.journal.requested',
  chainVerify: 'audit.chain-verify',
} as const;

export const MAX_JOURNAL_PAGES = 1000;

export const packetRequestSchema = z.object({
  export_id: z.number().int().positive(),
  payment_request_id: z.number().int().positive(),
  workspace_id: z.number().int().positive(),
  locale: z.enum(['ru', 'en']).catch('en'),
});

export const journalRequestSchema = z.object({
  export_id: z.number().int().positive(),
  workspace_id: z.number().int().positive(),
  from: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
  to: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
  format: z.enum(['pdf', 'csv', 'json']),
  kind: z.enum(['audit_journal', 'prevented_risk']),
  locale: z.enum(['ru', 'en']).catch('en'),
});

function services(deps: JobDeps): DomainJobServices {
  if (!deps.domain) throw permanent('domain services are not configured');
  return deps.domain;
}

function sha256(bytes: Buffer): string {
  return createHash('sha256').update(bytes).digest('hex');
}

function chainEntries(entries: ReadonlyArray<Record<string, unknown>>): ChainEntry[] | null {
  const out: ChainEntry[] = [];
  for (const entry of entries) {
    const { seq, prev_hash: prev, hash, material } = entry;
    if (typeof seq !== 'number' || typeof prev !== 'string' || typeof hash !== 'string' || typeof material !== 'string') return null;
    out.push({ seq, prev_hash: prev, hash, material });
  }
  return out;
}

export function createEvidencePacketHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const domain = services(deps);
    const { payload } = parseOutboxJob(data, packetRequestSchema);
    return domainStep(
      ctx,
      domain.metrics,
      'audit.evidence-packet',
      async () => {
        const raw = await domain.api.exportData(payload.export_id, payload.workspace_id, null, ctx.requestId);
        const parsed = evidencePacketSchema.safeParse(raw);
        if (!parsed.success) throw permanent(`invalid evidence packet data: ${z.prettifyError(parsed.error)}`);
        const packet = parsed.data;
        const chain = verifyEntries(packet.entries);
        if (!chain.ok) ctx.logger.error({ exportId: payload.export_id, firstBrokenSeq: chain.first_broken_seq }, 'evidence chain segment failed verification');
        const pdf = await renderEvidencePacket(packet, chain);
        const digest = sha256(pdf);
        const reference = packet.request.reference.replace(/[^A-Za-z0-9-]/g, '') || String(payload.payment_request_id);
        await domain.api.uploadExport(
          payload.export_id,
          payload.workspace_id,
          { filename: `evidence-${reference}.pdf`, contentType: 'application/pdf', bytes: pdf },
          digest,
          chain.ok,
          ctx.requestId,
        );
        return { bytes: pdf.byteLength, sha256: digest, chain_verified: chain.ok };
      },
      async (error) => {
        await domain.api.exportFailed(payload.export_id, payload.workspace_id, errorMessage(error), ctx.requestId);
      },
    );
  };
}

function journalColumns(entries: ReadonlyArray<Record<string, unknown>>, labels: Record<string, string>, locale: 'ru' | 'en'): { header: string[]; keys: string[] } {
  const preferred = ['seq', 'occurred_at', 'occurred_at_local', 'event', 'event_label', 'actor', 'channel', 'reference', 'payload', 'prev_hash', 'hash'];
  const present = new Set(entries.flatMap((entry) => Object.keys(entry)));
  const keys = [...preferred.filter((key) => present.has(key)), ...[...present].filter((key) => !preferred.includes(key) && key !== 'material').sort()];
  return { keys, header: keys.map((key) => labels[`column.${key}`] ?? labels[key] ?? label({}, locale, key)) };
}

export function createJournalHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const domain = services(deps);
    const { payload } = parseOutboxJob(data, journalRequestSchema);
    return domainStep(
      ctx,
      domain.metrics,
      'audit.journal',
      async () => {
        const entries: Array<Record<string, unknown>> = [];
        let labels: Record<string, string> = {};
        let workspace = '';
        let page: number | null = 1;
        for (let guard = 0; page !== null && guard < MAX_JOURNAL_PAGES; guard++) {
          const raw = await domain.api.exportData(payload.export_id, payload.workspace_id, page, ctx.requestId);
          const parsed = journalPageSchema.safeParse(raw);
          if (!parsed.success) throw permanent(`invalid journal page: ${z.prettifyError(parsed.error)}`);
          entries.push(...parsed.data.entries);
          labels = { ...labels, ...parsed.data.labels };
          const ws = (parsed.data as { workspace?: { name?: unknown } }).workspace?.name;
          if (typeof ws === 'string') workspace = ws;
          page = parsed.data.next_page ?? null;
        }
        const chainable = chainEntries(entries);
        const chain: ChainVerification = chainable ? verifyChain(chainable) : { ok: false, checked: 0, first_broken_seq: null, head_hash: null };
        let file: Buffer;
        let filename: string;
        let contentType: string;
        const base = `${payload.kind === 'prevented_risk' ? 'prevented-risk' : 'audit-journal'}-${payload.from}-${payload.to}`;
        if (payload.format === 'csv') {
          const { header, keys } = journalColumns(entries, labels, payload.locale);
          file = toCsv(header, entries.map((entry) => keys.map((key) => entry[key])));
          filename = `${base}.csv`;
          contentType = 'text/csv; charset=utf-8';
        } else if (payload.format === 'json') {
          file = toNdjson(entries);
          filename = `${base}.ndjson`;
          contentType = 'application/x-ndjson';
        } else {
          file = await renderJournalPdf({
            locale: payload.locale,
            workspace,
            from: payload.from,
            to: payload.to,
            generatedAt: domain.now().toISOString(),
            labels,
            entries,
            chain,
          });
          filename = `${base}.pdf`;
          contentType = 'application/pdf';
        }
        const digest = sha256(file);
        await domain.api.uploadExport(payload.export_id, payload.workspace_id, { filename, contentType, bytes: file }, digest, chainable !== null && chain.ok, ctx.requestId);
        return { entries: entries.length, bytes: file.byteLength, chain_verified: chainable !== null && chain.ok };
      },
      async (error) => {
        await domain.api.exportFailed(payload.export_id, payload.workspace_id, errorMessage(error), ctx.requestId);
      },
    );
  };
}

export function createChainVerifyHandler(deps: JobDeps): JobHandler {
  return async (_data: unknown, ctx: JobContext) => {
    const domain = services(deps);
    return domainStep(ctx, domain.metrics, AUDIT_JOBS.chainVerify, () => domain.api.verifyAllChains(ctx.requestId), async () => undefined);
  };
}
