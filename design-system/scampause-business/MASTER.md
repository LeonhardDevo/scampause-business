# ScamPause Business — Brand & UI Master Spec

> **How to use:** when building a page, first check `design-system/scampause-business/pages/<page>.md`; its rules override this file
> for that page only. Everything else follows this Master. Binding upstream rules: `PLATFORM.md` §0 (no CDN, RU+EN, light+dark,
> accessibility) and §9 (brand anchors). Product invariant: the UI shows **risk signals**, never "safe" (see `docs/DOMAIN_CONTRACT.md` §0).
>
> Design direction: fintech, dark-first console, IBM Plex. Deliberately excluded: hosted web-font imports (local `@fontsource*` packages only),
> single-theme designs (both themes are designed), testimonial carousels (no fake social proof), CTA glow/pulse loops (distracting, reduced-motion unfriendly).

---

## 0. Own layout language (binding; PLATFORM §0.10 "own UI, not a recolor")

ScamPause shares only the foundation's component primitives (shadcn-vue, reka-ui) with PayFlow Guard and OpsBot. Its layout language
is its own; reviewers reject a screen that could pass for PayFlow with different colours.

| Dimension | ScamPause Business ("forensic console") | PayFlow Guard (for contrast, its MASTER §5.1) |
|---|---|---|
| Shell | **Ink left sidebar in both themes** (264 / 64 px, mono eyebrow group labels) + 56 px header; case files add a sticky **right rail** "What to do next" from 1280 px | top-bar "ledger" shell, no foundation sidebar |
| Information architecture | queue → **case file with exhibits** (tabs named like evidence: Signals, Bank details, Sender, Verification, Approvals, Timeline, Files) | invoice ledger rows and reminder timelines |
| Density | data-dense: table rows 44 px / 36 px dense toggle, 24 px content padding, signal lists and diffs before prose | comfortable finance ledger |
| Radii & shape | rectangular **evidence tags** (6 px), buttons/inputs 8 px, cards/dialogs 14 px; borders separate, shadows minimal; dark cards have no drop shadow | softer, rounder cards |
| Typography | **IBM Plex Sans Variable** (condensed `wdth` 85% for display and KPIs) + **IBM Plex Mono** for references, accounts, hashes, eyebrows | Manrope + Inter + JetBrains Mono |
| Colour anchors | ink navy, signal blue, hazard amber; risk scale critical / high / medium / low | emerald / ink / amber / violet |
| Texture & motifs | blueprint **grid paper**, the **pause** mark (two bars), 4 px **hazard-stripe** edges, **redaction bars**, exhibit labels ("EXHIBIT A — SENDER") | dot pattern, hero glow |
| Illustration style | inline SVG "exhibits" assembled from real product fragments (e-mail card with a look-alike domain, bank diff with redaction bars, stamps, hash-linked blocks) + Lucide glyphs; no people, no stock, no 3D or clip-art | ledger and invoice illustrations |
| Landing structure | forensic **scan hero** (live mockup: scan line over an e-mail, staggered signal chips, gauge sweep) → four scams → sticky scroll "Forward → Check → Call & approve" → bento with a live policy simulator → "Not a black box" ledger table → bookkeepers → security & privacy → pricing teaser → FAQ → hazard-edged final CTA (§9) | its own section order and hero |
| Signature components | `RiskGauge`, `BankDiffTable`, `SenderPanel`, `EvidenceTimeline`, `VerificationSheet`, `RouteCard` | its own |

## 1. Personality

**Vigilant · forensic · calm · exact.** ScamPause is the colleague who says "wait — let's call them" without drama.

| We are | We are not |
|---|---|
| A forensic console: evidence, exhibits, diffs, timestamps | A cyber-security "hacker" aesthetic (neon, glitch, matrix green) |
| Serious and plain-spoken ("This account is new for this vendor") | Alarmist ("DANGER! FRAUD!") or reassuring ("All good!") |
| Precise with numbers (mono, tabular, masked) | Vague scores without reasons |
| Bilingual by design (RU and EN are equals) | English UI with translated labels squeezed in |

Brand motif: **the pause** — two vertical bars — and the **hazard stripe** (amber/ink, 45°) used sparingly as an edge marker.
Secondary motifs: **grid lines** (blueprint/ledger paper), **redaction bars** for masked data, **exhibit labels** ("EXHIBIT A — SENDER").

Voice examples:
- EN: "Stop. This request changes Blue Ridge's bank account. Call Karen Whitfield on the number from your contract."
- RU: «Стоп. В запросе новые реквизиты Blue Ridge. Позвоните Карен Уитфилд по номеру из договора.»
- Lowest risk label: EN "Few signals", RU «Мало сигналов» — always with the tooltip "The score shows risk signals; it does not mean the payment is safe." / «Оценка показывает сигналы риска и не гарантирует безопасность платежа.»

## 2. Color tokens (OKLCH, implemented in `resources/css/brand.css`)

Light is the default for first visits (`system` respected); dark is a first-class theme with its own values (the "dark-first" feel comes from
the ink sidebar, which stays dark in both themes). Values were computed from the PLATFORM §9 anchors; contrast ratios are WCAG 2.x against
the listed background (computed 2026-10-22).

### 2.1 Core (shadcn-vue variable names)

| token | Light | ≈ hex | Dark | ≈ hex | contrast notes |
|---|---|---|---|---|---|
| `--background` | `oklch(0.984 0.003 247.9)` | #F8FAFC | `oklch(0.169 0.025 265.2)` | #0A0F1A | — |
| `--foreground` | `oklch(0.183 0.031 263.4)` | #0B1220 | `oklch(0.929 0.013 255.5)` | #E2E8F0 | 17.9:1 / 15.5:1 on background |
| `--card` | `oklch(1 0 0)` | #FFFFFF | `oklch(0.209 0.032 262.4)` | #101827 | fg on card 18.7:1 / 14.4:1 |
| `--card-foreground` | = foreground | | = foreground | | |
| `--popover` | `oklch(1 0 0)` | #FFFFFF | `oklch(0.223 0.036 262.5)` | #121B2C | 18.7:1 / 14.0:1 |
| `--popover-foreground` | = foreground | | = foreground | | |
| `--muted` | `oklch(0.959 0.007 247.9)` | #EEF2F6 | `oklch(0.248 0.037 261.7)` | #172133 | surface for chips, code, table hover |
| `--muted-foreground` | `oklch(0.446 0.037 257.3)` | #475569 | `oklch(0.711 0.035 256.8)` | #94A3B8 | 7.2:1 on bg, 6.7:1 on muted / 7.5:1 on bg, 6.3:1 on muted |
| `--border` | `oklch(0.929 0.013 255.5)` | #E2E8F0 | `oklch(0.308 0.040 260.4)` | #243044 | decorative dividers only (1.2–1.3:1) |
| `--input` | `oklch(0.657 0.031 261.4)` | #8792A5 | `oklch(0.524 0.044 259.2)` | #5B6B84 | form control borders ≥ 3:1 (3.1 / 3.3) — WCAG 1.4.11 |
| `--ring` | `oklch(0.500 0.119 242.7)` | #0369A1 | `oklch(0.714 0.143 254.6)` | #60A5FA | 5.9:1 / 7.0:1 — 2px ring + 2px offset |
| `--primary` | `oklch(0.500 0.119 242.7)` | #0369A1 signal blue | `oklch(0.714 0.143 254.6)` | #60A5FA | links 5.7:1 / 7.5:1 on bg |
| `--primary-foreground` | `oklch(1 0 0)` | #FFFFFF | `oklch(0.172 0.035 257.2)` | #06101F | 5.9:1 / 7.5:1 |
| `--secondary` | `oklch(0.947 0.011 252.1)` | #E8EEF5 | `oklch(0.267 0.037 260.0)` | #1B2638 | |
| `--secondary-foreground` | `oklch(0.208 0.040 265.8)` | #0F172A | `oklch(0.929 0.013 255.5)` | #E2E8F0 | 15.3:1 / 12.3:1 |
| `--accent` (hover/selected surface) | `oklch(0.950 0.015 241.8)` | #E6F0F8 | `oklch(0.258 0.047 256.6)` | #14243A | |
| `--accent-foreground` | `oklch(0.337 0.075 245.1)` | #0C3A5B | `oklch(0.933 0.026 248.1)` | #DCEBFA | 10.3:1 / 12.9:1 |
| `--destructive` | `oklch(0.535 0.203 27.6)` | #C81E1E | `oklch(0.711 0.166 22.2)` | #F87171 | text on bg 5.5:1 / 6.9:1 |
| `--destructive-foreground` | `oklch(1 0 0)` | #FFFFFF | `oklch(0.143 0.036 22.7)` | #160404 | 5.7:1 / 7.2:1 |
| `--success` | `oklch(0.527 0.137 150.1)` | #15803D | `oklch(0.800 0.182 151.7)` | #4ADE80 | text 4.8:1 / 11.0:1 |
| `--success-foreground` | `oklch(1 0 0)` | #FFFFFF | `oklch(0.266 0.063 152.9)` | #052E16 | 5.0:1 / 8.6:1 |
| `--warning` | `oklch(0.528 0.131 54.6)` | #A35200 | `oklch(0.837 0.164 84.4)` | #FBBF24 | text 5.3:1 / 11.5:1 |
| `--warning-foreground` | `oklch(1 0 0)` | #FFFFFF | `oklch(0.189 0.026 77.5)` | #1A1206 | 5.6:1 / 11.1:1 |
| `--info` | `oklch(0.520 0.094 223.1)` | #0E7490 | `oklch(0.797 0.134 211.5)` | #22D3EE | text 5.1:1 / 10.6:1 |
| `--info-foreground` | `oklch(1 0 0)` | #FFFFFF | `oklch(0.172 0.035 257.2)` | #06101F | 5.4:1 / 10.6:1 |

### 2.2 Brand & risk tokens (ScamPause-specific)

| token | Light | Dark | use |
|---|---|---|---|
| `--hazard` | `oklch(0.769 0.165 70.1)` #F59E0B | same | logo pause square, hazard stripes, "Stop" accents — **never as text color on light** |
| `--hazard-foreground` | `oklch(0.189 0.026 77.5)` #1A1206 | same | text/icons on hazard (8.6:1) |
| `--hazard-ink` | `oklch(0.473 0.125 46.2)` #92400E | `oklch(0.837 0.164 84.4)` #FBBF24 | hazard-colored text (6.8:1 / 10.6:1) |
| `--risk-critical` (text/icon) | `oklch(0.505 0.190 27.5)` #B91C1C | `oklch(0.808 0.103 19.6)` #FCA5A5 | on tint 5.7:1 / 9.3:1 |
| `--risk-critical-tint` | `oklch(0.957 0.018 17.5)` #FDECEC | `oklch(0.219 0.040 13.0)` #2A1215 | badge/diff background |
| `--risk-critical-fill` | `oklch(0.577 0.215 27.3)` #DC2626 | `oklch(0.637 0.208 25.3)` #EF4444 | charts, gauge (4.8:1 / 4.7:1 vs card) |
| `--risk-high` | `oklch(0.553 0.174 38.4)` #C2410C | `oklch(0.837 0.117 66.3)` #FDBA74 | on tint 4.7:1 / 9.9:1 |
| `--risk-high-tint` | `oklch(0.966 0.021 60.8)` #FFF1E6 | `oklch(0.236 0.031 52.7)` #2A1A10 | |
| `--risk-high-fill` | `oklch(0.553 0.174 38.4)` #C2410C | `oklch(0.705 0.187 47.6)` #F97316 | 5.2:1 / 6.3:1 |
| `--risk-medium` | `oklch(0.508 0.108 73.3)` #8A5A00 | `oklch(0.905 0.166 98.1)` #FDE047 | on tint 5.5:1 / 12.3:1 |
| `--risk-medium-tint` | `oklch(0.975 0.036 95.0)` #FEF7DC | `oklch(0.246 0.029 89.6)` #262010 | |
| `--risk-medium-fill` | `oklch(0.554 0.121 66.4)` #A16207 | `oklch(0.795 0.162 86.0)` #EAB308 | 4.9:1 / 9.3:1 |
| `--risk-low` | `oklch(0.448 0.108 151.3)` #166534 | `oklch(0.871 0.136 154.4)` #86EFAC | on tint 6.5:1 / 11.6:1 |
| `--risk-low-tint` | `oklch(0.963 0.019 157.9)` #E9F7EE | `oklch(0.238 0.036 157.2)` #0F2418 | |
| `--risk-low-fill` | `oklch(0.527 0.137 150.1)` #15803D | `oklch(0.723 0.192 149.6)` #22C55E | 5.0:1 / 7.8:1 |
| `--grid-line` | `oklch(0.929 0.013 255.5 / 0.6)` | `oklch(0.260 0.037 261.8 / 0.7)` | background grid texture, chart split lines |
| `--redaction` | `oklch(0.183 0.031 263.4)` | `oklch(0.711 0.035 256.8)` | redaction bars for masked digits (decorative; masked text also has an accessible label) |

The risk ramp (red/orange/yellow/green anchors from PLATFORM §9) is **reserved for risk** — never for generic categories, brand
decoration or success messages unrelated to risk.

### 2.3 Charts (categorical, ≥ 3:1 vs card in both themes)

| token | Light | ≈ hex | Dark | ≈ hex |
|---|---|---|---|---|
| `--chart-1` | `oklch(0.500 0.119 242.7)` | #0369A1 | `oklch(0.714 0.143 254.6)` | #60A5FA |
| `--chart-2` | `oklch(0.555 0.146 49.0)` | #B45309 | `oklch(0.837 0.164 84.4)` | #FBBF24 |
| `--chart-3` | `oklch(0.511 0.086 186.4)` | #0F766E | `oklch(0.785 0.133 181.9)` | #2DD4BF |
| `--chart-4` | `oklch(0.491 0.241 292.6)` | #6D28D9 | `oklch(0.709 0.159 293.5)` | #A78BFA |
| `--chart-5` | `oklch(0.525 0.199 4.0)` | #BE185D | `oklch(0.725 0.175 349.8)` | #F472B6 |
| `--chart-6` | `oklch(0.446 0.037 257.3)` | #475569 | `oklch(0.711 0.035 256.8)` | #94A3B8 |
| `--chart-7` | `oklch(0.532 0.141 131.6)` | #4D7C0F | `oklch(0.849 0.207 128.8)` | #A3E635 |
| `--chart-8` | `oklch(0.520 0.094 223.1)` | #0E7490 | `oklch(0.797 0.134 211.5)` | #22D3EE |

Light contrasts vs white 5.0–7.6:1; dark vs card 6.5–11.8:1. Risk charts use `--risk-*-fill`, not chart-N. "Prevented" series use `--success`.

### 2.4 Sidebar (ink console in both themes)

| token | Light theme | Dark theme | notes |
|---|---|---|---|
| `--sidebar` | `oklch(0.183 0.031 263.4)` #0B1220 | `oklch(0.149 0.019 262.4)` #070B13 | |
| `--sidebar-foreground` | `oklch(0.869 0.020 252.9)` #CBD5E1 | same | 12.6:1 / 13.3:1 |
| `--sidebar-primary` | `oklch(0.714 0.143 254.6)` #60A5FA | same | active item icon/indicator 7.4:1 |
| `--sidebar-primary-foreground` | `oklch(0.172 0.035 257.2)` #06101F | same | |
| `--sidebar-accent` | `oklch(0.258 0.046 264.0)` #18233A | `oklch(0.231 0.036 262.5)` #141D2E | hover/active background |
| `--sidebar-accent-foreground` | `oklch(0.968 0.007 247.9)` #F1F5F9 | same | 14.3:1 / 15.4:1 |
| `--sidebar-border` | `oklch(0.279 0.037 260.0)` #1E293B | `oklch(0.259 0.031 260.4)` #1B2433 | decorative |
| `--sidebar-ring` | `oklch(0.714 0.143 254.6)` | same | |
| `--sidebar-muted-foreground` | `oklch(0.711 0.035 256.8)` #94A3B8 | same | group labels 7.3:1 |

### 2.5 Implementation

```css
/* resources/css/brand.css — imported by app.css; app.css maps tokens via @theme inline */
:root { /* light values from the tables */ }
.dark { /* dark values */ }
@theme inline {
  --color-hazard: var(--hazard); --color-hazard-foreground: var(--hazard-foreground); --color-hazard-ink: var(--hazard-ink);
  --color-risk-critical: var(--risk-critical); --color-risk-critical-tint: var(--risk-critical-tint); --color-risk-critical-fill: var(--risk-critical-fill);
  /* … same for high/medium/low, success/warning/info (+foreground), grid-line, redaction, chart-1..8, sidebar-* */
}
```
Components use utilities only (`bg-card`, `text-risk-critical`, `bg-risk-high-tint`, `border-input`); raw hex/oklch in `.vue` files is a lint error.
ECharts reads tokens at runtime with `getComputedStyle(document.documentElement)` and re-renders on theme change (MutationObserver on `html.class`).

## 3. Typography

| Role | Family (package) | Settings |
|---|---|---|
| UI & body | **IBM Plex Sans Variable** — `@fontsource-variable/ibm-plex-sans/wdth.css` (+ `wdth-italic.css`) | weights 100–700, width axis 75–100% |
| Display (landing H1/H2, KPI numbers) | same family, `font-stretch: 85%` (condensed via the `wdth` axis — no separate Condensed package needed) | weight 600–650 |
| Data, references, IBAN/accounts, hashes, eyebrows | **IBM Plex Mono** — `@fontsource/ibm-plex-mono/400.css`, `500.css`, `600.css` | |
| PDF evidence packets (worker) | `@ibm/plex-sans`, `@ibm/plex-mono` complete WOFF files embedded by pdfkit | |

**Cyrillic confirmed** (checked in the package files on 2026-10-22): `@fontsource-variable/ibm-plex-sans@5.3.0` ships `cyrillic` and `cyrillic-ext`
subsets for the `wdth` axis (`unicode-range U+0400-045F …`); `@fontsource/ibm-plex-mono@5.3.0` ships `cyrillic` and `cyrillic-ext`; `@ibm/plex-sans@1.1.0`
complete fonts include Cyrillic. CSS family names: `'IBM Plex Sans Variable'`, `'IBM Plex Mono'`.

```css
/* resources/css/fonts.css (brand-landing) — local packages only, no network */
@import '@fontsource-variable/ibm-plex-sans/wdth.css';
@import '@fontsource-variable/ibm-plex-sans/wdth-italic.css';
@import '@fontsource/ibm-plex-mono/400.css';
@import '@fontsource/ibm-plex-mono/500.css';
@import '@fontsource/ibm-plex-mono/600.css';
@theme inline {
  --font-sans: 'IBM Plex Sans Variable', ui-sans-serif, system-ui, sans-serif;
  --font-mono: 'IBM Plex Mono', ui-monospace, 'SFMono-Regular', Menlo, monospace;
}
```

Scale (rem; 1rem = 16px):

| token | size / line-height | weight | stretch | tracking | use |
|---|---|---|---|---|---|
| `display-xl` | `clamp(2.5rem, 4.2vw + 1rem, 4.5rem)` / 1.02 | 650 | 85% | −0.02em | landing hero |
| `display` | `clamp(2rem, 2.4vw + 1rem, 3rem)` / 1.08 | 600 | 87.5% | −0.015em | landing section titles |
| `h1` | 1.5rem / 2rem | 600 | 100% | −0.01em | app page title |
| `h2` | 1.25rem / 1.75rem | 600 | 100% | −0.005em | card titles, dialogs |
| `h3` | 1rem / 1.5rem | 600 | 100% | 0 | sub-sections |
| `body` | 1rem / 1.5rem | 400 | 100% | 0 | default (mobile minimum) |
| `body-sm` | 0.875rem / 1.25rem | 400 | 100% | 0 | dense tables ≥ 1024px, secondary text |
| `caption` | 0.8125rem / 1.125rem | 400–500 | 100% | 0.005em | helper text, timestamps |
| `eyebrow` | 0.75rem / 1rem | 600 | — (mono) | 0.08em, uppercase | exhibit labels "EXHIBIT A — BANK DETAILS" / «ПРИЛОЖЕНИЕ А — РЕКВИЗИТЫ» |
| `kpi` | `clamp(1.75rem, 1.2vw + 1.2rem, 2.25rem)` / 1.1 | 600 | 85% | −0.01em | KPI tiles (tabular) |
| `mono-data` | 0.875rem / 1.25rem | 500 | — | 0.02em | references `SP-00042`, accounts `LT58 •••• •••• 9129`, hashes |

Numeric rules: all amounts, scores, counts and dates in tables/KPIs use `font-variant-numeric: tabular-nums lining-nums`; amounts are right-aligned
with the currency formatted by `Intl.NumberFormat` (`$18,400.00` / `18 400,00 $`); account numbers are grouped by 4 in mono; references never
mix `O` and `0`. RU text: allow 15–25% more width; never truncate Russian labels in buttons (buttons grow; labels wrap only in cards).
Line length 60–75 characters for prose.

## 4. Shape, depth, texture

- Radius tokens: `--radius: 0.625rem` (10px) → `sm 6px` (badges, chips, dense inputs), `md 8px` (buttons, inputs), `lg 10px`, `xl 14px` (cards, dialogs), `full` (avatars, gauge).
  Badges are **rectangular tags (6px)**, not pills — the forensic "evidence tag" look.
- Borders do the separation work; shadows are subtle. Light: `--shadow-xs: 0 1px 0 oklch(0.183 0.031 263.4 / 0.04)`, `--shadow-sm: 0 1px 2px oklch(0.183 0.031 263.4 / 0.06), 0 1px 1px oklch(0.183 0.031 263.4 / 0.04)`,
  `--shadow-md: 0 6px 16px -4px oklch(0.183 0.031 263.4 / 0.10)`, `--shadow-lg: 0 16px 40px -12px oklch(0.183 0.031 263.4 / 0.20)`.
  Dark: no drop shadows on cards; use `border` + `inset 0 1px 0 oklch(1 0 0 / 0.04)`; overlays use `--shadow-lg` with `oklch(0 0 0 / 0.5)`.
- **Grid texture** (landing hero, empty states, auth pages): `background-image: linear-gradient(var(--grid-line) 1px, transparent 1px), linear-gradient(90deg, var(--grid-line) 1px, transparent 1px); background-size: 24px 24px;`
  masked with a radial fade (`mask-image: radial-gradient(ellipse at top, black 40%, transparent 75%)`).
- **Hazard stripe**: `repeating-linear-gradient(-45deg, var(--hazard) 0 6px, var(--hazard-foreground) 6px 12px)` — only as a 4px edge (left border of critical banners, top of the final CTA band, the solo-mode banner) and inside the logo. Never behind text, never animated.
- **Redaction bars**: masked digits render as `•` in mono inside a `--muted` chip; on the landing, decorative redaction bars (`--redaction`, 2px radius) stand in for numbers in mockups.
- Gradients: restrained "signal glow" only on the landing — light: `radial-gradient(60% 50% at 15% 0%, oklch(0.500 0.119 242.7 / 0.10), transparent)` + `radial-gradient(40% 40% at 90% 70%, oklch(0.769 0.165 70.1 / 0.08), transparent)`;
  dark: same hues at 0.18 / 0.10. No gradients on app surfaces, buttons or text.
- Z-index scale: 10 sticky table headers, 20 dropdowns/popovers, 30 sheets/drawers, 40 dialogs, 50 toasts, 60 command palette.
- Spacing: 4px base unit; app content padding 24px (16px < 640px); landing container 1200px; app tables may use full width up to 1440px.

## 5. Motion

| Token | Duration | Easing | Use |
|---|---|---|---|
| `--motion-instant` | 100ms | `cubic-bezier(0.2, 0, 0, 1)` | hover color, pressed state |
| `--motion-quick` | 160ms | `cubic-bezier(0.2, 0, 0, 1)` | tooltips, chips appearing, tab underline |
| `--motion-base` | 220ms | `cubic-bezier(0.2, 0, 0, 1)` enter / `cubic-bezier(0.4, 0, 1, 1)` exit | popovers, dialogs, sheets (translate 8–16px + fade) |
| `--motion-slow` | 320ms | same | page-level panels, stepper progress |
| `--motion-story` | 600–900ms (once) | `cubic-bezier(0.16, 1, 0.3, 1)` | landing hero sequence, gauge sweep |

Rules: animate only `opacity` and `transform`; max 1–2 animated elements per view; no infinite decorative loops (only spinners/progress);
no scale on hover for cards (no layout shift) — use border/background change; the risk gauge sweeps from 0 to the score once per load;
signal chips stagger 40ms each (max 6). `prefers-reduced-motion: reduce` → all transforms disabled, fades ≤ 100ms, ECharts `animation:false`,
the landing scanner shows the final frame, the gauge renders instantly. Live updates (pipeline stepper) announce via `aria-live="polite"`.

## 6. Iconography

Lucide via **`@lucide/vue`** (the foundation dependency — no second icon package), stroke 1.75 (1.5 at ≥ 24px), sizes 16 (inline, tables), 20 (buttons, nav), 24 (empty states, feature tiles).
Brand marks (Telegram, Stripe, Visa …) only from **`simple-icons`** (`siTelegram`, `siStripe`) rendered as inline SVG paths with `fill="currentColor"` and an `aria-label`; no hand-drawn logos. `ChannelBadge` uses `siTelegram` for Telegram.
Never emoji, stickers, animated emoji or emoji used as bullets/status markers — in the UI, e-mails, PDFs, Telegram templates and keyboards, seed data or landing copy (PLATFORM §0.8). Status = text + colour + Lucide icon; in Telegram only `<b>`, `<i>`, `<code>`, `<a>`, words and `•`, `—`, `№`. Icon-only buttons need `aria-label`. Canonical icons: risk `ShieldAlert` (critical), `TriangleAlert` (high), `CircleAlert` (medium), `CircleDot` (low);
status `Loader` (processing), `FileSearch` (review), `PhoneCall` (awaiting verification), `Stamp` (awaiting approval), `CheckCheck` (approved), `Banknote` (paid), `OctagonX` (rejected), `TimerOff` (expired), `Ban` (blocked);
domain `Landmark` (bank), `ArrowRightLeft` (changed details), `AtSign` (sender), `Globe` (domain), `Fingerprint` (fingerprint/hash), `Link2` (evidence chain), `QrCode`, `Users` (payroll), `MessagesSquare` (assistant), `Send` (generic "send" actions only — Telegram itself is shown with `siTelegram`).

## 7. Logo & illustration

- **Mark** (`AppLogoMark.vue`, inline SVG, 32×32 viewBox): rounded square (r 7) filled `--hazard`, two vertical bars (pause) in `--hazard-foreground`, 4×14 px with a 4px gap,
  bottom-right corner cut by a 3-stripe hazard notch. Monochrome variant uses `currentColor`. Minimum size 16px (the favicon drops the notch).
- **Wordmark**: "ScamPause" in Plex Sans 650, stretch 85%, tracking −0.01em; "Business" in Plex Mono 500 uppercase at 0.6em, tracking 0.12em, `--muted-foreground`.
- Favicons and OG images are generated locally from the SVG (script in the brand-landing package), RU and EN OG variants.
- **Illustrations** are inline SVG Vue components (`components/brand/illustrations/*.vue`) built from product primitives — no stock photos, no photos of people:
  `EnvelopeDiff` (e-mail card with a highlighted look-alike domain), `BankDiffCard` (old vs new account with redaction bars), `CallbackPhone` (phone with a verified checkmark and the official number label),
  `ApprovalStamp` (two stamps = dual control), `EvidenceChain` (linked blocks with hash snippets), `QrSwap` (QR with a crossed-out payee), `EmptyLedger` (grid paper + pause mark) for empty states.
  Colors via tokens (work in both themes), stroke 1.5px, `aria-hidden="true"` when decorative, `<title>` when meaningful.

## 8. Components (shadcn-vue base, extended in `components/domain/**`)

### 8.1 Buttons
| Variant | Light | Dark | Use |
|---|---|---|---|
| `default` (primary) | `bg-primary text-primary-foreground`, hover `oklch(0.450 0.110 242.7)` | `bg-primary`, hover `oklch(0.770 0.130 254.6)` | one per view: "Check a payment", "Submit", "Approve" |
| `secondary` | `bg-secondary text-secondary-foreground` | same tokens | secondary actions |
| `outline` | `border-input bg-card` | same | filters, toolbar |
| `ghost` | transparent, hover `bg-accent` | same | table row actions |
| `destructive` | `bg-destructive text-destructive-foreground` | same | Reject, Block, Revoke (always with a confirm dialog) |
| `hazard` | `bg-hazard text-hazard-foreground` | same | landing "Stop" demo control and the solo-mode banner action only |

Sizes: `sm` 32px (desktop dense toolbars only), `default` 40px, `lg` 48px (landing, mobile primary). Touch targets ≥ 44×44 via padding/`min-h-11` below 768px.
States: focus-visible ring 2px `--ring` + 2px offset (`--background`); disabled 50% opacity + `cursor-not-allowed`; loading = a spinner replaces the leading icon, the label stays, `aria-busy="true"`.
Approve/Reject pair: Approve (default) on the right, Reject (destructive outline) on the left — reject is never the primary color.

### 8.2 Badges & chips
- `RiskBadge`: tag (6px radius) `bg-risk-{level}-tint text-risk-{level}` + icon + label + score in mono (`Critical risk · 92`). Sizes sm (tables) / md (headers). Tooltip with the "not a guarantee" sentence. Critical adds a 3px left hazard-stripe edge.
- `StatusChip`: neutral tag with a leading icon (see §6); colors: review/awaiting_verification `warning` tint, awaiting_approval `info` tint, approved `success` tint, paid/expired/cancelled `muted`, rejected `destructive` tint. Labels never rely on color alone.
- `SignalChip`: category icon + short title; severity shown as 1–4 bars (SVG) + points.
- `DoNotUseChip`: phone/e-mail taken from the suspicious message, strikethrough, `Ban` icon, `text-hazard-ink`, tooltip "Do not use numbers from the message" / «Не используйте номера из письма».
- `ChannelBadge`: web / Telegram / system with icon, muted.
- Plan/limit badges on billing: outline.

### 8.3 Cards
`bg-card border rounded-xl`, padding 20px (16px below 640px); header = eyebrow (mono, uppercase) + title + actions. "Exhibit" cards in the case file carry
eyebrows `EXHIBIT A — SENDER`, `EXHIBIT B — BANK DETAILS`, `EXHIBIT C — CALLBACK`, `EXHIBIT D — APPROVALS` (RU «ПРИЛОЖЕНИЕ А — ОТПРАВИТЕЛЬ», …).
Clickable cards: `cursor-pointer`, hover `border-ring/40` + `bg-accent/40`, no translate/scale.

### 8.4 Tables
@tanstack/vue-table + shadcn Table. Row height 44px (comfortable) / 36px (dense toggle ≥ 1024px). Sticky header `bg-card` with a bottom border.
Numbers right-aligned and tabular; references and accounts in mono; risk and status as badges; relative times with an absolute tooltip (`Intl`).
Hover `bg-muted/60`, selected `bg-accent`. Keyboard: rows focusable, `Enter` opens, `j/k` moves. Below 768px rows become stacked cards with the same fields.
Empty state inside the table frame (illustration + one action). Pagination: page size 25, "1–25 of 312" / «1–25 из 312».

### 8.5 Data visualisation (vue-echarts, tree-shaken)
- Chart types: trends → line/area (prevented amount by month); category comparison → horizontal bars sorted descending with value labels (top signals);
  composition over time → stacked columns (risk by week); part-to-whole ≤ 4 parts → donut (decision SLA); distribution → histogram (decision time). No pies with more than 4 slices, no 3D, no dual axes.
- Theme from tokens: text `--muted-foreground` 12px Plex Sans, split lines `--grid-line`, axis line hidden, tooltip `bg-popover` + `border` + `shadow-md`, mono numbers in tooltips.
- Accessibility: `aria-label` summary sentence per chart, a "Show as table" toggle (real `<table>`), `decal` patterns enabled for risk series (critical = diagonal hatch, high = dots) so color is not the only cue.
- Fonts on canvas: charts initialise only after `document.fonts.ready` and `document.fonts.load('12px "IBM Plex Sans Variable"', 'Ж')` (Cyrillic subset loaded); `textStyle.fontFamily` is read from `--font-sans`, and charts re-render when the theme or fonts change (colours are re-read from tokens, never cached once).
- `RiskGauge`: 180° arc, track `--muted`, value arc colored by level, score in `kpi` type, level label below; sweeps once (`--motion-story`), instant under reduced motion.

### 8.6 Forensic components
- `BankDiffTable`: two columns "On record" / «В справочнике» vs "In this request" / «В запросе»; changed rows `bg-risk-critical-tint` + `ArrowRightLeft` icon + "changed" tag; Reveal (audited) shows full numbers with char-level highlights (`underline decoration-2 decoration-risk-critical`).
- `SenderPanel`: domain rows with a look-alike explanation ("blueridge-pack.com looks like blueridgepack.com · one character added" / «blueridge-pack.com похож на blueridgepack.com · добавлен один символ»), auth result chips (SPF/DKIM/DMARC), domain age, free-mail tag.
- `PipelineStepper`: 4 steps Extract → Domains → Bank details → Score with states pending/running/done/failed; `aria-live`.
- `EvidenceTimeline`: vertical rule `--border`, nodes colored by event type; each item shows time (local, UTC on hover), actor, channel badge, short hash `#2fda86…` (mono) with a copy button; integrity status pill at the top ("Chain verified 03:00" / «Цепочка проверена в 03:00»).
- `VerificationSheet`: right sheet; official contacts list with one-tap copy of the number; checklist with large checkboxes; outcome segmented control; the "do not use numbers from the message" reminder sits above the contacts.
- `RouteCard`: slots as rows (avatar, role, status, channel, time); the deadline countdown (`TimerReset` icon) turns `warning` below 12h and `destructive` below 2h.

### 8.7 Assistant chat & citations
- Assistant bubble: `bg-card border rounded-xl` with a 2px left rule `--primary`; user bubble `bg-secondary`; max width 72ch; markdown rendered as sanitized HTML.
- Citation chips: `[1]` in mono `text-primary` + document title + page/section, tag radius 6px; hover reveals the snippet in a popover; tool results as muted chips ("Policy rules" / «Правила согласования»).
- Refused answers: bubble with a `--hazard` left rule + "Not found in approved documents" / «Не найдено в утверждённых документах» + an escalate action.
- Suggestions: outline chips above the composer (4 seeded questions per locale).

### 8.8 Forms
Label above the input, helper text below, error below in `text-destructive` with a 16px `CircleAlert`, linked via `aria-describedby`. Inputs 40px (44px mobile), `border-input`, focus ring.
Account inputs: mono, auto-grouping by 4, live checksum indicator (IBAN/ABA/RU key) with text ("Checksum OK" / «Контрольная сумма верна»).
Money inputs: currency select + amount with a locale-aware decimal separator; stored as minor units.

### 8.9 Feedback
Toasts (sonner) bottom-right (bottom on mobile), 4s, never for errors that need action (use inline banners). Confirm dialogs for reject/revoke/block/unblock/cancel/delete with the consequence spelled out.
Banners: info (blue), warning (amber), critical (red with a hazard edge). Skeletons match final layout heights (no layout shift).

## 9. Landing page (`marketing/Landing`) — section by section

Layout: max-width 1200px content, 12-column grid, floating sticky header (16px inset, `bg-background/80 backdrop-blur border rounded-xl`), sections 96px apart (64px mobile).
Pattern: "Product Demo + Features" with a before/after element and a pricing teaser — **no fake testimonials or logos** until real design partners agree.

| # | Section | Content & copy direction (EN / RU) | Visual & motion |
|---|---|---|---|
| 1 | Header | Logo · Product · How it works · Pricing · Security · RU/EN · theme · "Sign in" / «Войти» · CTA "Start free" / «Начать бесплатно» | sticky floating bar; primary CTA |
| 2 | Hero | Eyebrow (mono): "FOR SMALL BUSINESSES AND THEIR BOOKKEEPERS" / «ДЛЯ МАЛОГО БИЗНЕСА И ЕГО БУХГАЛТЕРОВ». H1: "Stop. Call. Confirm. Before the money leaves." / «Стоп. Позвони. Подтверди. — пока деньги не ушли.» Sub: "Forward a suspicious invoice or e-mail. ScamPause finds changed bank details, look-alike senders and pressure tactics, routes the payment to a second approver in Telegram, and keeps proof of who checked what." / «Перешлите подозрительный счёт или письмо. ScamPause найдёт смену реквизитов, поддельного отправителя и давление, отправит платёж второму согласующему в Telegram и сохранит доказательства: кто, когда и как проверил.» CTAs: "Check an invoice free" / «Проверить счёт бесплатно» + "See how it works" / «Как это работает». Micro-row: "Works with any bank · RU / EN · We never move your money" / «Работает с любым банком · RU / EN · Мы не проводим платежи» | Right: live mockup built from real components — `EnvelopeDiff` e-mail card, `BankDiffTable` (•4417 → •8839), `RiskGauge` sweeping to 92 "Critical", a Telegram approval bubble. Once on load: a 1px "scan line" passes over the e-mail (900ms), signal chips appear staggered (look-alike domain, Reply-To mismatch, "don't call"), the gauge sweeps. Grid texture + signal glow background. Reduced motion: final frame. |
| 3 | The four scams | Title: "Four ways small companies lose money" / «Четыре способа, которыми малый бизнес теряет деньги». Cards: Fake invoice / Поддельный счёт; Vendor impersonation / Подмена поставщика; Payroll diversion / Перенаправление зарплаты; "Urgent" CEO wire + QR invoices / «Срочный» перевод «от директора» и счета с QR. Each card: a one-line example in quotes. Source line: "Business e-mail compromise is among the costliest cybercrimes reported to the FBI" / «Компрометация деловой переписки — одно из самых дорогих киберпреступлений по данным ФБР» (numbers only after primary-source verification) | 4-up grid (2×2 tablet, stacked mobile); small inline SVG per card; cards fade up on first view (once, 40ms stagger) |
| 4 | How it works | "Forward → Check → Call & approve" / «Переслать → Проверить → Позвонить и согласовать». 1 "Upload the PDF, photo or .eml — or type it in." / «Загрузите PDF, фото или .eml — или введите вручную.» 2 "Rules compare it with the vendor's history: bank details, domain, amount, urgency." / «Правила сравнивают запрос с историей поставщика: реквизиты, домен, сумма, срочность.» 3 "Call the official number from your contract, record the result, and let a second person approve — in Telegram or the web." / «Позвоните по официальному номеру из договора, запишите результат, а второй человек согласует — в Telegram или в вебе.» | desktop: sticky scroll story — steps on the left, one mockup on the right that changes state per step (cross-fade 220ms); thin hazard-stripe connectors; mobile: stacked cards |
| 5 | Feature bento | **Bank-detail diff** (large) "See exactly what changed" / «Видно, что именно изменилось»; **Sender forensics** "Look-alike domains, Reply-To tricks, fresh domains" / «Похожие домены, подмена Reply-To, свежие домены»; **Telegram approvals** "Two taps for routine payments, full review for risky ones" / «Два нажатия для обычных платежей, полная проверка для рискованных»; **Your policy, executable** — interactive mini simulator: amount input → "2 approvers · owner required" / «2 согласующих · нужен владелец»; **Evidence you can export** "Hash-chained log and PDF packets" / «Журнал с цепочкой хешей и PDF-пакеты»; **Assistant over your documents** "“What's the official number in the contract?” — with sources" / «„Какой официальный номер в договоре?“ — с источниками»; **Prevented, measured** mini area chart | bento 4-column grid (2 tablet, 1 mobile); tiles are real components with demo data; hover: border highlight only; the simulator is keyboard accessible |
| 6 | Not a black box | "Rules you can read. Signals you can explain." / «Правила, которые можно прочитать. Сигналы, которые можно объяснить.» Six example signals with points; statement: "We never tell you a payment is safe. We show what looks different and who confirmed it." / «Мы никогда не говорим, что платёж безопасен. Мы показываем, что выглядит иначе, и кто это подтвердил.» | mono table styled like a ledger on grid paper |
| 7 | For bookkeepers | "One login, every client's payment checks" / «Один вход — проверки платежей всех клиентов»: roles (bookkeeper prepares & verifies, client approves), workspace switcher visual | composition of the switcher + queue |
| 8 | Security & privacy | Encrypted bank data, masked by default, audited reveal; no trackers, no third-party scripts; "Data residency options on request" / «Размещение данных в РФ — по запросу»; evidence chain; link to the risk disclaimer | 4 icon rows + link to `/security` |
| 9 | Pricing teaser | 4 plan cards from `/pricing` props, Pro highlighted, annual toggle (−17%); "Limits never block a verification or a rejection" / «Лимиты никогда не мешают проверке или отклонению платежа» | cards; the highlighted card has a primary border + "Most popular" / «Чаще выбирают» tag |
| 10 | FAQ | Does ScamPause block payments? (No — you pay in your bank) · Is this a guarantee? (No — it shows risk signals and records verification) · Do we need Telegram? (No — e-mail and web work too) · We are a 2-person company (solo mode + invite a second approver) · Which languages? · Where is data stored? | accordion (reka-ui), keyboard accessible |
| 11 | Final CTA | "Your next “urgent” invoice deserves five minutes." / «Следующий „срочный“ счёт заслуживает пяти минут проверки.» + CTA | band with a 4px hazard-stripe top edge, grid texture |
| 12 | Footer | Product, Pricing, Security; legal set (PLATFORM §10.2): Terms, Privacy, Cookies, DPA, Sub-processors, AUP, AI notice, Security, Contact, Risk disclaimer; one-line disclaimer "ScamPause shows risk signals; it is not a guarantee." / «ScamPause показывает сигналы риска и не даёт гарантий.»; language, theme, © | muted, two columns on mobile; the same legal links in the app user menu. A small non-blocking **cookie notice** (strictly necessary cookies only, dismissal stored in a cookie) sits bottom-left on first visit |

SEO: title/description per locale ("ScamPause Business — check suspicious payments before you pay" / «ScamPause Business — проверка подозрительных платежей до оплаты»; "before it happens" is on the copy-guard list), OG images per locale, `hreflang` ru/en/x-default.

## 10. App page patterns

- **Shell**: ink sidebar (both themes), 264px expanded / 64px collapsed, group labels in mono eyebrow style; header 56px with breadcrumbs, ⌘K, primary "Check a payment", locale, theme, notifications bell (critical items show a red count).
- **Queue pages** (payment requests, approvals): filter bar (segmented status with counts) → table/cards; attention items first; no bulk approve (deliberate).
- **Case file** (payment request show): header strip (reference mono, status, risk gauge, amount, vendor, primary action by state) → exhibits in tabs (Overview signals, Bank details, Sender, Verification, Approvals, Timeline, Files). From 1280px a right rail keeps "What to do next" (Stop-Call-Confirm checklist + official contact) visible.
- **Master data** (vendors): list with "missing callback number" warnings; the vendor page uses the bank-account history timeline as its hero element.
- **Configuration** (policies): rule cards that read like sentences ("If amount ≥ $3,000 → 2 approvers") + live simulator.
- **Reports**: KPI strip → charts 2×2 → prevented cases table → exports & integrity card.
- **States**: every page defines empty (illustration + one action), loading (skeleton matching layout), error (inline banner with retry), permission-denied (explains which role is needed).
- Responsive: 375 (single column, tables → cards, sheets full-screen), 768 (two columns where useful), 1024 (collapsible sidebar), 1440 (full layout, right rails).

## 11. Accessibility checklist (pre-delivery)

- [ ] Text contrast ≥ 4.5:1 and UI component boundaries ≥ 3:1 in **both** themes (token tables above).
- [ ] Risk/status never by color alone (icon + label + pattern in charts).
- [ ] Visible focus ring on every interactive element; logical tab order; `j/k` table navigation documented in a tooltip.
- [ ] Inputs have labels; errors linked with `aria-describedby`; live regions for pipeline progress and toasts.
- [ ] Icon-only buttons have `aria-label`; decorative SVG `aria-hidden`.
- [ ] 44px touch targets on mobile; no horizontal scroll at 375px; RU strings fit (test with the longest RU labels).
- [ ] `prefers-reduced-motion` respected (gauge, landing scanner, charts).
- [ ] No external requests (fonts, images, scripts) — Playwright network guard green.
- [ ] Charts have table alternatives; masked numbers have accessible labels ("account ending 4417" / «счёт, оканчивающийся на 4417»).

## 12. Anti-patterns (do not use)

- Neon/glitch "hacker" styling, matrix green, skull/padlock clichés, stock photos of hooded hackers.
- "Safe", "guaranteed", "100% protection" as product claims (see DOMAIN_CONTRACT §0).
- Pills for risk badges, red/green as the only signal, pie charts with many slices.
- Hover scale/translate on cards and table rows; infinite pulsing CTAs; parallax that moves text.
- Any remote `@import`, hosted web-font services, CDN scripts, hot-linked images, Gravatar.
- Raw hex/oklch values in components; one-off colors outside the token tables.
