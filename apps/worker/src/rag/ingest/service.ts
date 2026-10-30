import { createHash } from 'node:crypto';
import { UpstreamHttpError, UpstreamNetworkError } from '../../clients/errors.js';
import type { Logger } from '../../logger.js';
import type { EmbeddingsRegistry } from '../embeddings/registry.js';
import { EmbeddingError } from '../embeddings/types.js';
import type { RagMetrics } from '../metrics.js';
import { DocumentOwnershipError, embeddingText, type ChunkStore, type DocumentMeta } from '../retrieval/store.js';
import { FileTooLargeError, type RagWebApi } from '../web/client.js';
import type { Chunker } from './chunker.js';
import { OcrUnavailableError } from './ocr.js';
import { DocumentParseError, UnsupportedDocumentError, type DocumentParser } from './parsers.js';

export class IngestError extends Error {
  readonly retryable: boolean;

  constructor(
    message: string,
    readonly statusCode = 422,
    retryable = false,
  ) {
    super(message);
    this.name = 'IngestError';
    this.retryable = retryable;
  }
}

export interface IngestOutcome {
  document_id: number;
  pages: number | null;
  chunks: number;
  ocr_used: boolean;
  embedding_model: string;
  warnings: string[];
  status_reported: boolean;
}

function isKnownError(error: unknown): boolean {
  return (
    error instanceof DocumentParseError ||
    error instanceof UnsupportedDocumentError ||
    error instanceof OcrUnavailableError ||
    error instanceof EmbeddingError ||
    error instanceof FileTooLargeError ||
    error instanceof UpstreamHttpError ||
    error instanceof DocumentOwnershipError
  );
}

const CONCURRENCY_CONFLICT_CODES: ReadonlySet<string> = new Set(['40P01', '40001']);

export function isConcurrencyConflict(error: unknown): boolean {
  if (!error || typeof error !== 'object') return false;
  const code = (error as { code?: unknown }).code;
  return typeof code === 'string' && CONCURRENCY_CONFLICT_CODES.has(code);
}

export class IngestService {
  constructor(
    private readonly deps: {
      web: RagWebApi;
      parser: DocumentParser;
      chunker: Chunker;
      embeddings: EmbeddingsRegistry;
      store: ChunkStore;
      logger: Logger;
      metrics?: RagMetrics;
    },
  ) {}

  async ingest(meta: DocumentMeta, requestId?: string, externalAllowed?: boolean): Promise<IngestOutcome> {
    const started = performance.now();
    const { web, parser, chunker, embeddings, store, logger } = this.deps;
    await this.report(meta.documentId, { status: 'processing' }, requestId);
    let parsed;
    let stored;
    try {
      await store.markDocument(meta, 'processing');
      const downloaded = await web.downloadDocument(meta.documentId, requestId);
      parsed = await parser.parse(downloaded.content, meta.mime || downloaded.contentType, downloaded.filename);
      const drafts = chunker.chunk(parsed.blocks);
      if (drafts.length === 0) throw new DocumentParseError('the document contains no extractable text');
      const status = await embeddings.status();
      const embedder = await embeddings.active(undefined, externalAllowed === undefined ? {} : { externalAllowed });
      const warnings = [...parsed.warnings];
      if (status.degraded) warnings.push(`embeddings degraded to ${embedder.modelId}: ${status.reason ?? 'unknown reason'}`);
      const vectors = await embedder.embedDocuments(drafts.map((draft) => embeddingText(meta.title, draft.sectionPath, draft.content)));
      stored = await store.replaceDocument(meta, drafts, vectors, embedder, {
        pages: parsed.pages,
        ocrUsed: parsed.ocrUsed,
        contentSha256: createHash('sha256').update(downloaded.content).digest('hex'),
        warnings,
      });
    } catch (error) {
      const transient = error instanceof UpstreamNetworkError || (error instanceof UpstreamHttpError && error.retryable) || isConcurrencyConflict(error);
      const message = isKnownError(error) || transient ? (error as Error).message : 'internal error while processing the document';
      logger.warn({ documentId: meta.documentId, error: error instanceof Error ? error.message : String(error) }, 'document ingest failed');
      if (!transient) {
        await store.markDocument(meta, 'failed', message).catch((markError: unknown) =>
          logger.warn({ documentId: meta.documentId, error: String(markError) }, 'document could not be marked failed'),
        );
        await this.report(meta.documentId, { status: 'failed', error: message }, requestId);
      }
      this.deps.metrics?.ingestResults.inc({ status: 'failed', ocr: 'false' });
      let statusCode = 422;
      if (error instanceof FileTooLargeError) statusCode = 413;
      if (error instanceof DocumentOwnershipError) statusCode = 409;
      if (transient) statusCode = 503;
      throw new IngestError(message, statusCode, transient);
    }
    const reported = await this.report(
      meta.documentId,
      { status: 'indexed', pages: parsed.pages, chunks: stored.chunks, ocr_used: parsed.ocrUsed },
      requestId,
    );
    this.deps.metrics?.ingestResults.inc({ status: 'indexed', ocr: String(parsed.ocrUsed) });
    this.deps.metrics?.ingestDuration.observe((performance.now() - started) / 1000);
    logger.info(
      { documentId: meta.documentId, chunks: stored.chunks, pages: parsed.pages, ocrUsed: parsed.ocrUsed, model: stored.embeddingModel },
      'document ingested',
    );
    return {
      document_id: meta.documentId,
      pages: parsed.pages,
      chunks: stored.chunks,
      ocr_used: parsed.ocrUsed,
      embedding_model: stored.embeddingModel,
      warnings: stored.warnings,
      status_reported: reported,
    };
  }

  private async report(documentId: number, payload: Parameters<RagWebApi['reportDocumentStatus']>[1], requestId?: string): Promise<boolean> {
    try {
      await this.deps.web.reportDocumentStatus(documentId, payload, requestId);
      return true;
    } catch (error) {
      this.deps.logger.warn(
        { documentId, status: payload.status, error: error instanceof Error ? error.message : String(error) },
        'document status could not be reported',
      );
      return false;
    }
  }
}
