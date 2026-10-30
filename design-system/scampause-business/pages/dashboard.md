# Dashboard Page Overrides (`Dashboard`)

> PROJECT: ScamPause Business · Page spec, 2026-10-22. Overrides `../MASTER.md` for this page.

## Layout
- Full content width up to 1440px. Order: KPI strip (6 tiles, 3×2 on tablet, 2×3 on mobile) → "Needs attention" (8 cols) + "Recent bank-detail changes" (4 cols) → charts grid 2×2 → onboarding checklist (until complete).
- Fixed chart heights (280px desktop, 240px mobile) so skeletons do not shift the layout.

## Typography / color overrides
- KPI values use the `kpi` token (Plex Sans, stretch 85%, tabular). "Prevented (30 days)" tile uses `--success` for the value, never the risk palette.
- Attention list items carry a reason chip (warning tint for verification, info tint for "your approval", destructive tint for critical risk).

## Charts (per CLI chart search: trend → line/area, comparison → sorted bars, composition → stacked columns)
- Risk by week: stacked columns with `--risk-*-fill` + decal patterns for critical/high.
- Prevented vs approved: two-series area/columns (`--success` vs `--chart-1`), compact currency axis.
- Top signals: horizontal bars sorted descending with value labels.
- Decision SLA: donut (on time / expired / pending) with the total in the center.
- Every chart: "Show as table" toggle and an `aria-label` sentence.

## Effects
- Count-up on KPI values is **not** used (numbers must be readable immediately); only the skeleton → content fade (`--motion-quick`).
