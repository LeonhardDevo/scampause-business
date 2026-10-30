# ADR-0003 — Extraction pipeline as a BullMQ flow in the worker; parsing, OCR and QR in-process

- Status: Accepted (2026-10-22), revised 2026.10.2 (strict stack, PLATFORM §0.10)
- Related: DOMAIN_CONTRACT §2.3, §2.17, §5.2, §6.2; ARCHITECTURE §6; ADR-0010

## Context
The brief places OCR and PDF data extraction, domain checks and bank-detail comparison in Node.js + BullMQ. PLATFORM §0.10 fixes the
ScamPause stack to Laravel + Vue, Node.js + BullMQ/Redis (which also hosts the bot and the TypeScript RAG) and Playwright — there is no
Python service to delegate parsing to. All business rules stay in web. We need retries, observability, ordering (extraction before
checks), parallelism (domain checks and bank comparison in parallel), protection against hostile files, and no request stuck forever.

## Decision
- The worker owns the **pipeline** as a BullMQ flow: `intake.extract` → flow parent `intake.score` with children
  `intake.domain-check` and `intake.bank-compare`. Job ids include `payment_request_id` and `revision`; web replies `409 stale_revision`
  to outdated jobs, which complete as no-ops.
- Parsing is **in-process TypeScript** (`apps/worker/src/domain/parse/`): PDF text layer via `pdfjs-dist`; pages with < 20 chars are
  rendered with `@napi-rs/canvas` and OCR'd by spawning the **`tesseract` CLI** (`rus+eng`, 30 s kill timeout per page); images are
  normalised with `sharp` (40 MP cap); QR via `zxing-wasm`; `.eml` via `mailparser` (nesting depth 1). The same parser serves the RAG
  ingestion of knowledge-base files, so there is still one OCR stack per product.
- Missing `rus` traineddata is detected at boot; extraction then reports `missing_languages`, the worker adds `OCR_LANGUAGE_MISSING`
  and web forces verification — never a silent quality drop. `npm run setup` installs `tessdata_fast` `rus` into a user-writable dir.
- **Field extraction** (amounts, IBAN/ABA/RU checksums, dates, tax ids, phones, kind suggestion) are pure functions, fixture-tested.
- Domain facts are fetched by the worker (`live` driver in production; `fake` only in local/testing/e2e) and cached in `domain_intel`
  keyed by `(domain, source)`.
- Every stage has a final-failure handler that reports `failed` to web; web scores with what it has, adds `PIPELINE_INCOMPLETE` and
  forces verification. A web `pipeline:sweep` republishes or closes requests stuck in `received`/`processing` for > 10 minutes.
- Per-workspace intake concurrency (Redis semaphore, 2) keeps one tenant from starving others.

## Consequences
- Positive: one runtime for queues, bot, RAG and OCR; retries and visibility per stage in Bull Board; no extra network hop per file.
- Positive: hostile-file limits are enforced where the bytes are decoded.
- Negative: the worker image needs the `tesseract` binary and `tesseract-ocr-rus`; local Windows dev needs `TESSDATA_PREFIX`.
- Negative: field extraction lives in TypeScript while scoring lives in PHP — the `ExtractionResult` contract and shared fixtures keep
  them aligned.

## Alternatives considered
- OCR in a Python service (PyMuPDF + Tesseract) — rejected: forbidden by PLATFORM §0.10 for this product.
- `tesseract.js` (WASM) — rejected: slower, weaker Cyrillic accuracy than the native CLI with `tessdata_fast`.
- Everything in Laravel queues — rejected: PLATFORM assigns queues to the worker; long OCR jobs do not belong in PHP workers.
