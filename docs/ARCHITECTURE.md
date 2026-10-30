# ScamPause Business — Architecture

Shared services and contracts are defined in `PLATFORM.md`. **Strict stack for this product (PLATFORM §0.10):** web = Laravel + Vue (Inertia); worker = Node 24 + BullMQ/Redis + grammY, which also hosts OCR (`tesseract` CLI), PDF/EML extraction and the **TypeScript RAG** (`apps/worker/src/rag`, `/v1/*` contract of PLATFORM §4.4 on the worker port); e2e = Playwright. There is **no Python/FastAPI service** (ADR-0010).
This document shows how the ScamPause domain uses them. Precise names, payloads and rules: `docs/DOMAIN_CONTRACT.md`.

## 1. System context

```mermaid
flowchart LR
    owner["Owner / approver<br/>(web, Telegram)"]
    bookkeeper["Bookkeeper / finance lead<br/>(web)"]
    employee["Employee<br/>(Telegram / e-mail)"]
    auditor["Auditor<br/>(web, read-only)"]
    sp(["ScamPause Business"])
    tg["Telegram Bot API"]
    smtp["SMTP provider<br/>(Mailpit in dev)"]
    stripe["Stripe (primary) / YooKassa (RUB)<br/>(fake driver offline)"]
    llm["LLM providers<br/>(mock by default)"]
    dns["Public DNS + RDAP<br/>(fake driver offline)"]
    bank["Customer's bank / AP tool<br/>(outside the system)"]

    bookkeeper -- "uploads invoice / .eml,<br/>records callback" --> sp
    owner -- "approves / rejects" --> sp
    auditor -- "reviews evidence" --> sp
    sp -- "approval requests,<br/>alerts" --> tg
    tg -- "callbacks, questions" --> sp
    sp -- "notifications" --> smtp
    smtp --> employee
    tg --> employee
    sp -- "checkout, portal" --> stripe
    stripe -- "webhooks" --> sp
    sp -- "assistant, extraction assist" --> llm
    sp -- "MX / SPF / DMARC / domain age" --> dns
    bookkeeper -.->|"pays manually after approval"| bank
```

ScamPause never moves money: the payment is executed by the customer in their own bank after the approval is recorded.

## 2. Containers

```mermaid
flowchart TB
    subgraph client["Browser"]
        spa["Inertia + Vue 3 SPA<br/>shadcn-vue, ECharts, IBM Plex"]
    end
    subgraph web["web — Laravel 13 (port 8300)"]
        http["HTTP controllers<br/>Domain + foundation"]
        domain["Domain services<br/>RiskEngine, PolicyEvaluator, RouteBuilder,<br/>VerificationService, EvidenceLedger"]
        internal["Internal API<br/>/internal/v1/scampause/*"]
        outbox[("outbox_messages")]
    end
    subgraph worker["worker — Node 24 (port 8301)"]
        relay["Outbox relay<br/>LISTEN outbox + poll"]
        queues["BullMQ queues + FlowProducer<br/>intake, domain-intel, approvals, audit"]
        parse["Parser (in-process)<br/>pdfjs-dist + canvas, tesseract CLI,<br/>zxing-wasm, mailparser, sharp limits"]
        bot["grammY bot<br/>core → scampause module → assistant"]
        ragsvc["TypeScript RAG /v1/*<br/>agent loop, domain tools, guards"]
        hooks["Fastify webhooks<br/>/webhooks/stripe, /webhooks/yookassa, /webhooks/telegram"]
        pdf["Evidence PDF / CSV<br/>pdfkit + IBM Plex"]
    end
    subgraph infra["Infrastructure (docker compose)"]
        pg[("Postgres 17 + pgvector<br/>5443: public, rag, worker")]
        redis[("Redis 7<br/>6393")]
        mail["Mailpit<br/>1033 / 8331"]
        disk[("Private disk<br/>AES-GCM encrypted uploads, previews, exports")]
    end

    spa <--> http
    http --> domain
    domain --> outbox
    domain --> pg
    domain --> disk
    internal --> domain
    outbox --> relay
    relay --> queues
    queues --> redis
    queues -- "signed HTTP" --> internal
    queues --> parse
    parse -- "download file (decrypted stream)" --> internal
    bot -- "signed HTTP" --> internal
    bot --> ragsvc
    http -- "AiClient (AI_URL = worker /v1)" --> ragsvc
    hooks --> queues
    pdf -- "upload export" --> internal
    ragsvc -- "tools: signed HTTP" --> internal
    ragsvc -- "rag schema (pgvector + FTS)" --> pg
    queues -- "SMTP" --> mail
```

Ownership rule (PLATFORM §3): **web owns every business decision and all decrypted bank data**; worker does orchestration and external I/O
(parsing/OCR/QR, DNS/RDAP, Telegram, SMTP, PDF rendering) and the RAG (retrieval, LLM calls), which reaches domain data only through permission-checked tools on the internal API.

Foreign-to-the-foundation seams this product uses are listed exactly in DOMAIN_CONTRACT §11.5 (route requires, `config/permissions.php`, `WorkspaceRole`, `HandleInertiaRequests`, plan usage resolvers, deletion events, worker queue/handler/module/scheduler registration, RAG registry/intents/output-guard/post-fusion hooks).

## 3. Data flow — intake to decision

```mermaid
flowchart LR
    A["File or manual entry"] --> B["payment_requests<br/>status received"]
    B -- "outbox intake.received" --> C["worker intake.extract"]
    C -- "GET file" --> D["web private disk"]
    C -- "parseFile()" --> E["worker parser: text + OCR + QR + previews"]
    E --> C
    C -- "ExtractionResult" --> F["web: extractions (encrypted)<br/>+ vendor matching"]
    F --> G{"BullMQ flow"}
    G --> H["intake.domain-check<br/>DNS + RDAP facts"]
    G --> I["intake.bank-compare<br/>web BankDetailsComparator"]
    H --> J["intake.score<br/>web RiskEngine"]
    I --> J
    J --> K["risk_assessments + risk_signals<br/>status review"]
    K -- "submit" --> L{"verification required?"}
    L -- "yes" --> M["awaiting_verification<br/>Stop. Call. Confirm."]
    M -- "confirmed" --> N["awaiting_approval<br/>approval route"]
    M -- "denied" --> R["rejected + block list<br/>prevented amount"]
    L -- "no" --> N
    N -- "all approvals" --> O["approved"]
    N -- "any reject" --> R
    N -- "deadline" --> X["expired"]
    O -- "mark paid" --> P["paid"]
    K & M & N & O & R & X & P --> Z[("evidence_entries<br/>hash chain")]
```

## 4. Data model overview

```mermaid
erDiagram
    WORKSPACES ||--o{ VENDORS : has
    VENDORS ||--o{ VENDOR_DOMAINS : uses
    VENDORS ||--o{ VENDOR_CONTACTS : "official contacts"
    VENDORS ||--o{ VENDOR_BANK_ACCOUNTS : "account history"
    WORKSPACES ||--o{ PAYMENT_REQUESTS : receives
    VENDORS ||--o{ PAYMENT_REQUESTS : "payee of"
    PAYMENT_REQUESTS ||--o{ PAYMENT_REQUEST_FILES : attaches
    PAYMENT_REQUESTS ||--o{ EXTRACTIONS : "per revision"
    PAYMENT_REQUESTS ||--o| EMAIL_MESSAGES : "parsed .eml"
    PAYMENT_REQUESTS ||--o{ BANK_COMPARISONS : "per revision"
    PAYMENT_REQUESTS ||--o{ RISK_ASSESSMENTS : assessed
    RISK_ASSESSMENTS ||--o{ RISK_SIGNALS : contains
    PAYMENT_REQUESTS ||--o{ VERIFICATIONS : "callback evidence"
    VENDOR_CONTACTS ||--o{ VERIFICATIONS : "number dialled"
    PAYMENT_REQUESTS ||--o{ APPROVAL_ROUTES : "attempts"
    APPROVAL_ROUTES ||--o{ APPROVALS : slots
    WORKSPACES ||--o{ APPROVAL_RULES : configures
    WORKSPACES ||--o{ MEMBER_LIMITS : configures
    WORKSPACES ||--o{ BLOCKED_BANK_ACCOUNTS : blocks
    WORKSPACES ||--o{ EVIDENCE_ENTRIES : "hash chain"
    WORKSPACES ||--|| EVIDENCE_HEADS : "chain head"
    WORKSPACES ||--o{ REPORT_EXPORTS : exports
    WORKSPACES ||--|| WORKSPACE_PAYMENT_SETTINGS : settings
    PAYMENT_REQUESTS ||--o{ EMPLOYEE_ALERTS : "payroll alert"
    PAYMENT_REQUESTS ||--o{ SP_NOTIFICATIONS : "recipient x channel"
    WORKSPACES ||--o{ DOMAIN_INTEL_SIGHTINGS : "first checked"
```

Intra-workspace foreign keys use `NO ACTION`; users referenced by evidence are pseudonymized, never hard-deleted; workspace purge runs through `WorkspacePurger` with the evidence GUC (ADR-0011).

## 5. Payment request state machine

```mermaid
stateDiagram-v2
    [*] --> received
    received --> processing: worker starts extraction
    received --> review: manual entry without sender
    received --> processing: manual entry with sender e-mail
    processing --> review: scored, or any stage failed / sweeper (PIPELINE_INCOMPLETE)
    review --> review: edit fields, re-assess
    review --> awaiting_verification: submit, verification required
    review --> awaiting_approval: submit, no verification required
    review --> rejected: obvious scam
    awaiting_verification --> awaiting_approval: callback confirmed
    awaiting_verification --> rejected: callback denied
    awaiting_approval --> approved: required approvals reached
    awaiting_approval --> rejected: any approver rejects
    awaiting_approval --> expired: deadline passed
    awaiting_approval --> awaiting_verification: re-assessment requires verification again
    awaiting_approval --> review: no eligible approver left
    expired --> awaiting_approval: reopen, verification fresh
    expired --> awaiting_verification: reopen, verification stale
    approved --> paid: mark paid
    approved --> rejected: revoke
    review --> cancelled
    awaiting_verification --> cancelled
    awaiting_approval --> cancelled
    expired --> cancelled
    paid --> [*]
    rejected --> [*]
    cancelled --> [*]
```

## 6. Sequence — intake, extraction and risk assessment

```mermaid
sequenceDiagram
    autonumber
    actor U as Bookkeeper
    participant W as web
    participant DB as Postgres
    participant R as worker relay
    participant Q as BullMQ intake
    participant P as worker parser (in-process)
    participant DNS as DNS / RDAP
    U->>W: POST /payment-requests (invoice.pdf)
    W->>DB: insert payment_request (received), file, evidence request.created
    W->>DB: outbox intake.received (same transaction)
    W-->>U: redirect to case file (polls status)
    W->>R: NOTIFY outbox
    R->>DB: claim rows FOR UPDATE SKIP LOCKED
    R->>Q: add intake.extract (jobId = outbox id)
    Q->>W: POST pipeline extract running
    Q->>W: GET intake files + file stream
    Q->>P: parseFile(bytes, rus+eng, detect_qr, limits)
    P->>P: pdfjs text layer, tesseract CLI per page (30 s cap), zxing-wasm
    P-->>Q: pages text, ocr_used, missing_languages, qr_codes, previews
    Q->>W: POST previews (≤ 10 PNG pages, stored encrypted)
    Q->>Q: extractFields (amount, IBAN, dates, sender)
    Q->>W: POST extraction (ExtractionResult)
    W->>DB: store encrypted text + fields, vendor match
    W-->>Q: proceed, domains
    Q->>Q: flow parent intake.score, children domain-check + bank-compare
    par domain facts
        Q->>W: GET domain-intel (mode live / cache_only)
        Q->>DNS: MX, SPF, _dmarc, RDAP registration date
        Q->>W: POST domain-intel facts
    and bank comparison
        Q->>W: POST bank-comparison
        W->>DB: compare fingerprints with vendor history, store outcome
    end
    Q->>W: POST assess
    W->>DB: RiskEngine signals + score, status review, evidence request.assessed
    W->>DB: outbox telegram.notify (template + raw params) / email.send (if high or critical)
    Note over Q,W: any stage's final failure → POST pipeline failed → review with PIPELINE_INCOMPLETE, verification forced; pipeline:sweep republishes stuck requests
    U->>W: GET status (poll) shows review with signals
```

## 7. Sequence — verification, approvals in web and Telegram, reminders and expiry

```mermaid
sequenceDiagram
    autonumber
    actor B as Bookkeeper
    actor A1 as Approver (web)
    actor A2 as Approver (Telegram)
    participant W as web
    participant O as outbox / relay
    participant Q as BullMQ approvals
    participant T as Telegram
    B->>W: POST verifications (official contact, confirmed, checklist)
    W->>W: guards: official number, SoD, checklist, not from message
    W->>W: status awaiting_approval, PolicyEvaluator, RouteBuilder
    W->>O: approval.requested x N, reminder.due (delayed), escalation.due, expiry.due
    O->>Q: approvals.dispatch → per-channel jobs (-tg, -mail)
    Q->>Q: render sp.approval_requested in the scampause module (params escaped, StoredAction buttons)
    Q->>T: sendMessage with Approve / Reject / Open
    Q->>W: POST approvals/{id}/telegram-message (persisted before ack)
    A1->>W: POST approve (web)
    W->>W: evidence approval.approved (channel web)
    A2->>T: tap Approve
    T->>Q: callback a:<stored id> (telegram-updates queue) → module action sp.apv
    Q->>W: POST scampause/telegram/callback (action_type, subject_id, step_token)
    W-->>Q: edit to confirmation view
    A2->>T: tap Confirm
    T->>Q: callback a:<stored id> → sp.apv_confirm
    Q->>W: POST scampause/telegram/callback
    W->>W: guards: assignee, step token, eligibility re-check under lock (membership, role, limits), risk + plan allow Telegram approve, route active
    W->>W: route completed, request approved, master data account verified
    W->>O: telegram.notify (edit messages), email.send, evidence.packet.requested
    W-->>Q: answer "Approved by you"
    Note over O,Q: If nobody decides
    O->>Q: approval.reminder.due at T+4h, T+24h (quiet hours shifted)
    Q->>W: POST approvals/{id}/reminders
    W-->>Q: rendered reminder or send false
    O->>Q: approval.expiry.due at expires_at
    Q->>W: POST approval-routes/{id}/expire
    W->>W: status expired, notify requester and owner
    Note over Q,W: approvals.sweep every 5 min expires overdue routes if a delayed message was lost
```

## 8. Sequence — Telegram RAG question with domain tools

```mermaid
sequenceDiagram
    autonumber
    actor M as Finance lead
    participant T as Telegram
    participant B as worker bot
    participant W as web internal API
    participant AI as worker RAG /v1/chat (TypeScript)
    participant V as pgvector + FTS
    M->>T: "Нужен ли второй согласующий для суммы $4 000?"
    T->>B: update (telegram-updates queue, jobId = update_id)
    B->>W: GET /internal/v1/telegram/context
    W-->>B: user, workspace, role, permissions
    B->>AI: POST /v1/chat (channel telegram, locale ru)
    AI->>AI: intent approvals_needed (mock) or LLM tool choice
    AI->>W: POST /internal/v1/scampause/policy/evaluate (amount 4000 USD)
    W-->>AI: approvals_required 2, rule "Dual control from $3,000"
    AI->>V: hybrid search "approval policy thresholds" (workspace, role filter, locale de-dup)
    V-->>AI: chunks of payment-approval-policy.ru
    AI->>AI: tool output to the model with [[PHONE_n]]/[[PERSON_n]] placeholders and untrusted_text wrapping
    AI->>AI: compose answer, cite [1], restore placeholders, output guards (masked accounts, official numbers only, no "safe", HTML allow-list)
    AI-->>B: answer_html, citations, actions
    B->>W: POST /internal/v1/ai-queries (log)
    B->>T: sendMessage with numbered sources
```

## 9. Sequence — Stripe subscription lifecycle (fake driver exercises the real webhook path; YooKassa for RUB uses the same `BillingGateway` with worker-scheduled renewals, PLATFORM §11.3)

```mermaid
sequenceDiagram
    autonumber
    actor O as Owner
    participant W as web
    participant F as FakeGateway / Stripe
    participant H as worker /webhooks/stripe
    participant Q as stripe-events queue
    O->>W: POST /settings/billing/checkout (starter, month)
    W->>F: create checkout session (trial_period_days 14, lookup_key sp_starter_month_usd)
    F-->>O: checkout page (fake: /dev/fake-checkout)
    O->>F: Pay
    F->>H: checkout.session.completed + customer.subscription.created (signed)
    H->>H: verify signature, dedupe event.id
    H->>Q: enqueue (jobId = event.id)
    Q->>W: POST /internal/v1/stripe/events
    W->>W: subscription trialing → active on invoice.paid, PlanLimits + Entitlements refreshed
    O->>W: POST /settings/billing/portal (cancel at period end)
    F->>H: customer.subscription.updated (cancel_at_period_end)
    H->>Q: enqueue
    Q->>W: POST stripe/events
    F->>H: invoice.payment_failed
    Q->>W: POST stripe/events, status past_due, banner
    F->>H: customer.subscription.deleted
    Q->>W: POST stripe/events, plan free, Telegram approvals gated
```

## 10. Sequence — evidence packet

```mermaid
sequenceDiagram
    autonumber
    participant W as web
    participant Q as BullMQ audit
    W->>W: decision (approved / rejected / paid), evidence entries appended under head lock
    W->>Q: outbox evidence.packet.requested (export_id)
    Q->>W: GET /internal/v1/scampause/exports/{id}/data
    W-->>Q: localized masked data + entries with canonical material and hashes
    Q->>Q: re-hash chain segment (shared vectors), render PDF with IBM Plex (Cyrillic)
    Q->>W: POST exports/{id}/file (pdf, sha256, chain_verified)
    W->>W: report_exports ready, signed download link
```

## 11. Cross-cutting decisions

| Concern | Decision |
|---|---|
| Tenancy | `workspace_id` on every domain row; global scope; internal API asserts workspace; only `domain_intel` (public facts) is global |
| Secrets & bank data | Laravel encrypted casts; keyed HMAC fingerprints for equality; masked everywhere by default; audited reveal (ADR-0002) |
| Idempotency | Outbox ids as BullMQ job ids; per-stage job ids include `revision`; web answers 409 `stale_revision` → worker no-op |
| Time | UTC instants, workspace timezone for business rules and reports, injected clock (ADR-0006) |
| Determinism | Rules engine pure functions with `rules_version` and `inputs_digest` (ADR-0001) |
| Observability | Request id propagated web → worker (jobs, bot, RAG); Prometheus metrics for pipeline stages, notifications, evidence checks |
| Stack | Laravel + Vue, Node/BullMQ (+ bot + TS RAG + OCR), Playwright; no Python (ADR-0010) |
| Privacy | Deletion = pseudonymize evidence-referenced users + ordered workspace purge; retention categories in the foundation purge job; data-subject redaction outside hash material (ADR-0011, DOMAIN_CONTRACT §12) |
| Callback numbers | Master data with four-eyes verification and cooldown (ADR-0012) |
| Untrusted text | E-mail subjects, vendor names, file names are escaped in Telegram/e-mail templates, wrapped as `untrusted_text` for the LLM, CSV-injection-neutralised in exports |
| Offline dev | Fake Stripe/YooKassa, fake Telegram transport + dev routes, mock LLM, fake DNS/RDAP (refused in production), hash embeddings |

## 12. ADR index

| ADR | Title | Status |
|---|---|---|
| [0001](adr/0001-rule-based-risk-engine.md) | Deterministic rule-based risk engine in web; LLM only as helper | Accepted |
| [0002](adr/0002-bank-data-encryption-and-fingerprints.md) | Bank data: application-level encryption, keyed fingerprints, decrypted data stays in web | Accepted |
| [0003](adr/0003-extraction-pipeline-bullmq-flow.md) | Extraction pipeline as a BullMQ flow in the worker; parsing, OCR and QR in-process | Accepted (revised 2026.10.2) |
| [0004](adr/0004-hash-chained-evidence-ledger.md) | Hash-chained evidence ledger next to the audit log | Accepted |
| [0005](adr/0005-telegram-approvals-asymmetric.md) | Telegram approvals: two-step confirm, risk-gated approve, reject always allowed | Accepted |
| [0006](adr/0006-deadlines-delayed-outbox-and-sweeper.md) | Approval deadlines and reminders via delayed outbox messages plus a sweeper | Accepted |
| [0007](adr/0007-multi-currency-base-amounts.md) | Multi-currency: rules on base-currency amounts, manual FX, missing rate = strictest | Accepted |
| [0008](adr/0008-bookkeeping-firms-multi-workspace.md) | Bookkeeping firms as members of client workspaces in MVP; Practice entity in v1 | Accepted |
| [0009](adr/0009-no-payment-blocking-risk-language.md) | No payment initiation/blocking; "risk, not safety" language policy | Accepted |
| [0010](adr/0010-node-only-stack-typescript-rag.md) | Strict stack: no Python service; OCR, extraction and RAG in the Node worker | Accepted |
| [0011](adr/0011-deletion-pseudonymization-retention.md) | Deletion by pseudonymization, ordered workspace purge, retention with ledger checkpoints | Accepted |
| [0012](adr/0012-callback-contacts-four-eyes.md) | Callback contacts as four-eyes master data with cooldown | Accepted |
