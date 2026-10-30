# ADR-0002 — Bank data: application-level encryption, keyed fingerprints, decrypted data stays in web

- Status: Accepted (2026-10-22), revised 2026.10.2
- Related: DOMAIN_CONTRACT §1.4, §2.2, §2.5; PLAN §10

## Context
We store vendors' and employees' bank accounts, account holders, phone numbers and full e-mail texts. We must compare accounts
(has the account changed? is it on the block list? does another vendor use it?) without exposing plaintext broadly, and we must never
leak full numbers through Telegram, e-mail, LLM prompts or logs.

## Decision
- All bank numbers, holder names, employee phones, extracted texts, verification notes and comments use Laravel `encrypted` casts
  (AES-256-CBC, `APP_KEY`, rotation via `APP_PREVIOUS_KEYS`).
- Equality search uses `HMAC-SHA256(SCAMPAUSE_FINGERPRINT_KEY, "{workspace_id}:" + body)` fingerprints (body `{scheme}:{routing}:{account}` or `phone::{e164}`, vectors in DOMAIN_CONTRACT §2.2) — salted per workspace,
  so fingerprints cannot be correlated across tenants. `last4` is stored in plain text for display.
- Decryption happens **only in web**. The worker's "bank compare" stage calls a web endpoint that performs the comparison. The worker sees
  account numbers only in the extraction direction (from documents it parsed itself) and posts them straight to web without storing them.
- UI shows masked values by default; full values require `bank_details.reveal`, are rate-limited (10/min) and audited (`bank_account.revealed`).
- **Uploaded files** (PDF, .eml, images, previews) and exports are encrypted before they touch the disk: AES-256-GCM via `ext-openssl`, per-file nonce, `SCAMPAUSE_FILE_KEY` with a key-id prefix for rotation; decryption only in the download stream and the signed internal file endpoint.
- Notifications, evidence payloads, LLM inputs (redaction + placeholders) and logs carry only masked data. Tests assert that **none of the fixture generator's full account numbers** (or routing+account pairs) appear in packets, logs, bot answers or evidence — not a blanket "no 8+ digit runs" rule, which would fail on sha256 hashes and on RU public identifiers (INN/BIK/KPP/OGRN).

## Consequences
- Positive: database dumps, backups and raw disk contents alone do not reveal bank data (Pest: raw bytes of a stored fixture do not contain its IBAN); tenant isolation extends to fingerprints.
- Positive: key rotation is possible (`php artisan scampause:rehash-fingerprints`).
- Negative: encrypted columns cannot be searched or indexed; analytics on bank data are limited to fingerprints, last4 and country.
- Negative: cross-tenant fraud intelligence needs a separate, opt-in global fingerprint scheme (v2, legal review).

## Alternatives considered
- Postgres `pgcrypto` column encryption — rejected: keys would travel in SQL, harder rotation, less portable.
- A tokenization vault service — rejected for MVP complexity; it can replace the cast later behind the same model API.
