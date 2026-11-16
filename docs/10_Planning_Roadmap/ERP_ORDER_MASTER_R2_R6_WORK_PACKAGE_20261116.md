# ERP 订单总表 R2–R6 Work Package — 2026-11-16

## PROJECT POSITION

- Project: `beyourlovercom/erpbeyourlovercom` / existing Laravel + Inertia ERP, **not** SiteOps/Vben migration.
- Owner-approved R1 design: compact single-table page, no redundant top `全部订单 / 归档清单` tabs; five quick chips inside the workspace. Visual reference: `https://byl-erp-orders-r1-20261116.warmmesa1027.chatgpt.site`.
- Problem: `/orders/archive` was paginated at 50 with no database-backed search. An archived order beyond the first page appeared missing. The operational Orders query also uses `eligibleForOperations()`, intentionally excluding archived records, and therefore cannot be the source of a truly complete historical master.
- Target: independently query the canonical `orders` records, including all kinds, operational archive, legacy trash and soft-delete, with read-only server-side search, filtering, sorting and pagination.
- Scope: R2 archive search, R3 master query, R4 single-table Global Grid, R5 tests/UAT, R6 standalone PR. No order mutations, no release to Production.

## AUTHORITY / SAFETY

- Canonical facts remain in Orders, Address, Items, Shipments and User relations. No order copies or new database tables.
- `orders.view` required for all list access. KOL specialist own-order scope is preserved, manager scope is preserved. Trash and descendants of trashed parents remain invisible without `system.permissions.manage`.
- Existing shipment/allocation/operational status routes are unchanged; the historical master never feeds fulfillment queue APIs.
- R1 owner design PASS is **not** equivalent to live Dev UAT PASS. Owner must inspect a real Dev/UAT page after implementation before merge. Production release is a separate authorization gate.
- UI contracts: `BYL_COMPACT_WORKSPACE_V1`, `BYL_GLOBAL_DATA_GRID_VI_V1_1`, 30-tab workspace invariant, page header action placement.

## ACCEPTANCE SCENARIOS

1. A record archived on page 2 (example `007-1110-c42262`) is found on the first search-results page of `/orders/archive`, including matching by source number, recipient and SKU.
2. `/orders/master` includes canonical customer, KOL and derived orders; operational archives and trash are visible via quick filters to authorized users, without losing ordinary orders.
3. All searches, sort, filters, counts and pagination run on the server across the full authorized dataset, not only loaded AG Grid rows.
4. KOL specialists cannot learn about other users' orders by counts, filters or search. Non-admin staff cannot inspect trash records.
5. Owner sees a compact Global Grid with one search/toolbar row, one quick-filter row, no duplicated navigation tabs, fixed left identity/right actions, account-backed column settings.
6. No write action on the master page, no change to existing state/sync/routing behavior.

## ROADMAP HOURS

| Gate | Work | Estimated effective hours | Current stage |
| --- | --- | ---: | --- |
| R1 | Design mockup / Owner feedback | Completed before branch | Design PASS |
| R2 / P0 | Archive database search across pages, tests | 1.5–2.5 | Implemented; backend test pending |
| R3 / P1 | Historical read-only canonical query, access scope | 3–4.5 | Implemented; backend test pending |
| R4 / P1 | Global Grid compact UI, quick chips, columns | 3–4.5 | Implemented; typecheck/build passed |
| R5 | Dev backend integration, real browser Owner UAT | 2–3.5 | Pending |
| R6 | Branch/PR, review evidence, release prep | 1–2 | PR prep; no merge/Production |

Total initial engineering estimate excluding completed R1: **10.5–17 effective hours**. The table is a planning estimate, not a report of hours billed or worked.

## ESTIMATED CODEX HOURS

- Code change/review/test assistance: 6–10 Codex-effective hours, overlapping the engineering estimates above rather than additive.
- Remote integration and human UAT are gated by environment and Owner, so no artificial calendar completion date is promised.

## RELEASE / ROLLBACK

- Commit on a dedicated feature branch. Keep PR open until Dev integration and Owner live UAT PASS.
- Merge only through the ERP governance gate. No Production deployment without separate Owner authorization.
- Rollback is to disable/revert the new route/menu/page and the independent archive search change; no migration and no data changes to undo.
