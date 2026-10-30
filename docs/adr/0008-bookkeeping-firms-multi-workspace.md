# ADR-0008 — Bookkeeping firms as members of client workspaces in MVP; Practice entity in v1

- Status: Accepted (2026-10-22)
- Related: PLAN §3, §5.2; DOMAIN_CONTRACT §3

## Context
The ICP includes small bookkeeping firms that pay bills for many clients. The foundation offers multi-workspace membership and a workspace
switcher; billing is per workspace.

## Decision
- MVP: each client company is a workspace; the bookkeeper is invited with role `bookkeeper` (prepare, verify, mark paid; never approve).
  The client keeps approval authority (owner/admin/approver). Evidence records which firm member did what.
- v1: a `practices` entity groups client workspaces for a cross-client queue, consolidated billing and policy templates — additive, with no
  change to domain tables (all remain workspace-scoped).

## Consequences
- Positive: no extra tenancy complexity in MVP; clear segregation of duties between firm and client.
- Negative: bookkeepers switch workspaces to see each client's queue until v1.

## Alternatives considered
- A firm-level tenant owning client sub-tenants from day one — rejected: changes foundation tenancy for all three products.
