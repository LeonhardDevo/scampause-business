# Audit Log Page Overrides (`audit/Index` — foundation page with domain filters)

> PROJECT: ScamPause Business · Page spec, 2026-10-22. Overrides `../MASTER.md` for this page.

## Layout
- Dense forensic table (36px rows on ≥ 1024px): time (local, UTC tooltip) · actor (avatar + name + role) · action (mono key + localized label) · subject (link, e.g. `SP-00031`) · channel · IP.
- Row expansion shows the before/after diff as a two-column key/value table; masked values stay masked (bank numbers never appear in audit diffs).
- Filters: action group (Payment requests, Verifications, Approvals, Vendors & bank details, Block list, Policies, Members, Billing), actor, date range.
- Header card links to the evidence integrity check (Reports) and shows the latest verification status.

## Effects
- The CLI's count-up/trend animations are rejected here: an audit log must be static and printable.
