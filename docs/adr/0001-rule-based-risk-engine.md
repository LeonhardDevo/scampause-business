# ADR-0001 — Deterministic rule-based risk engine in web; LLM only as helper

- Status: Accepted (2026-10-22)
- Deciders: product architecture
- Related: DOMAIN_CONTRACT §2.8, §2.10, §7; PLAN §9 (R1, R3, R8)

## Context
The product must flag fake invoices, vendor impersonation, payroll fraud and BEC for micro-businesses, and its output is used to justify
(or refuse) real payments. The brief is explicit: "high responsibility", "quality verification rules are needed, not only an LLM".
Customers, auditors and insurers must be able to understand *why* something was flagged, and the same input must always produce the same result.

## Decision
- Risk is computed by `App\Domain\Risk\RiskEngine` in the **web** service as a pure function of the request, vendor master data,
  bank comparison, sender facts and lexicon, versioned by `rules_version` (`2026.10.2`) and `lexicon_version`.
- Score = capped sum of explicit signal weights (content capped at 40) with floors for strong signals; four levels; every signal stores
  masked evidence and a localized explanation + advice. `inputs_digest` makes every assessment reproducible.
- Verification requirements and approval routing are also deterministic (PolicyEvaluator, RouteBuilder), and bank/payroll changes always
  require a callback regardless of score.
- The LLM is used only for: (1) the assistant over the customer's own documents with tools, (2) optional extraction assist for missing
  vendor name/amount. LLM output can never lower a score, remove a signal, satisfy a verification or approve anything.

## Consequences
- Positive: explainable, testable (table-driven Pest tests per signal), auditable, works offline with the mock provider.
- Positive: business rules stay in one service (PLATFORM §3); worker and ai cannot drift.
- Negative: novel scam patterns need new rules (mitigated by mandatory callbacks for bank changes and a weekly review of rejected cases).
- Negative: weights need tuning per customer segment (v1: per-workspace tuning with a test bench).

## Alternatives considered
- LLM classifier as the primary verdict — rejected: non-deterministic, hard to explain, prompt-injectable through the very e-mails we analyse.
- ML model trained on fraud data — rejected for MVP: no labelled data yet; revisit with opt-in network signals in v2.
