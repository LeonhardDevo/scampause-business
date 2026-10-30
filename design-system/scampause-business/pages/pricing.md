# Pricing Page Overrides (`marketing/Pricing`, `settings/Billing` plan cards)

> PROJECT: ScamPause Business · Page spec, 2026-10-22, pattern "Pricing-Focused": hero → plan cards → comparison → FAQ → CTA. Overrides `../MASTER.md` for these pages.

## Layout
- Container 1200px (not the narrow 800px suggested by the CLI — four plans need width). Monthly/annual toggle (annual shows "2 months free" / «2 месяца в подарок»).
- 4 plan cards (Free, Starter, Pro highlighted, Business); 2×2 on tablet, stacked on mobile with Pro first.
- Comparison table of limits exactly from `config/billing.php` (members, checks per month, vendors, documents, AI questions, approval rules, Telegram approvals, evidence exports, live domain intelligence, file retention).
- Safety note under the table: "Limits never block a verification, an approval or a rejection." / «Лимиты никогда не мешают проверке, согласованию или отклонению платежа.»
- FAQ: billing questions + "Is ScamPause a guarantee against fraud? No…" / «Гарантирует ли ScamPause защиту от мошенничества? Нет…».

## Color overrides
- Pro card: `--primary` 2px border + "Most popular" tag; other cards default. No dark "enterprise" card.
- Prices in Plex Sans stretch 85%, tabular, in the **visitor's market currency** (USD / EUR / RUB from `config/billing.php` per-currency prices, DOMAIN_CONTRACT §2.16) formatted with the shared money spec (`$29`, `27 €`, `2 490 ₽`). Payment-method line: "Card payments via Stripe" or, for RUB, "Payments via YooKassa" (simple-icons marks only). Only subscriptions are billed — ScamPause never processes your payments.
