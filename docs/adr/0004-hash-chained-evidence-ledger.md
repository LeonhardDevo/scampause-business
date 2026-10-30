# ADR-0004 — Hash-chained evidence ledger next to the audit log

- Status: Accepted (2026-10-22)
- Related: DOMAIN_CONTRACT §1.2.21, §2.15; PLAN §9 (R13)

## Context
The product promise is to "keep proof: who, when and how confirmed the payment". The foundation `audit_logs` table is append-only by
convention, but anyone with database access could still alter history, and auditors/insurers need a way to check integrity outside our UI
(exported packets).

## Decision
- Add `evidence_entries` with a per-workspace gap-free `seq`, `prev_hash`, `hash = sha256(prev_hash + "\n" + canonical_json(material))`,
  serialized through `evidence_heads` row locks inside the business transaction.
- Canonical JSON: sorted keys, unescaped UTF-8, integers only, microsecond UTC timestamps; shared test vectors in PHP and TypeScript.
- DB triggers forbid UPDATE/DELETE on `evidence_entries` and `verifications` (except the guarded workspace purge).
- Nightly `audit.chain-verify` job; on-demand "Verify integrity" in Reports; evidence packets (PDF) embed the hashes and are re-verified by
  the worker before rendering.
- `audit_logs` remains the general activity log (IP, user agent, before/after); evidence entries carry only masked, decision-relevant payloads.

## Consequences
- Positive: tampering becomes detectable; packets are independently verifiable with the documented algorithm.
- Negative: a single writer lock per workspace serializes evidence writes (acceptable for micro-business volumes; covered by load tests).
- Negative: no external timestamping/notarization yet (v2 option: periodic anchoring of head hashes to an external timestamp authority).

## Alternatives considered
- Rely on `audit_logs` only — rejected: no integrity proof.
- External ledger or blockchain — rejected: cost, complexity, privacy.
