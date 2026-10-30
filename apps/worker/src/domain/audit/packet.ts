import { createRequire } from 'node:module';
import PDFDocument from 'pdfkit';
import { z } from 'zod';
import type { ChainVerification } from '../evidence/canonical.js';

const text = z
  .union([z.string(), z.number(), z.boolean()])
  .nullish()
  .transform((value) => (value === null || value === undefined ? '' : String(value)));

export const packetEntrySchema = z
  .object({
    seq: z.number().int(),
    event_label: text,
    event: text,
    actor: text,
    channel: text,
    occurred_at_local: text,
    occurred_at: text,
    payload: z.unknown().optional(),
    prev_hash: z.string(),
    hash: z.string(),
    material: z.string(),
  })
  .passthrough();
export type PacketEntry = z.infer<typeof packetEntrySchema>;

export const evidencePacketSchema = z
  .object({
    workspace: z.object({ name: z.string(), timezone: text }),
    locale: z.enum(['ru', 'en']).catch('en'),
    generated_at: text,
    request: z
      .object({
        reference: text,
        title: text,
        kind_label: text,
        status_label: text,
        amount_formatted: text,
        vendor_name: text,
        invoice_number: text,
        due_date: text,
        payee_masked: text,
        risk: z
          .object({
            score: text,
            level_label: text,
            signals: z.array(z.object({ title: text, points: text, explanation: text })).default([]),
          })
          .default({ score: '', level_label: '', signals: [] }),
      })
      .passthrough(),
    bank_diff: z.object({ rows: z.array(z.record(z.string(), z.unknown())).default([]) }).default({ rows: [] }),
    verifications: z.array(z.record(z.string(), z.unknown())).default([]),
    approvals: z.array(z.record(z.string(), z.unknown())).default([]),
    entries: z.array(packetEntrySchema).default([]),
    disclaimer: text,
    labels: z.record(z.string(), z.string()).default({}),
  })
  .passthrough();
export type EvidencePacketData = z.infer<typeof evidencePacketSchema>;

const DEFAULT_LABELS: Readonly<Record<'ru' | 'en', Record<string, string>>> = Object.freeze({
  en: {
    title: 'Evidence packet',
    journal_title: 'Audit journal',
    workspace: 'Workspace',
    generated_at: 'Generated',
    request: 'Payment request',
    risk: 'Risk signals',
    bank_diff: 'Bank details comparison',
    verifications: 'Verifications',
    approvals: 'Approvals',
    entries: 'Evidence log',
    chain_ok: 'Hash chain verified: {count} entries, head {head}.',
    chain_broken: 'Hash chain mismatch at entry No. {seq}. Do not rely on this packet; contact your workspace owner.',
    period: 'Period',
    none: 'None',
  },
  ru: {
    title: 'Пакет доказательств',
    journal_title: 'Журнал аудита',
    workspace: 'Рабочее пространство',
    generated_at: 'Сформирован',
    request: 'Запрос на оплату',
    risk: 'Сигналы риска',
    bank_diff: 'Сравнение реквизитов',
    verifications: 'Проверки',
    approvals: 'Согласования',
    entries: 'Журнал доказательств',
    chain_ok: 'Цепочка хешей проверена: записей {count}, последний хеш {head}.',
    chain_broken: 'Цепочка хешей нарушена на записи № {seq}. Не полагайтесь на этот пакет; обратитесь к владельцу рабочего пространства.',
    period: 'Период',
    none: 'Нет',
  },
});

function fontPath(spec: string): string {
  return createRequire(import.meta.url).resolve(spec);
}

const FONTS = {
  sans: '@ibm/plex-sans/fonts/complete/woff/IBMPlexSans-Regular.woff',
  sansBold: '@ibm/plex-sans/fonts/complete/woff/IBMPlexSans-SemiBold.woff',
  mono: '@ibm/plex-mono/fonts/complete/woff/IBMPlexMono-Regular.woff',
} as const;

export function label(labels: Record<string, string>, locale: 'ru' | 'en', key: string, params: Record<string, string | number> = {}): string {
  const template = labels[key] ?? DEFAULT_LABELS[locale][key] ?? key;
  return template.replace(/\{(\w+)\}/g, (match, name: string) => (name in params ? String(params[name]) : match));
}

function scalar(value: unknown): string {
  if (value === null || value === undefined) return '';
  if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') return String(value);
  if (Array.isArray(value)) return value.map(scalar).filter(Boolean).join('; ');
  return JSON.stringify(value);
}

class PdfWriter {
  readonly doc: PDFKit.PDFDocument;
  private readonly chunks: Buffer[] = [];
  private readonly done: Promise<Buffer>;

  constructor(title: string, createdAt: Date) {
    this.doc = new PDFDocument({ size: 'A4', margin: 48, bufferPages: true, info: { Title: title, Producer: 'ScamPause', Creator: 'ScamPause', CreationDate: createdAt } });
    this.doc.registerFont('sans', fontPath(FONTS.sans));
    this.doc.registerFont('sans-bold', fontPath(FONTS.sansBold));
    this.doc.registerFont('mono', fontPath(FONTS.mono));
    this.doc.font('sans');
    this.done = new Promise((resolve, reject) => {
      this.doc.on('data', (chunk: Buffer) => this.chunks.push(chunk));
      this.doc.on('end', () => resolve(Buffer.concat(this.chunks)));
      this.doc.on('error', reject);
    });
  }

  heading(value: string, size = 13): void {
    this.doc.moveDown(0.6).font('sans-bold').fontSize(size).fillColor('#111827').text(value).moveDown(0.2).font('sans').fontSize(10);
  }

  line(value: string, options: { mono?: boolean; size?: number; color?: string } = {}): void {
    if (value.trim() === '') return;
    this.doc
      .font(options.mono ? 'mono' : 'sans')
      .fontSize(options.size ?? 10)
      .fillColor(options.color ?? '#1f2937')
      .text(value, { lineGap: 1.5 });
  }

  pair(key: string, value: string): void {
    if (value.trim() === '') return;
    this.line(`${key}: ${value}`);
  }

  async finish(): Promise<Buffer> {
    this.doc.end();
    return this.done;
  }
}

function createdAt(value: string): Date {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? new Date(0) : date;
}

export async function renderEvidencePacket(data: EvidencePacketData, chain: ChainVerification): Promise<Buffer> {
  const { locale, labels } = data;
  const l = (key: string, params: Record<string, string | number> = {}): string => label(labels, locale, key, params);
  const writer = new PdfWriter(`${l('title')} ${data.request.reference}`, createdAt(data.generated_at));
  writer.heading(`${l('title')} · ${data.request.reference}`, 18);
  writer.pair(l('workspace'), data.workspace.name);
  writer.pair(l('generated_at'), data.generated_at);
  writer.line(
    chain.ok ? l('chain_ok', { count: chain.checked, head: (chain.head_hash ?? '').slice(0, 16) }) : l('chain_broken', { seq: chain.first_broken_seq ?? 0 }),
    { color: chain.ok ? '#065f46' : '#991b1b' },
  );

  writer.heading(l('request'));
  const request = data.request;
  for (const key of ['title', 'kind_label', 'status_label', 'amount_formatted', 'vendor_name', 'invoice_number', 'due_date', 'payee_masked'] as const) {
    writer.pair(l(key), request[key]);
  }

  writer.heading(l('risk'));
  writer.line(`${request.risk.level_label} (${request.risk.score}/100)`);
  if (request.risk.signals.length === 0) writer.line(l('none'));
  for (const signal of request.risk.signals) writer.line(`• ${signal.title} (+${signal.points}) — ${signal.explanation}`);

  if (data.bank_diff.rows.length > 0) {
    writer.heading(l('bank_diff'));
    for (const row of data.bank_diff.rows) writer.line(Object.values(row).map(scalar).filter(Boolean).join(' · '));
  }

  writer.heading(l('verifications'));
  if (data.verifications.length === 0) writer.line(l('none'));
  for (const verification of data.verifications) {
    writer.line(
      ['method_label', 'outcome_label', 'occurred_at_local', 'performed_by', 'spoken_to_name', 'contact_name', 'phone_masked', 'phone_source_label', 'source_ref']
        .map((key) => scalar(verification[key]))
        .filter(Boolean)
        .join(' · '),
    );
    const checklist = verification.checklist;
    if (Array.isArray(checklist)) {
      for (const item of checklist) {
        const record = item as Record<string, unknown>;
        writer.line(`  – ${scalar(record.label)}: ${scalar(record.value)}`, { size: 9 });
      }
    }
  }

  writer.heading(l('approvals'));
  if (data.approvals.length === 0) writer.line(l('none'));
  for (const approval of data.approvals) {
    writer.line(
      ['slot', 'approver', 'role_label', 'status_label', 'channel_label', 'decided_at_local', 'comment']
        .map((key) => scalar(approval[key]))
        .filter(Boolean)
        .join(' · '),
    );
  }

  writer.heading(l('entries'));
  for (const entry of data.entries) {
    writer.line(`№ ${entry.seq} · ${entry.event_label || entry.event} · ${entry.actor} · ${entry.channel} · ${entry.occurred_at_local || entry.occurred_at}`, { size: 9 });
    writer.line(`prev ${entry.prev_hash}`, { mono: true, size: 7, color: '#4b5563' });
    writer.line(`hash ${entry.hash}`, { mono: true, size: 7, color: '#4b5563' });
  }

  if (data.disclaimer.trim() !== '') {
    writer.doc.moveDown(1);
    writer.line(data.disclaimer, { size: 9, color: '#374151' });
  }
  return writer.finish();
}

export interface JournalSummary {
  locale: 'ru' | 'en';
  workspace: string;
  from: string;
  to: string;
  generatedAt: string;
  labels: Record<string, string>;
  entries: ReadonlyArray<Record<string, unknown>>;
  chain: ChainVerification;
}

export async function renderJournalPdf(summary: JournalSummary): Promise<Buffer> {
  const l = (key: string, params: Record<string, string | number> = {}): string => label(summary.labels, summary.locale, key, params);
  const writer = new PdfWriter(l('journal_title'), createdAt(summary.generatedAt));
  writer.heading(l('journal_title'), 18);
  writer.pair(l('workspace'), summary.workspace);
  writer.pair(l('period'), `${summary.from} — ${summary.to}`);
  writer.pair(l('generated_at'), summary.generatedAt);
  writer.line(
    summary.chain.ok
      ? l('chain_ok', { count: summary.chain.checked, head: (summary.chain.head_hash ?? '').slice(0, 16) })
      : l('chain_broken', { seq: summary.chain.first_broken_seq ?? 0 }),
    { color: summary.chain.ok ? '#065f46' : '#991b1b' },
  );
  writer.heading(l('entries'));
  if (summary.entries.length === 0) writer.line(l('none'));
  for (const entry of summary.entries) {
    writer.line(
      ['seq', 'event_label', 'actor', 'channel', 'occurred_at_local', 'reference']
        .map((key) => scalar(entry[key]))
        .filter(Boolean)
        .join(' · '),
      { size: 9 },
    );
  }
  return writer.finish();
}
