# ADR-0010 — Strict stack: no Python service; OCR, extraction and RAG in the Node worker

- Status: Accepted (2026.10.2)
- Related: PLATFORM §0.10, §4.4–4.6; DOMAIN_CONTRACT header, §6.2, §7, §11.5; ADR-0003

## Context
The user fixed one stack per product (decision 2026-10-22, PLATFORM §0.10). For ScamPause Business it is: Laravel + Vue SaaS, a Telegram
bot with RAG, Node.js + BullMQ/Redis (OCR and PDF data extraction, domain checks, bank-detail comparison, approval notifications,
webhooks, audit ledger) and Playwright — **no FastAPI/Python**. The first plan revision reused the foundation's Python `apps/ai` for
`/v1/parse`, extract-assist and RAG tools.

## Decision
- `apps/worker` hosts everything that is not Laravel: BullMQ queues and flows, the grammY bot (`scampause` BotModule), the in-process
  parser (pdfjs-dist, `tesseract` CLI, zxing-wasm, mailparser) and the **TypeScript RAG** in `apps/worker/src/rag` (port of the TBO
  provider manager, hybrid pgvector + FTS retrieval, confidence gate, mock provider, agent loop with permission-filtered tools).
- The RAG serves the unchanged PLATFORM §4.4 `/v1/*` HTTP contract on the worker port, so the Laravel `AiClient` (`AI_URL`) is unaware
  of the implementation. Embeddings in dev/tests: `EMBEDDINGS_PROVIDER=hash`.
- Domain AI code (tools, mock intents, prompts, output guards, PII placeholders, locale de-duplication, extract-assist) is the
  **rag-domain** package inside the worker; there is no `apps/ai`, no pytest, no `pyproject` in this repository.
- The foundation TS RAG port is a prerequisite cloned with the foundation; the domain relies only on its registry and hook seams
  (DOMAIN_CONTRACT §11.5).

## Consequences
- Positive: one runtime and one deployable for all background and conversational work; fewer signed hops; one test runner (Vitest).
- Positive: the bot calls the RAG in-process; the web assistant still uses the standard HTTP contract.
- Negative: CPU-heavy OCR shares the worker process pool — mitigated by per-page timeouts, per-workspace intake concurrency and a
  separate `intake` queue concurrency.
- Negative: the TypeScript RAG must match the Python reference behaviour of OpsBot (same contract tests).

## Alternatives considered
- Keep `apps/ai` (FastAPI) for parsing and RAG — rejected: contradicts the user's stack decision.
- `tesseract.js` instead of the CLI — rejected (accuracy and speed on Cyrillic scans).
