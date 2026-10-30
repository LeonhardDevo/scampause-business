# ADR-0006 — Approval deadlines and reminders via delayed outbox messages plus a sweeper

- Status: Accepted (2026-10-22)
- Related: DOMAIN_CONTRACT §2.13, §5; PLATFORM §4.2

## Context
Approvals expire, reminders repeat and escalations fire; all must respect the workspace timezone, business hours and quiet hours, survive
restarts, and never double-send.

## Decision
- When a route is created, web computes all instants (reminders, escalation, expiry) with `ApprovalSchedule` (UTC arithmetic, wall-clock
  rules in the workspace timezone, DST-safe) and publishes **delayed outbox messages** (`available_at`). The worker relay only claims
  messages whose `available_at` has passed; jobs call web, which re-checks state (already decided → `send:false`).
- A repeatable `approvals.sweep` job every 5 minutes expires overdue routes as a safety net.
- Expiry is a hard deadline (not shifted by quiet hours); reminders and escalations are shifted out of quiet hours.
- All rules use an injected clock; dev-only endpoints (`expire-now`, `remind-now`) let E2E tests run without waiting.

## Consequences
- Positive: no in-memory timers, idempotent by outbox id, survives worker restarts, testable with a frozen clock.
- Negative: changing settings does not reschedule already-published reminders (they re-check state and settings when they fire; acceptable).

## Alternatives considered
- BullMQ delayed jobs created directly by web — rejected: web never talks to Redis (PLATFORM §3).
- A cron scanning every minute for due reminders — rejected: more DB load, less precise, harder idempotency.
