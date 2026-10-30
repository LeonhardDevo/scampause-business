import { getDocumentProxy, getResolvedPDFJS, renderPageAsImage } from 'unpdf';
import type { Logger } from '../../logger.js';
import type { OcrEngine } from '../../rag/ingest/ocr.js';
import { encodePnm } from '../../rag/ingest/pdf.js';
import { assertImageWithinLimits, FileTooComplexError, PARSE_LIMITS, sniffMime } from './limits.js';
import { noQrDecoder, type QrDecoder } from './qrDecoder.js';

export interface ParseFileInput {
  workspace_id: number;
  file_id: number;
  mime: string;
  bytes: Buffer;
  ocr_langs?: string;
  detect_qr?: boolean;
  max_pages?: number;
  render_previews?: number;
}

export interface ParsedPage {
  page: number;
  text: string;
  ocr_used: boolean;
}

export interface ParsedFile {
  mime: string;
  pages: ParsedPage[];
  qr_codes: Array<{ page: number | null; payload: string }>;
  previews: Array<{ page: number; png: Buffer }>;
  ocr_used: boolean;
  ocr_languages_used: string[];
  missing_languages: string[];
  page_count: number;
}

export class UnsupportedFileError extends Error {
  readonly retryable = false;
  readonly code = 'unsupported_file';

  constructor(mime: string) {
    super(`unsupported file type ${mime}`);
    this.name = 'UnsupportedFileError';
  }
}

export class EncryptedPdfError extends Error {
  readonly retryable = false;
  readonly code = 'pdf_js_or_encrypted';

  constructor() {
    super('the PDF is encrypted');
    this.name = 'EncryptedPdfError';
  }
}

interface DecodedImage {
  width: number;
  height: number;
  kind?: number;
  data?: Uint8Array | Uint8ClampedArray;
}

interface TextItemLike {
  str?: string;
  hasEOL?: boolean;
  transform?: number[];
}

interface ObjStore {
  get(key: string, callback: (value: unknown) => void): unknown;
}

export interface PageRenderer {
  render(pdf: Buffer, page: number, width: number): Promise<Buffer | null>;
}

export class CanvasPageRenderer implements PageRenderer {
  async render(pdf: Buffer, page: number, width: number): Promise<Buffer | null> {
    try {
      const image = await renderPageAsImage(new Uint8Array(pdf), page, { canvasImport: () => import('@napi-rs/canvas'), width: Math.max(1, Math.round(width)) });
      return Buffer.from(image);
    } catch {
      return null;
    }
  }
}

export const PREVIEW_WIDTH = 1200;
const OCR_DPI = 300;

export function ocrRenderWidth(widthPt: number, heightPt: number, maxPixels = PARSE_LIMITS.maxImagePixels): number {
  const byDpi = (widthPt * OCR_DPI) / 72;
  const byPixels = Math.sqrt((maxPixels * widthPt) / Math.max(1, heightPt));
  return Math.floor(Math.min(byDpi, byPixels));
}

export interface FileParserOptions {
  ocr: OcrEngine;
  qr?: QrDecoder;
  renderer?: PageRenderer | null;
  logger: Logger;
  now?: () => number;
}

function pageText(items: readonly TextItemLike[]): string {
  let text = '';
  let lastY: number | null = null;
  for (const item of items) {
    if (typeof item.str !== 'string') continue;
    const y = item.transform?.[5] ?? null;
    if (lastY !== null && y !== null && Math.abs(y - lastY) > 2 && !text.endsWith('\n')) text += '\n';
    text += item.str;
    if (item.hasEOL) text += '\n';
    if (y !== null) lastY = y;
  }
  return text;
}

function objectOf(store: ObjStore, key: string, timeoutMs: number): Promise<unknown> {
  return new Promise((resolve) => {
    const timer = setTimeout(() => resolve(null), timeoutMs);
    try {
      store.get(key, (value) => {
        clearTimeout(timer);
        resolve(value);
      });
    } catch {
      clearTimeout(timer);
      resolve(null);
    }
  });
}

export class FileParser {
  private readonly qr: QrDecoder;
  private readonly now: () => number;
  private readonly renderer: PageRenderer | null;

  constructor(private readonly options: FileParserOptions) {
    this.qr = options.qr ?? noQrDecoder;
    this.renderer = options.renderer === undefined ? new CanvasPageRenderer() : options.renderer;
    this.now = options.now ?? Date.now;
  }

  async parse(input: ParseFileInput): Promise<ParsedFile> {
    if (input.bytes.byteLength > PARSE_LIMITS.maxFileBytes) throw new FileTooComplexError('file exceeds 15 MB');
    const mime = sniffMime(input.bytes, input.mime);
    const deadline = this.now() + PARSE_LIMITS.jobTimeoutMs;
    const missing = await this.options.ocr.missingLanguages();
    const result: ParsedFile = {
      mime,
      pages: [],
      qr_codes: [],
      previews: [],
      ocr_used: false,
      ocr_languages_used: [],
      missing_languages: missing,
      page_count: 0,
    };
    if (mime === 'application/pdf') await this.parsePdf(input, result, deadline);
    else if (mime === 'image/png' || mime === 'image/jpeg' || mime === 'image/webp') await this.parseImage(input, result);
    else if (mime.startsWith('text/')) {
      result.pages.push({ page: 1, text: input.bytes.toString('utf8'), ocr_used: false });
      result.page_count = 1;
    } else throw new UnsupportedFileError(mime);
    if (result.ocr_used) result.ocr_languages_used = (await this.options.ocr.languages().catch(() => '')).split('+').filter(Boolean);
    return result;
  }

  private async ocrImage(image: Buffer): Promise<string | null> {
    if (!(await this.options.ocr.available())) return null;
    try {
      return await this.options.ocr.imageToText(image);
    } catch (error) {
      this.options.logger.warn({ error: error instanceof Error ? error.message : String(error) }, 'ocr failed for one image');
      return null;
    }
  }

  private async decodeQr(image: Buffer, page: number | null, result: ParsedFile, detect: boolean): Promise<void> {
    if (!detect) return;
    try {
      for (const payload of await this.qr.decode(image)) {
        if (!result.qr_codes.some((code) => code.payload === payload)) result.qr_codes.push({ page, payload });
      }
    } catch (error) {
      this.options.logger.debug({ error: error instanceof Error ? error.message : String(error) }, 'qr decode failed');
    }
  }

  private async parseImage(input: ParseFileInput, result: ParsedFile): Promise<void> {
    assertImageWithinLimits(input.bytes);
    result.page_count = 1;
    await this.decodeQr(input.bytes, 1, result, input.detect_qr ?? true);
    const text = await this.ocrImage(input.bytes);
    result.pages.push({ page: 1, text: text ?? '', ocr_used: text !== null });
    result.ocr_used = text !== null;
  }

  private async parsePdf(input: ParseFileInput, result: ParsedFile, deadline: number): Promise<void> {
    let pdf: Awaited<ReturnType<typeof getDocumentProxy>>;
    try {
      pdf = await getDocumentProxy(new Uint8Array(input.bytes), { disableFontFace: true, useSystemFonts: false, verbosity: 0 });
    } catch (error) {
      if ((error as { name?: string } | null)?.name === 'PasswordException') throw new EncryptedPdfError();
      throw new FileTooComplexError(`unreadable PDF: ${error instanceof Error ? error.message : String(error)}`);
    }
    const { OPS } = await getResolvedPDFJS();
    const maxPages = Math.min(input.max_pages ?? PARSE_LIMITS.maxPages, PARSE_LIMITS.maxPages);
    result.page_count = pdf.numPages;
    try {
      for (let number = 1; number <= Math.min(pdf.numPages, maxPages); number++) {
        if (this.now() > deadline) throw new FileTooComplexError('parsing took longer than allowed');
        const page = await pdf.getPage(number);
        const [, , width, height] = page.view;
        if ((width ?? 0) > PARSE_LIMITS.maxPagePoints || (height ?? 0) > PARSE_LIMITS.maxPagePoints) {
          page.cleanup();
          throw new FileTooComplexError(`page ${number} exceeds ${PARSE_LIMITS.maxPagePoints} pt`);
        }
        let text = '';
        try {
          text = pageText((await page.getTextContent()).items as TextItemLike[]);
        } catch {
          text = '';
        }
        const needsOcr = text.trim().length < PARSE_LIMITS.minPageTextChars;
        const detectQr = input.detect_qr ?? true;
        const preview = this.renderer && number <= Math.min(input.render_previews ?? 0, PARSE_LIMITS.previewPages) ? await this.renderer.render(input.bytes, number, PREVIEW_WIDTH) : null;
        if (preview) result.previews.push({ page: number, png: preview });
        const rendered = needsOcr && this.renderer ? await this.renderer.render(input.bytes, number, ocrRenderWidth(width ?? 612, height ?? 792)) : null;
        const images = rendered ? [rendered] : needsOcr || detectQr ? await this.pageImages(page, OPS) : [];
        for (const image of [...images, ...(preview && !rendered ? [preview] : [])]) await this.decodeQr(image, number, result, detectQr);
        let ocrUsed = false;
        if (needsOcr) {
          const parts: string[] = [];
          for (const image of images) {
            const ocrText = await this.ocrImage(image);
            if (ocrText !== null) {
              ocrUsed = true;
              parts.push(ocrText);
            }
          }
          if (ocrUsed) text = `${text}\n${parts.join('\n')}`.trim();
        }
        result.ocr_used ||= ocrUsed;
        result.pages.push({ page: number, text, ocr_used: ocrUsed });
        page.cleanup();
      }
    } finally {
      await pdf.loadingTask.destroy().catch(() => undefined);
    }
  }

  private async pageImages(page: Awaited<ReturnType<Awaited<ReturnType<typeof getDocumentProxy>>['getPage']>>, OPS: Record<string, number>): Promise<Buffer[]> {
    const images: Buffer[] = [];
    const operators = await page.getOperatorList();
    for (let index = 0; index < operators.fnArray.length; index++) {
      const fn = operators.fnArray[index];
      const args = operators.argsArray[index] as unknown[];
      let image: unknown = null;
      if (fn === OPS.paintImageXObject && typeof args[0] === 'string') {
        const key = args[0];
        image = await objectOf(key.startsWith('g_') ? page.commonObjs : page.objs, key, PARSE_LIMITS.pageTimeoutMs);
      } else if (fn === OPS.paintInlineImageXObject) {
        image = args[0];
      }
      const decoded = image as DecodedImage | null;
      if (!decoded || !decoded.width || !decoded.height) continue;
      if (decoded.width * decoded.height > PARSE_LIMITS.maxImagePixels) throw new FileTooComplexError('embedded image exceeds the pixel limit');
      if (decoded.width < 48 || decoded.height < 48) continue;
      const encoded = encodePnm(decoded);
      if (encoded) images.push(encoded);
      if (images.length >= 8) break;
    }
    return images;
  }
}
