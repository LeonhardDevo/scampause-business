import { existsSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import PDFDocument from 'pdfkit';
import { describe, expect, it } from 'vitest';
import { prepareZXingModule, writeBarcode } from 'zxing-wasm/writer';
import { EmlParseError, looksLikeEml, parseAuthenticationResults, parseEml } from '../../src/domain/extract/eml.js';
import { assertImageWithinLimits, FileTooComplexError, imageSize, sniffMime } from '../../src/domain/parse/limits.js';
import { CanvasPageRenderer, FileParser, ocrRenderWidth, PREVIEW_WIDTH, UnsupportedFileError } from '../../src/domain/parse/parseFile.js';
import { extractFields } from '../../src/domain/extract/extractFields.js';
import { ZxingQrDecoder } from '../../src/domain/parse/qrDecoder.js';
import { createSilentLogger } from '../../src/logger.js';
import { TesseractOcr, type OcrEngine } from '../../src/rag/ingest/ocr.js';
import { BEC_EMAIL, EPC_PAYLOAD, eml, RU_1C_INVOICE } from './fixtures.js';

const require = createRequire(import.meta.url);

export function fakeOcr(text = 'OCR TEXT Total $12.00', missing: string[] = []): OcrEngine & { calls: number } {
  const engine = {
    calls: 0,
    available: async () => true,
    missingLanguages: async () => missing,
    languages: async () => 'eng',
    imageToText: async () => {
      engine.calls++;
      return text;
    },
  };
  return engine;
}

export async function textPdf(lines: string[]): Promise<Buffer> {
  const doc = new PDFDocument({ size: 'A4', margin: 48 });
  doc.registerFont('plex', require.resolve('@ibm/plex-sans/fonts/complete/woff/IBMPlexSans-Regular.woff'));
  doc.font('plex').fontSize(11);
  const chunks: Buffer[] = [];
  const done = new Promise<Buffer>((resolveDone) => doc.on('end', () => resolveDone(Buffer.concat(chunks))));
  doc.on('data', (chunk: Buffer) => chunks.push(chunk));
  for (const line of lines) doc.text(line);
  doc.end();
  return done;
}

export async function imagePdf(png: Buffer): Promise<Buffer> {
  const doc = new PDFDocument({ size: 'A4', margin: 0 });
  const chunks: Buffer[] = [];
  const done = new Promise<Buffer>((resolveDone) => doc.on('end', () => resolveDone(Buffer.concat(chunks))));
  doc.on('data', (chunk: Buffer) => chunks.push(chunk));
  doc.image(png, 0, 0, { width: 595.28 });
  doc.end();
  return done;
}

export async function qrPng(payload: string): Promise<Buffer> {
  const wasm = await readFile(require.resolve('zxing-wasm/writer/zxing_writer.wasm'));
  await prepareZXingModule({ overrides: { wasmBinary: wasm.buffer.slice(wasm.byteOffset, wasm.byteOffset + wasm.byteLength) }, fireImmediately: true });
  const result = await writeBarcode(payload, { format: 'QRCode', scale: 6 });
  if (!result.image) throw new Error(result.error || 'no image');
  return Buffer.from(await result.image.arrayBuffer());
}

function pngHeader(width: number, height: number): Buffer {
  const buffer = Buffer.alloc(33);
  buffer.writeUInt32BE(0x89504e47, 0);
  buffer.writeUInt32BE(0x0d0a1a0a, 4);
  buffer.writeUInt32BE(13, 8);
  buffer.write('IHDR', 12, 'latin1');
  buffer.writeUInt32BE(width, 16);
  buffer.writeUInt32BE(height, 20);
  buffer[24] = 8;
  buffer[25] = 2;
  return buffer;
}

const localTessdata = resolve(import.meta.dirname, '..', '..', '.tessdata');
const tessdataPrefix = process.env.TESSDATA_PREFIX ?? (existsSync(resolve(localTessdata, 'rus.traineddata')) ? localTessdata : undefined);
const tesseract = new TesseractOcr({ command: process.env.TESSERACT_PATH ?? 'tesseract', languages: 'rus+eng', timeoutMs: 30_000, tessdataPrefix, logger: createSilentLogger() });
const missing = await tesseract.missingLanguages();
const reason = missing.includes('rus') ? 'tesseract has no "rus" language data; run npm run setup' : '';

describe('e-mail parsing', () => {
  it('reads sender, authentication results and hops', async () => {
    const parsed = await parseEml(
      eml({
        replyTo: 'karen.whitfield.ar@gmail.com',
        returnPath: 'bounce@mailer.blueridge-pack.com',
        authResults: 'spf=fail smtp.mailfrom=blueridge-pack.com; dkim=none; dmarc=fail header.from=blueridge-pack.com',
        received: 3,
      }),
    );
    expect(parsed.sender).toMatchObject({
      from_name: 'Karen Whitfield',
      from_email: 'billing@blueridge-pack.com',
      reply_to: 'karen.whitfield.ar@gmail.com',
      return_path: 'bounce@mailer.blueridge-pack.com',
      to: ['maria@northwindroasters.com'],
      subject: 'Updated bank details',
      sent_at: '2026-10-20T10:15:00.000Z',
      message_id: '<msg-001@blueridge-pack.com>',
      auth: { spf: 'fail', dkim: 'none', dmarc: 'fail' },
      received_hops: 3,
    });
    expect(parsed.bodyText).toContain('LT09 3250 0247 2001 8839');
  });

  it('falls back to Received-SPF and ignores unknown verdicts', async () => {
    const parsed = await parseEml(eml({ receivedSpf: 'softfail (domain does not designate sender)' }));
    expect(parsed.sender.auth).toEqual({ spf: 'softfail', dkim: null, dmarc: null });
    expect(parseAuthenticationResults(['spf=bogus dkim=pass'])).toEqual({ spf: null, dkim: 'pass', dmarc: null });
  });

  it('extracts allowed attachments and lists the rest', async () => {
    const pdf = await textPdf(['Invoice INV-1', 'Total due $10.00']);
    const parsed = await parseEml(
      eml({
        attachments: [
          { filename: 'invoice.pdf', contentType: 'application/pdf', content: pdf },
          { filename: 'macro.xlsm', contentType: 'application/vnd.ms-excel.sheet.macroEnabled.12', content: 'x' },
        ],
      }),
    );
    expect(parsed.attachments.map((item) => item.filename)).toEqual(['invoice.pdf']);
    expect(parsed.skipped).toEqual([{ filename: 'macro.xlsm', contentType: 'application/vnd.ms-excel.sheet.macroenabled.12', reason: 'type_not_allowed' }]);
  });

  it('opens one nested message and rejects a second level', async () => {
    const inner = eml({ body: 'Inner body with new bank details' });
    const middle = eml({ body: 'Forwarded', attachments: [{ filename: 'inner.eml', contentType: 'message/rfc822', content: inner, raw: true }] });
    const outer = eml({ body: 'Outer', attachments: [{ filename: 'middle.eml', contentType: 'message/rfc822', content: middle, raw: true }] });
    const parsed = await parseEml(outer);
    expect(parsed.nestedBodies.join('\n')).toContain('Forwarded');
    expect(parsed.nestedBodies.join('\n')).not.toContain('Inner body');
    expect(parsed.warnings).toEqual(expect.arrayContaining(['EML_NESTED_MESSAGE', 'EML_NESTING_TOO_DEEP']));
    expect(parsed.skipped.some((item) => item.reason === 'nesting_too_deep')).toBe(true);
  });

  it('turns HTML-only messages into text', async () => {
    const raw = Buffer.from(
      ['From: a@example.com', 'To: b@example.com', 'Subject: html', 'MIME-Version: 1.0', 'Content-Type: text/html; charset=utf-8', '', '<p>Total&nbsp;due <b>$5.00</b></p><script>x()</script>'].join('\r\n'),
    );
    const parsed = await parseEml(raw);
    expect(parsed.bodyText).toContain('Total');
    expect(parsed.bodyText).toContain('$5.00');
    expect(parsed.bodyText).not.toContain('x()');
  });

  it('recognises .eml uploads sniffed as text/plain', () => {
    expect(looksLikeEml(eml({}), 'mail.eml', 'text/plain')).toBe(true);
    expect(looksLikeEml(Buffer.from(BEC_EMAIL), 'note.txt', 'text/plain')).toBe(false);
    expect(new EmlParseError('x').name).toBe('EmlParseError');
  });
});

describe('file parser', () => {
  const logger = createSilentLogger();

  it('reads a text-layer PDF without OCR', async () => {
    const ocr = fakeOcr();
    const parser = new FileParser({ ocr, logger });
    const pdf = await textPdf(['Счёт на оплату № 512', 'Итого к оплате: 248 300,00 руб.']);
    const parsed = await parser.parse({ workspace_id: 1, file_id: 1, mime: 'application/pdf', bytes: pdf });
    expect(parsed.mime).toBe('application/pdf');
    expect(parsed.page_count).toBe(1);
    expect(parsed.ocr_used).toBe(false);
    expect(parsed.pages[0]?.text).toContain('248 300,00');
    expect(ocr.calls).toBe(0);
  });

  it('rejects decompression-bomb images before decoding', async () => {
    const parser = new FileParser({ ocr: fakeOcr(), logger });
    const bomb = pngHeader(50_000, 50_000);
    expect(imageSize(bomb)).toEqual({ width: 50_000, height: 50_000 });
    expect(() => assertImageWithinLimits(bomb)).toThrow(FileTooComplexError);
    await expect(parser.parse({ workspace_id: 1, file_id: 2, mime: 'image/png', bytes: bomb })).rejects.toMatchObject({ code: 'file_too_complex' });
  });

  it('rejects unsupported files', async () => {
    const parser = new FileParser({ ocr: fakeOcr(), logger });
    await expect(parser.parse({ workspace_id: 1, file_id: 3, mime: 'application/zip', bytes: Buffer.from('PK') })).rejects.toBeInstanceOf(UnsupportedFileError);
    expect(sniffMime(Buffer.from('%PDF-1.7'), 'application/octet-stream')).toBe('application/pdf');
  });

  it('decodes a payment QR from an image and OCRs it', async () => {
    const png = await qrPng(EPC_PAYLOAD);
    const ocr = fakeOcr('Harbor Logistics invoice', ['rus']);
    const parser = new FileParser({ ocr, qr: new ZxingQrDecoder(), logger });
    const parsed = await parser.parse({ workspace_id: 1, file_id: 4, mime: 'image/png', bytes: png });
    expect(parsed.qr_codes).toEqual([{ page: 1, payload: EPC_PAYLOAD }]);
    expect(parsed.ocr_used).toBe(true);
    expect(parsed.missing_languages).toEqual(['rus']);
    expect(parsed.pages[0]?.text).toBe('Harbor Logistics invoice');
  });

  it('renders previews of PDF pages', async () => {
    const parser = new FileParser({ ocr: fakeOcr(), logger });
    const pdf = await textPdf(['Invoice INV-1', 'Total due $10.00']);
    const parsed = await parser.parse({ workspace_id: 1, file_id: 6, mime: 'application/pdf', bytes: pdf, render_previews: 10 });
    expect(parsed.previews).toHaveLength(1);
    expect(parsed.previews[0]?.png.readUInt32BE(16)).toBe(PREVIEW_WIDTH);
    expect(ocrRenderWidth(612, 792)).toBe(2550);
    expect(ocrRenderWidth(2000, 2000)).toBeLessThanOrEqual(Math.sqrt(40_000_000));
  });

  it.skipIf(reason !== '')(`OCRs a scanned Russian 1C invoice${reason ? ` (skipped: ${reason})` : ''}`, async () => {
    const textLayer = await textPdf(RU_1C_INVOICE.split(/\r?\n/));
    const scan = await new CanvasPageRenderer().render(textLayer, 1, 2480);
    expect(scan).not.toBeNull();
    const scanned = await imagePdf(scan!);
    const parser = new FileParser({ ocr: tesseract, logger });
    const parsed = await parser.parse({ workspace_id: 1, file_id: 5, mime: 'application/pdf', bytes: scanned });
    expect(parsed.ocr_used).toBe(true);
    expect(parsed.missing_languages).toEqual([]);
    const text = parsed.pages[0]?.text ?? '';
    expect(text).toMatch(/Итого/i);
    const outcome = extractFields({ documents: [{ file_id: 5, role: 'attachment', pages: parsed.pages }], qr_payloads: [], sender: null, country: 'RU', locale: 'ru' });
    expect(outcome.fields.amount_minor).toMatchObject({ value: 24830000, source: 'ocr' });
    expect(outcome.fields.payee?.account_number.value).toBe('40702810938000012345');
  }, 120_000);
});
