# Vendors & Payees Page Overrides (`vendors/Index`, `vendors/Show`, `vendors/Form`, `blocked-accounts/Index`)

> PROJECT: ScamPause Business · Page spec, 2026-10-22. Overrides `../MASTER.md` for these pages.

## Index
- Stats strip: total · missing callback number (warning tint, clickable filter) · recent bank changes (≤ 30 days) · employees.
- Table: Name (+ kind tag) · Domains (chips, max 2 + "+n") · Callback contact (name + masked phone; missing → warning chip "No official number" / «Нет официального номера») · Verified account (masked) · Open requests · Last paid.
- Tabs or segmented control: All / Suppliers & contractors / Employees.

## Show (vendor profile)
- Hero element: **Bank account history** vertical timeline (verified = success node, retired = muted node with dates, rejected/blocked attempts = destructive node with the request reference).
- Callback contacts card above the fold with source references ("Supply Agreement BRP-2025-014, §9.2"); adding a callback number requires a source reference.
- Amount history sparkline (12 months), requests table (latest 20).

## Form
- Single column, sections: Identity · Tax & country · Payment defaults · Notes; employee kind reveals "Linked member".

## Blocked details
- Table with masked account, country, reason, source request, who/when; "Unblock" is a destructive action requiring a reason.
