# Approvals Page Overrides (`approvals/Index`, approval dialogs everywhere)

> PROJECT: ScamPause Business · Page spec, 2026-10-22. Overrides `../MASTER.md` for this page.

## Layout
- Tabs: Pending (default, count badge) / Decided. Cards rather than a table (decisions need context): reference, vendor, amount, risk badge, verification status ("Confirmed by phone — Irina, 22 h ago"), deadline countdown, channel availability ("Telegram approval available" or "Web only: bank details changed").
- Cards sorted by deadline ascending; items expiring in < 12h get a warning edge, < 2h destructive.

## Decision dialogs
- Approve dialog: masked payee + bank/country, amount, top 3 signals, verification summary, policy line ("2 of 2 approvals required · owner required"), optional comment; confirm button "Approve payment request" / «Согласовать запрос».
- Reject dialog: reason radio list (Fraud confirmed / Details mismatch / Not ordered / Duplicate / Policy violation / Other), comment required for Other; destructive confirm.
- High/critical risk or any web-only signal (DOMAIN_CONTRACT §2.10.1 `web_only_signals`): the card shows **"Open case file"** instead of Approve; inline Approve is offered only for low/medium (Reject stays inline everywhere).
- Keyboard: `a` / `r` shortcuts only inside the dialog, with visible hints; focus returns to the next card after a decision.

## Avoid
- Bulk approve; approve buttons directly on list rows without a dialog; hiding the risk explanation behind extra clicks.
