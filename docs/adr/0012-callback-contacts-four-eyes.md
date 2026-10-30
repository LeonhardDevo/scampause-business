# ADR-0012 — Callback contacts as four-eyes master data with cooldown

- Status: Accepted (2026.10.2)
- Related: DOMAIN_CONTRACT §1.2.4, §2.8, §2.11.2, §13.1; PLAN §9 (R14)

## Context
"Stop. Call. Confirm." is only as strong as the number that is called. In the first revision any user with `vendors.manage` (including
an external bookkeeper) could change a callback number with no second check. The classic BEC sequence — "our AR phone changed", then
"our bank changed" — would turn a confirmed call to the attacker into a verified bank account.

## Decision
- Callback contacts carry `status` (`pending_verification` → `verified` → `retired`). Only a different user with `vendors.verify_bank`
  verifies them; a phone change creates a new pending row and keeps the old verified one until retired.
- A contact changed within `callback_cooldown_days` (default 7) cannot satisfy a verification for a request with a bank-change signal
  (`CALLBACK_CONTACT_RECENTLY_CHANGED`); one number used for several vendors raises `CALLBACK_PHONE_SHARED`.
- Every change is an evidence event and notifies owners. The assistant never resolves a phone mismatch between a contract and vendor
  master — it warns and gives no number.

## Consequences
- Positive: closes the two-step BEC path; insider changes leave a tamper-evident trace.
- Negative: onboarding needs a second person to verify contacts (solo mode: the single owner verifies with an evidenced waiver).
