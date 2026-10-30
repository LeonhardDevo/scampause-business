# Payment Request Case File Overrides (`payment-requests/Show`)

> PROJECT: ScamPause Business · Page spec, 2026-10-22. Overrides `../MASTER.md` for this page.

## Layout
- Header strip (sticky under the app header on ≥ 1024px): reference (mono) · title · status chip · `RiskGauge` (96px) · amount (kpi type) · vendor · single primary action for the current state
  (Submit / Record verification / Approve + Reject / Mark paid / Reopen).
- Tabs: Overview · Bank details · Sender · Verification · Approvals · Timeline · Files. Deep-linkable via `?tab=`.
- Right rail on ≥ 1280px (320px): "What to do next" — Stop-Call-Confirm checklist, the official callback contact (copy button), and the policy preview (approvers required).
- While `received/processing`: `PipelineStepper` replaces the tabs with skeleton exhibits beneath; `aria-live` announces stage changes.

## Exhibit cards
- Eyebrows: EXHIBIT A — SENDER, EXHIBIT B — BANK DETAILS, EXHIBIT C — CALLBACK, EXHIBIT D — APPROVALS, EXHIBIT E — EVIDENCE (RU: ПРИЛОЖЕНИЕ А — ОТПРАВИТЕЛЬ, …).
- Overview: signals grouped by category, each with severity bars, points, explanation and advice; floor reason shown when applied.
- Bank details: `BankDiffTable` with masked values; "Reveal" button (only with permission) opens a dialog with the char-level diff and a note that the reveal is logged.
- Sender: domains table with look-alike technique, auth chips, domain age, free-mail tag; `DoNotUseChip` list of phones/e-mails from the message. Domain age shows only this workspace's first check time; on plans without live checks a note says «Возраст домена не проверялся на вашем тарифе» / "Domain age not checked on your plan".
- Files: page previews (`preview_pages`, server-rendered PNG) and a highlighted `source_excerpt` let reviewers compare extracted values without downloading; the e-mail body is sanitized plain text (no remote resources); "Download original" opens a confirm dialog warning that the file may be malicious.
- Callback contacts in the right rail show their four-eyes status (`pending verification` contacts cannot be used and say why).

## Color overrides
- Critical requests: 4px hazard-stripe left edge on the header strip; everything else follows MASTER.
- Decision buttons: Approve = primary (right), Reject = destructive outline (left), both open confirm dialogs summarizing payee (masked), amount, signals and verification.

## Avoid
- Showing full account numbers by default; any "safe" wording; animating the gauge more than once per load.
