# Payment Requests Page Overrides (`payment-requests/Index`, `payment-requests/Create`)

> PROJECT: ScamPause Business · Page spec, 2026-10-22. Overrides `../MASTER.md` for these pages.

## Index (queue)
- Full width (≤ 1440px), high density allowed: dense row toggle (36px) on ≥ 1024px; default 44px.
- Filter bar: search, status segmented control with counts (Open / Review / Awaiting verification / Awaiting approval / Decided), risk, kind, vendor, date range, "Mine".
- Columns: Reference (mono) · Title · Vendor · Amount (right, tabular) · Risk badge · Status chip · Due / expires (relative) · Requested by. Row click opens the case file.
- Below 768px: rows become cards (reference + status on top, amount + risk below).
- No bulk actions on purpose (every decision is individual and evidenced).

## Create (intake)
- Two tabs: Upload (default) and Enter manually. Dropzone min-height 220px with grid texture, accepts PDF/PNG/JPG/WEBP/EML, per-file progress.
- Right rail (≥ 1024px) or bottom card (mobile): "Stop. Call. Confirm." checklist + "Never use numbers from the message".
- Manual form: account inputs in mono with live checksum indicator; amount + currency inputs side by side.
- Plan limit reached: upgrade card **and** the manual checklist remain visible.

## Avoid
- Toasts for validation errors (inline only); auto-submitting the upload without explicit action.
