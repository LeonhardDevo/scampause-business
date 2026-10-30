# ADR-0007 — Multi-currency: rules on base-currency amounts, manual FX, missing rate = strictest

- Status: Accepted (2026-10-22)
- Related: DOMAIN_CONTRACT §2.9, §2.10

## Context
Micro-businesses pay suppliers in several currencies (a USD company paying EUR importers; a RUB company paying in USD). Approval thresholds
and member limits are defined in the workspace currency. Live FX feeds are external dependencies and must not be required offline.

## Decision
- Money is always `*_minor bigint + currency`; no floats. Each request stores `amount_base_minor`, `fx_rate`, `fx_rate_date`.
- Rates come from the workspace `exchange_rates` table (manual entry, seeded defaults), valid up to 31 days before the invoice date.
- **Missing rate ⇒ the amount is treated as +∞ for rules** (strictest approvals, only unlimited approvers) plus an info signal `FX_RATE_MISSING`.
- Conversion uses integer/decimal-string arithmetic with banker's rounding.

## Consequences
- Positive: deterministic, offline, never under-protects when data is missing.
- Negative: admins maintain rates manually (v1: optional rate provider with caching).

## Alternatives considered
- Live FX API — rejected for MVP (external dependency, offline requirement).
- Thresholds per currency — rejected: multiplies rule configuration for tiny teams.
