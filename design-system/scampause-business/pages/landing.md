# Landing Page Overrides (`marketing/Landing`)

> PROJECT: ScamPause Business · Page spec, 2026-10-22.
> Rules here override `../MASTER.md` for this page only. Section-by-section content and copy: MASTER §9.

## Layout
- Container 1200px, 12-column grid, 24px gutters; sections 96px apart (64px below 768px).
- Floating sticky header with 16px inset; content starts below it (no overlap at any width).
- Hero is a 7/5 split on ≥ 1024px (copy left, live product mockup right); stacked below 1024px with the mockup after the CTAs.

## Typography overrides
- H1 `display-xl` (Plex Sans Variable, 650, `font-stretch: 85%`); section titles `display`; eyebrows in Plex Mono uppercase.
- Body on the landing is 1.0625rem / 1.6 for readability (app stays 1rem).

## Color overrides
- Background gets the grid texture + "signal glow" gradients (MASTER §4) only in the hero and the final CTA band.
- The hazard stripe appears in exactly three places: logo, the "How it works" connectors, the final CTA top edge.
- Pricing teaser: Pro highlighted with a `--primary` border; Free uses muted surfaces; no dark "premium" card.

## Components unique to this page
- `HeroScanMockup` (EnvelopeDiff + BankDiffTable + RiskGauge + Telegram bubble) with the one-time scan sequence (900ms) and staggered signal chips.
- `ScamCards` (four scams), `HowItWorksStory` (sticky scroll on desktop), `FeatureBento` with the interactive `PolicySimulatorMini`, `SignalLedgerTable`, `PricingTeaser`, `FaqAccordion`, `FinalCta`.

## UX rules (from CLI recommendations, filtered)
- CTA placement: hero (primary + secondary), after the bento, pricing cards, final band; sticky header CTA always visible.
- Animate at most two elements per viewport; everything has a reduced-motion final frame.
- No testimonials, logos or numbers that are not verified (PLAN §4, R9).
- Show the "We never move your money / not a guarantee" message above the fold (micro-row) and in the FAQ.
