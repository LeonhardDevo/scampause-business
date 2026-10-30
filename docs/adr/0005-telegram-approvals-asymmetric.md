# ADR-0005 — Telegram approvals: two-step confirm, risk-gated approve, reject always allowed

- Status: Accepted (2026-10-22), revised 2026.10.2
- Related: DOMAIN_CONTRACT §2.12.3, §8; PLAN §9 (R5)

## Context
Approvers in micro-businesses live in Telegram; one-tap approvals drive adoption. But a stolen phone or hijacked Telegram session
could approve a fraudulent payment, and the approver must see the evidence for risky requests.

## Decision
- Buttons use the foundation StoredAction path (`a:<id>` in Telegram, action `sp.apv` / `sp.apv_confirm` / `sp.rej` / … in the
  `scampause` BotModule, installed before the assistant catch-all). Each action carries a web-issued step token (sha256 stored, rotated
  per step). Every callback is validated by web: linked user = assignee, route active, step token valid, and the approver is **still
  eligible at decision time** (membership, role, approve permission, per-payment and monthly limits under an advisory lock).
- Web sends `template` + raw params; the worker renders with HTML escaping on every param, so attacker-written subjects or vendor names
  cannot inject links; URL buttons must pass `isPublicHttpUrl`.
- **Approve** is two-step (Approve → Confirm) and allowed only when the workspace enables it, the plan entitlement includes it, the risk level is at
  or below `telegram_approve_max_risk` (default `medium`) and there is no signal from the named `web_only_signals` set. Otherwise the button is not shown and forged
  callbacks are answered with "approve in the web app".
- **Reject** and **Open** are always available in Telegram on every plan (the fail-safe direction), with a reason keyboard.
- Domain handlers answer only in private chats; members of several workspaces switch with `/workspace`, and every message names the workspace.
- After any decision or expiry, web edits every approver's message to remove stale buttons.
- Notifications contain only masked data; links require login.

## Consequences
- Positive: fast decisions for routine payments; risky ones force the full case-file review.
- Negative: approvers of high-risk requests must open the web app (intended friction).
- Negative: depends on Telegram availability — e-mail fallback exists for requests and reminders.

## Alternatives considered
- Approve everything in Telegram — rejected (account takeover risk).
- No Telegram approvals — rejected (adoption).
- One-time codes shown in Telegram and typed in web — deferred; a WebAuthn-only approval option is planned for v1.
