# ScamPause Business — Product & Production Plan

> Companion documents: `docs/DOMAIN_CONTRACT.md` (the binding build spec: data model, rules, routes, jobs, AI, bot, seed, E2E, work packages),
> `docs/ARCHITECTURE.md` (diagrams + ADR index), `design-system/scampause-business/MASTER.md` (brand & UI spec), `PLATFORM.md` (shared foundation, binding).
> Date: 2026-10-22 (revision 2, contract `2026.10.2`). Owner: product architecture.
> **Stack (PLATFORM §0.10, strict):** Laravel + Vue SaaS (`apps/web`); Node.js + BullMQ/Redis (`apps/worker`) for OCR (`tesseract` CLI), PDF/EML extraction, domain checks, bank-detail comparison jobs, approval fan-out & expiry, webhooks, audit packets — and the **Telegram bot with RAG implemented in TypeScript** (`apps/worker/src/rag`); Playwright (`e2e`). **No Python/FastAPI service.**
> **Own UI:** ScamPause has its own layout language, brand and landing (MASTER.md §0) — not a recolor of PayFlow Guard.

---

## 1. Vision

**ScamPause Business** is the "pause button" before a small business sends money.
An owner or bookkeeper forwards a suspicious invoice or e-mail (PDF, photo, `.eml`) or types the request in.
ScamPause extracts amount, payee, bank details and due date; compares them with the vendor's history; checks the sender and domain;
detects urgency and pressure; routes the payment to a **second approver**; asks for confirmation in **Telegram**; and keeps
**tamper-evident proof** of who verified the request, how (which official number was called), and who approved it, and when.

It never says "this payment is safe". It shows **risk signals** and enforces the bank-recommended habit
**"Stop. Call. Confirm."**: pause, call a number you already know (from the contract or vendor master, never from the message),
and confirm the request independently of e-mail.

One-liner (EN): *Stop. Call. Confirm. — before every payment that looks different.*
One-liner (RU): *Стоп. Позвони. Подтверди. — перед каждым платежом, который выглядит иначе.*

## 2. Positioning — why this is not "just another X"

| Alternative | What it does well | Why it does not solve this ICP's problem | ScamPause difference |
|---|---|---|---|
| E-mail security gateways (enterprise BEC filters) | Block malicious mail at the inbox for mid-market/enterprise | Priced and deployed for IT teams; miss look-alike vendors that pass SPF/DKIM; know nothing about the vendor's bank history; no approval workflow | Works on the *payment request itself* in the context of vendor master data and the company's own approval policy; no mail-flow change needed |
| AP automation / bill-pay platforms | Pay bills, sync accounting | Optimise *speed* of paying; verification of bank changes is limited to their own payee network or not explained; switching AP stack is a big decision for a 5-person company | Sits *in front of* whatever bank or AP tool the company already uses; optimises *pausing the right payments*; no money movement, no bank integration needed |
| Enterprise vendor-data validation platforms | Bank-account ownership validation at scale | Sold to treasury teams with ERP integration and long sales cycles | Self-serve, $29/month, setup in 15 minutes, bilingual RU/EN, Telegram-native approvals |
| Bank "payee name check" schemes (Confirmation of Payee in the UK, Verification of Payee in the EU) | Name–account match at the moment of payment | Only in some countries/rails; a fraudster can open an account with a similar name; no record of *who called whom*; nothing for urgent-CEO or payroll scams | Complements them: catches the change *before* anyone opens the banking app, and records the independent callback evidence |
| A written policy + shared spreadsheet | Free | Nobody reads it at 17:55 on Friday under "urgent" pressure; no proof; no second approver enforcement | Policy becomes executable rules + reminders + Telegram approvals + an assistant that answers "do I need a second approver for $4,000?" from *their* policy |

Defensible wedge: **evidence + workflow + vendor memory**, not a black-box AI verdict. Rules are deterministic and explainable;
the LLM is a helper (assistant over the company's own documents, optional extraction help) and can never mark anything verified.

## 3. Ideal customer profile, personas, jobs-to-be-done

**ICP (decided with the user):** micro-businesses with 1–30 staff that pay suppliers by bank transfer (wire/ACH/SEPA/RU bank transfer),
and the **small accounting/bookkeeping firms** that pay bills on their behalf. Bilingual RU/EN teams are first-class (Russian-speaking
owners abroad, CIS companies with English-speaking suppliers, international micro-companies).

| Persona | Context | Pains | Jobs-to-be-done |
|---|---|---|---|
| **Alex — owner-operator** (12-person coffee roaster) | Approves payments from the phone between meetings | "I can't check every invoice; I'm scared of the one e-mail that looks like my supplier" | *When a payment looks different, I want to be asked to approve it with the evidence in front of me, so I never pay a fraudster because I was in a hurry.* |
| **Мария — finance lead / office manager** | Prepares payments, talks to suppliers | Pressure from "the CEO", vendors changing details, no one to double-check | *When a vendor asks to change bank details, I want a clear procedure and the official phone number at hand, so I can verify in 5 minutes and prove I did.* |
| **Ирина — external bookkeeper** at a 4-person bookkeeping firm with 20 clients | Pays bills for many small clients from shared inboxes | Liability if a client loses money; clients approve over WhatsApp with no trail | *When I prepare a payment for a client, I want their approval recorded with time and method, so responsibility is clear and auditable.* |
| **Sam — second approver** | Ops manager, mostly on the floor | Approvals arrive as forwarded e-mails with no context | *When something needs my approval, I want one Telegram message with the risk and a two-tap decision, so I don't block the business.* |
| **Jordan — employee** | Salary paid by direct deposit | Fraudsters impersonate employees to redirect payroll | *When someone asks to change my salary account, I want to be told through a channel I trust.* |
| **Pat — auditor / outside accountant** | Year-end review, insurer questionnaire | No proof of controls | *I want an exportable, tamper-evident record of verifications and approvals.* |

## 4. Problem evidence

| Evidence | Source | Note |
|---|---|---|
| Banks recommend the **"Stop. Call. Confirm."** habit for requests to change payment instructions: stop, call the requester at a known number (not the one in the e-mail), confirm independently; main threats named: fake invoices, vendor impersonation, payroll fraud, business e-mail compromise | Banterra Bank business security guidance (named in the product brief), e.g. treasury newsletter: https://www.banterra.bank/uploads/userfiles/files/documents/Table%20Talk%20Newsletter/Q3_TM-Newsletter.pdf and https://www.banterra.bank/tools-resources/security-tips ; the same STOP-CALL-CONFIRM wording is used by Regions Bank: https://doingmoretoday.com/business-email-compromise-stop-call-and-confirm | Regions notes the confirming call "can take less than five minutes" — this is the core UX promise |
| BEC caused about **$2.77 billion** of reported losses in 2024 (21,442 complaints), the second-largest loss category in the FBI IC3 report | FBI IC3 2024 Internet Crime Report, via https://www.nacha.org/news/fbis-ic3-finds-almost-85-billion-lost-business-email-compromise-last-three-years and https://www.proofpoint.com/us/blog/email-and-cloud-threats/email-attacks-drive-record-cybercrime-losses-2024 | secondary summaries; confirm against ic3.gov before marketing use |
| **79%** of organisations faced attempted or actual payments fraud in 2024; BEC remained the top avenue (≈63%); vendor impersonation is the most common BEC tactic | 2025 AFP Payments Fraud and Control Survey, via https://www.nacmnc.org/news/701851/79-of-Organizations-Faced-Payment-Fraud-Attempts-in-2024.htm and https://truist.com/content/dam/truist-bank/us/en/documents/info/cci/2025-afp-payments-fraud-control-survey-report-key-highlights.pdf | secondary summaries disagree on the exact vendor-impersonation share (45% vs 60%); quote only "most common" until verified |
| Typical scenarios from the brief: "supplier" asks to change bank details; "manager" demands an urgent wire; fake invoice; employee salary account change; "new contract" paid by QR code | Product brief | each scenario is a seeded demo case and an E2E journey |

Accessed 2026-10-22. Marketing copy must cite primary sources only after verification (see risk R9).

## 5. Scope

Legend: **MVP** = production-grade, demoable, sold to design partners; **v1** = first public launch extensions; **v2** = scale.

### 5.1 MVP (this build — everything in DOMAIN_CONTRACT.md)

| Area | MVP scope |
|---|---|
| Intake | Web upload of PDF / PNG / JPG / WEBP / `.eml` (forwarded e-mail saved as file), up to 5 files; manual entry form; drag-and-drop; per-request reference `SP-00042` |
| Extraction | Worker pipeline (BullMQ flow): `.eml` parsing incl. `Authentication-Results`, PDF text layer (`pdfjs-dist`) + Tesseract CLI OCR (`rus+eng`, missing `rus` is flagged and forces verification) inside the worker, page previews for review, QR decoding (ST00012 RU, EPC/SEPA), deterministic field extraction (amount, currency, payee, IBAN/ABA/RU account with checksums, BIC, invoice no., dates, tax ids, e-mails, domains, phones); optional LLM assist for missing vendor name/amount only |
| Vendor master | Vendors & employees (payees), domains, **official callback contacts with source reference**, bank-account history with statuses (pending/verified/retired/rejected), block list |
| Risk engine | 35+ deterministic signals (bank change, country change, look-alike/homoglyph domains, reply-to mismatch, free mail, young domain, failed DMARC, display-name impersonation, urgency/secrecy/"don't call" language RU+EN, duplicates, amount anomalies, QR payee mismatch, payroll change, employee denial); score 0–100, levels, floors; explainable evidence; never "safe" |
| Verification | "Stop. Call. Confirm." form: only official numbers, checklist, outcome, who/when/how, immutable record, segregation of duties |
| Approvals | Rule engine (amount bands in base currency, kinds, risk level, new vendor, bank change → approvers count, required roles, callback requirement, deadline), per-member approval limits (per payment + monthly), solo mode for 1-person companies, deadlines with business hours/quiet hours/timezone, reminders, escalation, expiry, reopen |
| Telegram | Approval requests with two-step Approve and Reject-with-reason, reminders, escalations, payroll employee alert ("Was it you?"), `/pending`, `/request`, `/stop`, assistant Q&A with citations |
| Assistant (RAG) | Over policies, contracts, vendor register, procedures; domain tools (policy evaluation, official contacts, bank history, request status, my approvals); RU/EN; refusals; bot preview in web |
| Evidence & audit | Foundation audit log + hash-chained evidence ledger, nightly integrity check, PDF evidence packet per request, audit journal export (CSV/NDJSON/PDF) |
| Reports | Dashboard + reports: prevented amount, caught bank changes, verified legitimate changes, decision time, verification compliance, signals, by kind |
| Billing | Stripe subscriptions (fake driver offline), 4 plans with limits (§8), trial, portal, past-due handling; safety rule: limits never block verification/approval/rejection |
| Platform | RU+EN everywhere, light+dark, accessibility baseline, no CDN, offline with fake drivers (Stripe, Telegram, LLM, DNS/RDAP) |

### 5.2 v1 (next 2–3 months after MVP)

| Area | v1 scope |
|---|---|
| Intake | Inbound forwarding address per workspace (`check+<token>@in.scampause.app`) via an inbound-mail provider webhook → worker `/webhooks/inbound-email` → same pipeline; forward a document/photo to the Telegram bot; Gmail/Outlook add-on "Check with ScamPause" |
| Bookkeeping firms | "Practice" entity grouping client workspaces: cross-client queue, consolidated billing (per-client seat pricing), practice-level templates for rules and procedures |
| Vendor master | CSV import/export, vendor self-service "confirm your bank details" page with code delivered through the official channel (never via the requesting e-mail) |
| Risk | Per-workspace weight tuning with a test bench; holiday calendars (RU/US/EU) for business days; more QR formats (Swiss QR-bill); attachment malware scan (ClamAV) |
| Reports | Scheduled weekly digest e-mail/Telegram; insurer-ready "controls statement" PDF |
| Platform | SSO for Business plan (OIDC), WebAuthn-only approval option, audit log streaming (webhook/S3) |

### 5.3 v2

| Area | v2 scope |
|---|---|
| Network intelligence | Opt-in cross-tenant fraud signal sharing: global keyed fingerprints of *confirmed fraud* accounts/domains with k-anonymity thresholds and legal review |
| Bank integrations | Read-only bank feeds to auto-detect "paid without verification"; **payment holds/blocking only after legal review and bank partnership** (explicitly out of scope until then) |
| Accounting integrations | QuickBooks / Xero / 1C vendor master sync |
| API | Public API + webhooks for AP tools |
| Payee verification | Integrations with name-check services where available (UK CoP, EU VoP via partners) as additional signals |

## 6. Success metrics

| Metric | Definition | MVP target (first 90 days with design partners) |
|---|---|---|
| Activation | Workspace runs its first check within 24 h of sign-up | ≥ 60% |
| Setup completeness | ≥ 5 vendors with an official callback contact within 7 days | ≥ 40% of activated |
| Weekly active workspaces | ≥ 1 check or approval per week | ≥ 50% of paid |
| Verification compliance | Approved requests that required verification and had one | **100%** (system invariant; < 100% = P1 bug) |
| Time to decision | Median `submitted_at → decided_at` | < 4 business hours |
| Telegram decision share | Low/medium-risk approvals decided in Telegram | ≥ 50% |
| Expiry rate | Routes expiring without decision | < 5% |
| Prevented amount | Σ prevented (fraud) per month across customers — anonymised aggregates over ≥ 20 workspaces only, as allowed by the Terms (DOMAIN_CONTRACT §12.1) | tracked; showcase metric |
| False-alarm burden | High/critical requests later verified legitimate ÷ all high/critical | < 60% and trending down (tune weights) |
| Assistant quality | Answered with citation ÷ asked (excluding off-topic) | ≥ 85%; refusal correctness on golden set 100% |
| Conversion | Free → paid within 30 days | ≥ 8% |
| Logo churn | Monthly | < 3% |

## 7. Milestones

| Milestone | Content | Exit criteria |
|---|---|---|
| **M0 — Foundation** (cloned from PayFlow Guard) | Tenancy, roles, invitations, billing fake/stripe, outbox, internal API, Telegram linking (BotModule registry, StoredAction keyboards), notifications feed, KB upload + **TypeScript RAG in the worker** (PLATFORM §0.10 port of the foundation RAG), assistant/bot preview, AI provider admin, audit log, marketing shell; F2 privacy (consents, export/deletion, retention) and F3 payments (Stripe + YooKassa readiness) as they land | Foundation DoD (FOUNDATION_CONTRACT §5); no `apps/ai` in this repo |
| **M1 — Domain core** | Migrations/models (§1), vendor master UI, manual intake, risk engine v1 (non-pipeline signals), policy evaluator + simulator, approvals in web, evidence ledger, Pest suites | A manual request flows review → verification → 2 approvals → paid with evidence; policy simulator answers "$4,000 → 2 approvers" |
| **M2 — Intake pipeline** | Worker extraction (PDF/OCR/QR/eml) with the in-process parser and abuse limits, page previews, domain intel (fake in dev only / live / cache-only), bank comparison, scoring flow with failure fallbacks and sweeper, case-file UI with diff/sender panels, fixtures generator | J1, J2, J2b green |
| **M3 — Notifications & Telegram** | Approval dispatch, two-step Telegram approve/reject, reminders/escalation/expiry/sweeper, payroll employee alert, e-mail templates, in-app notifications | J3, J4, J4b, J5, J9 green |
| **M4 — Assistant** | Domain tools, mock intents, prompts, output guards + PII placeholders, locale de-dup, seeded documents — all in the worker TS RAG | J8 green; golden set 100% |
| **M5 — Reports, evidence & privacy** | Dashboard, reports, evidence packets (PDF), journals (CSV-injection safe), chain verification, retention categories, export provider, data-subject tool, pseudonymization | J6 green; deletion/export/redaction Pest suites green |
| **M6 — Brand, landing, billing limits** | brand.css, fonts, logo, landing/pricing/security/legal (RU/EN, light/dark), plan limits & upgrade prompts | J7, J10 green; Lighthouse ≥ 90 on landing |
| **M7 — Production hardening & launch** | Security review, load test (1k requests/day/workspace), backups/restore drill, observability dashboards, legal pages reviewed, design-partner onboarding (5–10 companies + 2 bookkeeping firms) | Production DoD (§11) |

## 8. Pricing & payments (maps 1:1 to `config/billing.php`, DOMAIN_CONTRACT §2.16 and §14)

ScamPause bills **only SaaS subscriptions** — it never collects or moves its customers' payments. **Stripe** is the primary, fully implemented gateway (Checkout, Customer Portal, proration, dunning, 14-day trial via `trial_period_days`); **YooKassa** is the RUB/RU-market driver (saved payment method + worker-scheduled renewals, 54-ФЗ receipts) behind the same `BillingGateway` interface; the `fake` driver runs dev/CI/E2E. Market (country → currency → gateway) is chosen at onboarding; the pricing page shows the visitor's market currency.

| | **Free** | **Starter** | **Pro** (highlighted) | **Business** |
|---|---|---|---|---|
| USD mo · yr | $0 | $29 · $290 | $79 · $790 | $199 · $1,990 |
| EUR mo · yr | €0 | €27 · €270 | €75 · €750 | €189 · €1,890 |
| RUB mo · yr | 0 ₽ | 2 490 ₽ · 24 900 ₽ | 6 790 ₽ · 67 900 ₽ | 16 990 ₽ · 169 900 ₽ |
| `seats` | 2 | 5 | 15 | 50 |
| `payment_requests_monthly` | 15 | 150 | 600 | 3,000 |
| `vendors` | 25 | 150 | 1,000 | unlimited (`null`) |
| `documents` (knowledge base) | 3 | 20 | 100 | 500 |
| `ai_queries_monthly` | 50 | 300 | 1,500 | 5,000 |
| `approval_rules` (custom, active) | 2 | 6 | 25 | unlimited |
| entitlement `telegram_approvals` | Reject + notifications only | yes | yes | yes |
| entitlement `evidence_exports` (PDF packets, journals) | – | yes | yes | yes |
| entitlement `domain_intel_live` (live DNS/RDAP) | cache only | cache only | yes | yes |
| entitlement `file_retention_days` | 90 | 730 | 2,555 (7 years) | 2,555 |
| Trial | — | 14 days | 14 days | 14 days |

Plan keys: `free`, `starter`, `pro`, `business` (foundation `Plan['key']`); foundation limit keys are kept (`seats`, `documents`, `ai_queries_monthly`, `storage_mb`), flags live in a separate `entitlements` block. Stripe `lookup_key` = `sp_<plan>_<interval>_<cur>`. Bookkeeping-firm pricing (per-client discount) is a v1 "Practice" add-on.
**Safety rule:** no plan limit or billing state ever blocks verification, approval, rejection, cancellation, evidence viewing or the Stop-Call-Confirm guidance.

## 9. Risk register

| # | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| R1 | **Liability**: a customer pays a fraudster after ScamPause showed "few signals" | Medium | High | Product invariant: never "safe"; every badge/tooltip/e-mail carries the disclaimer; verification is required by policy for all bank changes regardless of score; ToS limitation of liability and "decision-support only" clause; evidence trail shows the customer's own decisions; professional liability insurance before paid launch |
| R2 | False negatives: novel scams without our signals | Medium | High | Bank-change and payroll-change always require callback (independent of score); new-vendor first payment above threshold requires callback; weekly review of rejected cases to add signals; v1 weight tuning |
| R3 | False positives → alert fatigue → users click through | High | Medium | Floors only on strong signals; content points capped at 40; Telegram one-tap for low/medium risk; metric "false-alarm burden"; explain *why* each signal fired with advice |
| R4 | Leakage of bank data | Low | High | Encrypted columns, keyed fingerprints, masked UI by default, audited reveal with rate limit, no full numbers in Telegram/e-mail/LLM/logs (tests), signed short-lived downloads, private disk encrypted at rest |
| R5 | Telegram account takeover used to approve | Low | High | Two-step confirm with single-use nonce; approve-in-Telegram only for low/medium risk without bank changes; reject always possible; WebAuthn-only option in v1 |
| R6 | Insider fraud (requester approves own payment) | Medium | High | SoD enforced in services (requester ≠ approver ≠ submitter; verifier ≤ 1 of 2 approvers; adder ≠ verifier for master data); solo mode explicit and evidenced |
| R7 | Long B2B sales cycle | High | Medium | Self-serve free plan, 15-minute setup, bookkeeping firms as channel (one firm → 20 clients), content marketing around "Stop. Call. Confirm." |
| R8 | LLM hallucinates policy or numbers | Medium | Medium | Policy answers come from the deterministic evaluator; vendor facts from tools; citations required; confidence gate; output guards redact account-like numbers and forbidden copy; golden set in CI |
| R9 | Marketing claims based on unverified statistics | Medium | Medium | Cite primary sources only (IC3, AFP report) after verification; keep claims qualitative until then |
| R10 | OCR/extraction errors cause wrong comparisons | Medium | Medium | Checksums (IBAN/ABA/RU key) flag `INVALID_ACCOUNT_CHECKSUM`; human review step before submit; manual override recorded; fixtures with noisy OCR in tests |
| R11 | Data residency (RU personal data localisation) | Medium | High | Single-region deployments (`DATA_REGION=ru` documented); RU-hosted instance offered **on request** until §12 Q1 is decided — marketing says only "Data residency options on request"; no cross-region replication; documented in DPA |
| R14 | Callback-number poisoning (attacker first changes the "official" phone) | Medium | High | Callback contacts need four-eyes verification, cooldown before they can satisfy bank-change verifications, owner alerts on every change, shared-number signal (DOMAIN_CONTRACT §13.1) |
| R15 | Insider relaxes controls before a fraudulent payment | Low | High | Rule/limit/settings/role changes are evidence events with owner alerts; new approvers wait 24 h before assignment; decision-time eligibility re-check |
| R12 | Third-party outages (Telegram, RDAP, LLM) | Medium | Low | E-mail fallback for approvals; domain intel optional with `DOMAIN_INTEL_UNAVAILABLE` info signal; mock/fallback LLM; queues with retries |
| R13 | Evidence tampering accusations | Low | High | Hash chain with nightly verification, immutable DB triggers, evidence packets embed hashes, admin cannot edit history |

## 10. Legal & privacy (PLATFORM §10; binding detail in DOMAIN_CONTRACT §1.5 and §12)

- **No safety guarantee.** ScamPause is decision support. Terms: no warranty that payments are legitimate; customers remain responsible for payment decisions; ScamPause never initiates, holds or blocks payments (no payment-services licensing needed in MVP). Automatic payment blocking would require bank integration and legal review (v2, explicitly excluded). Copy never says a payment is "on hold" or "stopped".
- **Copy rules.** Forbidden affirmative words in all channels (DOMAIN_CONTRACT §0, §2.18), incl. "safe payment", "guaranteed", "on hold", "before it happens", «безопасный платёж», «гарантируем», «на удержании». Approved wording: "risk signals", "independent verification", "approved by your team", "rejected by your team (prevented risk)".
- **Product-specific legal documents (RU + EN, Markdown in `resources/legal/{en,ru}/`, lawyer-review banner):** `risk-disclaimer`, `data-subject-notice` (employees, vendor contacts, e-mail senders), `dpia-template`, `dpa-annex`, plus domain clauses in `terms` (decision support only; aggregated-statistics clause) and `privacy` (processor role, 152-ФЗ «поручение на обработку», Telegram cross-border transfer for RU deployments, RDAP/DNS lookups). Owner: brand-landing (texts), web-domain-backend (features).
- **DPA:** click-wrap accepted by **every** workspace owner at workspace creation, on every plan (correction: it is not a Business-only document).
- **Roles & lawful basis.** Customer = controller, ScamPause = processor. Personal data: employees' names/phones/salary accounts, vendor contacts, e-mail contents, offence-related `fraud_confirmed` labels (DPIA template provided). GDPR/UK GDPR: legitimate interest in fraud prevention (customer). Russia (152-FZ): RU-hosted deployment on request; consent-ru at registration.
- **Domain-data coverage.** Export: `DomainExportProvider` adds every domain table to the F2 workspace export and the user's "download my data". Deletion: users referenced by evidence are pseudonymized (explicit override of PLATFORM §10.2 hard delete), workspaces purged through `WorkspacePurger` after the F2 grace period. Data-subject requests from vendor contacts/employees/senders: owner tool to find, export or redact (redaction keeps the evidence chain valid).
- **Retention.** Categories registered in the foundation `config/retention.php`: files per plan (purge also clears extracted text and e-mail recipients/subjects), evidence 7 years (min 5) with ledger checkpoints, exports 30 days, notification records 365 days, global domain facts 90 days. Shown in Settings → Privacy and in the privacy policy.
- **PII redaction.** Bank numbers masked everywhere except audited reveal; phones as last4 in evidence/notifications and as placeholders towards LLMs; log redaction in web and worker; no full numbers in Telegram, e-mail, prompts, exports without reveal permission.
- **AI.** Extract-assist off by default per workspace; external LLMs only when the workspace enables "Allow external LLM providers" — providers themselves are chosen by the platform admin, never by customers.
- **Sub-processors:** hosting, e-mail delivery, Stripe / YooKassa (billing data only), Telegram (only if linked; masked content), RDAP + DNS resolver (only with live domain intel), LLM providers (only when the workspace allows external providers; redacted input).
- **Notices.** Risk disclaimer on case file, packets, e-mails, bot; full footer link set (Terms, Privacy, Cookies, DPA, Sub-processors, AUP, AI notice, Security, Contact) and a non-blocking strictly-necessary cookie notice; Telegram `/privacy` domain paragraph; payroll security alerts carry sender identity and privacy link but **no opt-out link** (documented override: an opt-out would let a fraudster silence the alert).
- **Security commitments:** TLS 1.2+, encrypted disk/bucket **plus application-level AES-256-GCM encryption of uploaded files** and encrypted bank columns, role-based access, audit log, evidence ledger, encrypted daily backups (30-day retention), incident response with 72-hour notification, annual penetration test before Business launch; SOC 2 Type I targeted in year 2 (not claimed before).
- **Owners.** brand-landing — legal texts, footer, cookie notice; web-domain-backend — export provider, deletion/pseudonymization, retention categories, data-subject tool, `config/legal.php`; web-domain-ui — disclaimer placements and privacy UI; worker-domain — log redaction, bot privacy text; rag-domain — PII placeholders and output guards.
- **Accessibility.** WCAG 2.1 AA baseline (PLATFORM §0.5).

## 11. Definition of done — production

1. All DOMAIN_CONTRACT work packages meet their DoD (§11.4 there); `npm run test` (Pest, Vitest) and `npm run e2e` (J1–J10 in `en-light` + `ru-dark`, plus `en-dark`/`ru-light` for J1/J10) green in CI; axe: no serious/critical violations.
2. No request leaves localhost in E2E (network guard); CSP `default-src 'self'` enforced in production; no CDN references (grep check in CI).
3. Security: dependency audit clean (composer audit, npm audit --production), secrets only via env/admin UI, log redaction tests, rate limits on login/internal/bot/reveal, signed URLs, upload sniffing, DB triggers for immutability tested, backup + restore drill documented.
4. Observability: JSON logs with request id across web/worker; Prometheus metrics (queue depth, pipeline duration, notification delivery, evidence verification); alerts for failed jobs > 1%, evidence chain broken, outbox lag > 60 s.
5. Performance: case file p95 < 400 ms server time with 10k requests/workspace; pipeline p95 < 30 s for a 3-page PDF with OCR; dashboard p95 < 600 ms.
6. Product invariants verified by tests: verification compliance 100%, SoD rules (incl. callback-contact four-eyes and decision-time eligibility), forbidden copy, no full account numbers in notifications/LLM, no emoji/stickers anywhere (PLATFORM §0.8), workspace purge leaves 0 domain rows/files, pseudonymized deletion keeps the evidence chain valid.
7. RU/EN complete (no missing keys — CI check), RU plurals tested, light/dark screenshots reviewed at 375/768/1440.
8. Legal pages (foundation set + risk disclaimer, data-subject notice, DPA annex, DPIA template) published in RU/EN with the no-guarantee clause; pricing page matches `config/billing.php` per currency (test); Stripe path tested against `stripe-mock`, YooKassa path against recorded fakes.
9. Runbooks: Stripe webhook replay, Telegram webhook reset, re-embed, key rotation (`APP_KEY`, `SCAMPAUSE_FINGERPRINT_KEY`), evidence chain incident.
10. Demo: `npm run setup` seeds both demo workspaces; every screen has meaningful content in both languages.

## 12. Open questions (need the user)

1. Primary launch geography and hosting: EU/US single region first, or an RU-hosted instance from day one (152-FZ)? This changes the deployment plan, not the code. Until decided, marketing claims only "Data residency options on request".
2. Pricing for bookkeeping firms in MVP: per-client workspace on regular plans (current plan) vs an early "Practice" bundle.
3. Brand/legal name and domain for the product (used in e-mails, Telegram bot username, inbound address in v1).
