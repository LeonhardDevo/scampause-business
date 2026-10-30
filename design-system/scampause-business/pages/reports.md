# Reports Page Overrides (`reports/Index`)

> PROJECT: ScamPause Business · Page spec, 2026-10-22. Overrides `../MASTER.md` for this page.

## Layout
- Period bar (presets 30 days / 90 days / Year to date / 12 months + custom range, workspace timezone shown) → KPI strip (9 tiles, wraps) → charts 2×2 → by-kind table → prevented cases table → Exports & Evidence integrity cards side by side.
- Print stylesheet: hides navigation, prints KPIs + tables in the light theme (for insurers/auditors).

## Charts (CLI chart guidance applied)
- Checks by week: stacked columns by risk level (risk fills + decals).
- Prevented by month: columns fraud vs duplicate (`--success` and `--chart-6`).
- Signals: horizontal bars sorted descending, colored by category (chart-1..6), value labels.
- Decision time: histogram buckets (<4h, 4–24h, 1–2d, 2–3d, >3d).
- All with table alternatives and a summary `aria-label`.

## Evidence integrity card
- Shows the last verification time, entries count, head hash (mono, copyable) and a "Verify integrity" button; result states: verified (success), broken (destructive, with the first broken sequence number and guidance).

## Rejected CLI suggestions
- Parallax / scroll-triggered effects: this is a data page, no decorative motion.
