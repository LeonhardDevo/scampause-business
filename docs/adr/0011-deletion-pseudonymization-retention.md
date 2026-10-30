# ADR-0011 — Deletion by pseudonymization, ordered workspace purge, retention with ledger checkpoints

- Status: Accepted (2026.10.2)
- Related: PLATFORM §10.2; DOMAIN_CONTRACT §1.5, §12.4, §12.5; ADR-0004

## Context
PLATFORM §10.2 asks for hard deletion of accounts and a 30-day workspace purge. The foundation implements immediate deletes relying on
`ON DELETE CASCADE`. ScamPause keeps immutable verifications and a hash-chained evidence ledger whose material contains `actor_user_id`,
and NOT NULL foreign keys to users. A naive cascade or user delete fails (triggers, RESTRICT keys) or breaks the chain.

## Decision
- **Overrides PLATFORM §10.2 (hard delete of accounts):** users referenced by any domain row are pseudonymized (name, e-mail,
  credentials, 2FA, passkeys, Telegram link, memberships removed), never deleted; unreferenced users follow the foundation hard delete.
- Intra-workspace foreign keys are `NO ACTION`; a domain `WorkspacePurger` deletes child-first inside the deletion transaction with
  `SET LOCAL scampause.allow_evidence_purge = 'on'`, then removes encrypted files after commit. Foundation extension: `WorkspaceDeleting`
  and `UserDeleting` events.
- Retention categories (files, evidence, exports, notification records, global domain facts) are registered with the foundation
  purge job. Evidence is purged by `seq` range only after the retention period (default 7 years, minimum 5) and a `ledger.checkpoint`
  entry keeps verification meaningful.
- Data-subject redaction nulls only encrypted PII columns that are **not** hash material, under a separate GUC
  `scampause.allow_pii_redaction`, so the chain stays valid.

## Consequences
- Positive: account and workspace deletion work against the real foundation code; evidence remains provable.
- Positive: retention is visible in Settings → Privacy and the privacy policy.
- Negative: a pseudonymized "Former member #id" remains in evidence — documented in the privacy policy as a legal-obligation basis.

## Alternatives considered
- `SET NULL` on user keys — rejected (changes hashed material).
- Keeping evidence forever — rejected (data minimisation).
