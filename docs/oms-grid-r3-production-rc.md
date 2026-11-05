# BYL ERP OMS Grid R3 — Production Release Candidate / Controlled Rollout

Date: 2026-11-05. Branch/PR: feat/byl-erp-oms-grid-v1-prototype / PR #628.
Status: Code/isolated-UAT approved for controlled release review; **NOT a Production deployment approval**.

## Frozen Owner decision
- Finish real Orders Grid R3 first, prove the shared AG Grid Community engine can work in controlled Production.
- Only after actual R3 Production canary acceptance proceed to Inventory → Domestic/Overseas Logistics → Products → KOL → Accounting → remaining lists.
- Keep the already approved compact OMS appearance: one flat header row, one floating-filter row, pinned L/R columns, resizable columns, account preferences, maximize, one pager; order item rows are grouped into canonical parent Orders.

## R3 functionality acceptance
1. Non-production Orders grid at /orders?oms_grid=1 is compatible with existing Orders permissions, workflow status PATCH & conflict semantics and ERP canonical detail links.
2. One canonical Order has N SKU item rows. The same ERP number appears on each item line, with only one editable status/action row. No duplicate Orders are created.
3. ERP query is server paged by canonical Orders, not visible SKU rows. Page size 20/50/100; ALL only for <=200 filtered Orders.
4. In-table global search supports numeric fragments; independent floating-column text filters use full server dataset. Combined SKU/product-name/quantity filters must match the **same** OrderItem row; only matching SKU rows are shown/exported, but original order totals and identity are retained.
5. Import XLSX template: one row per SKU, same external_order_number groups one Order, first-row order metadata inherited; preflight with errors, 15-min signed file proof, explicit confirm, transactional source-number claims and ERP existing create/audit service. No Woo/shipping side effects.
6. Export matches current server query, one row per matching SKU; XLSX limited to 2000 Orders / 5000 SKU lines, streaming CSV for larger queries. Both formats preserve ERP/source order number. Import and export are independent server-permission features.

## Four Production rollout controls (fail closed)

Environment/config entries (stored in environment and config cache, not changed in this PR):
- ERP_OMS_GRID_PRODUCTION_ENABLED=false
- ERP_OMS_GRID_CANARY_USER_IDS= (comma-separated **approved ERP numeric account IDs**, no valid IDs means nobody)
- ERP_OMS_GRID_PRODUCTION_EXPORT_ENABLED=false
- ERP_OMS_GRID_PRODUCTION_IMPORT_ENABLED=false

These flags are set to OFF by default. A query parameter such as oms_grid=1 cannot bypass them.

| Phase | Config | Visible behavior / allowed scope |
|---|---|---|
| P0 Deploy dormant | All OFF | Existing ERP order lists unchanged, new endpoints 404 |
| P1 Read-only canary | production_enabled ON + exact approved user IDs, import/export OFF | Named staff see "体验 OMS Grid"; other staff continue legacy. No OMS bulk writes or exports |
| P2 Export canary | Add production_export_enabled ON | Only allowlisted users with orders.view may export |
| P3 Import canary | Add production_import_enabled ON only after independent approval | Only allowlisted orders.create+orders.view, not KOL-only specialists, may preflight and explicitly confirm a **small approved real customer Order batch** |
| P4 Wider adoption | Separate Owner acceptance | Expand exact user IDs, never blanket-enable from an empty allowlist |

Production source code must never default to an all-users ON state. Rollback is immediate via changing production_enabled back to false, clearing config cache/reloading configuration. Keep the previous release and hashed Vite assets for code rollback.

## Required predeploy / merge controls (no shortcuts)
1. Exact PR HEAD pinned, branch clean, review complete, GitHub CI gate green and exact expected HEAD unchanged.
2. Current Production release code/DB/version backed up; verify new import-ledger migration with dry-run, schema review, rollback order, duplicate/conflict behavior.
3. Confirm actual ERP server gzip/Brotli and correct cache policy. Public login asset probe 2026-11-05: gzip already enabled, hashed assets currently cache-control max-age=14400 (4h); immutable one-year policy may be applied **only** on hashed /build/assets/ files, never HTML/authenticated API.
4. Deploy code and schema migration with all four flags OFF; **read back** old /orders and authenticated endpoint 404 under a valid ERP role, verify Woo/Finance/Logistics untouched.
5. Explicit Owner PASS for P1 enablement and a named canary ID; verify real Production order totals, multi-SKU same-order rows, saved views, status visible/read-only, search, 50/100 page, real error responses, data access isolation and speed from China Mac.
6. Explicit Owner PASS for P2/P3; do not execute any real bulk import or workflow write as part of just a read-only canary.
7. If any mismatch is found, immediately revert feature flag, confirm old list still works, record issue and stop further expansion.

## Verified isolated UAT evidence

- Current UAT: US Mac mini separate SQLite and Tailnet Nginx port 8200 with gzip/immutable hashed asset caching, no customer information copied into UAT.
- Data: 1,500 synthetic scale Orders plus earlier synthetic rows; browser load remained sub-second locally with 50/100 pagination.
- Browser routes authorized by a **local test role**: OMS Orders / legacy Orders / Customer Orders / KOL Orders / Products / Inventory / Logistics / Accounting / Order Detail all HTTP 200, no static asset failures or JS exceptions. No Production role or DB was changed.
- Unit + feature regression includes Production simulated OFF-by-default and exact user-cohort authorization; import/export independently disabled; existing permissions and KOL-only boundaries preserved.
- Real remote Dev DNS: deverp.beyourlover.com **does not resolve** from China Mac on 2026-11-05; therefore no remote Dev real-record UAT is claimed. Owner's approved browser visual acceptance and isolated functional UAT are evidence, not a substitute for a Production canary.

## R4+ migration order — do not start before R3 Production canary PASS

| Stage | Target | Minimum acceptance |
|---|---|---|
| R4-A | Inventory overview, Inventory Products, overdue-stock detail | Same data quantities and exclusions/export, role scopes |
| R4-B | Domestic fulfillment, overseas approval and shipping lists | Quick-fill, warehouse review, stock gate, business status/409 logic |
| R5-A | Product lists & product records | SKU/barcode, image links, permissions and filters |
| R5-B | KOL library and fulfillment lists | owner/team visibility, commission scope and manual order action gates |
| R5-C | Accounting and order-cost listings | finance-only permissions, currency, costs without fake totals |
| R6 | Other list inventory, template cleanup | Regression/release checklist for every migrated page |

All migrations use the single Grid Standard V1 engine and keep domain workflows unchanged. Confirm target-specific server query performance instead of assuming the Orders query benchmark applies to all modules.

## Final main-rebase compatibility gate — 2026-11-05

- Source of truth: newest `origin/main` at `efc8b600` merged into the PR branch. Only one content conflict: `resources/js/Pages/Orders/Index.vue`.
- Resolution preserves latest main's **#631 Woo sync notice/button removal** from the Orders page. Only the new `体验 OMS Grid / 返回原列表` switch remains, and only in the allowlisted canary context.
- Merge commit `64ff3e256f859c6b752816ec72969f4ae563c557`. `origin/main` is now an ancestor; GitHub reports mergeable.
- Combined Orders / UI Preferences / Sync Workspace / Logistics Tracking / Tracking Auto tests: **133 total, 1,718 assertions, 1 intentional skip** (separate live Woo Canary test not executed).
- Front-end six focused suites: **32/32 PASS**. Typecheck, Vite build PASS.
- Real authenticated Chrome on US Mac isolated SQLite + private nginx: OMS Orders, legacy Orders, Customer, KOL, Products, Inventory, Logistics, Accounting and Order Detail all HTTP 200, 0 JavaScript/static-asset failures; same-SKU-line combined filter confirmed again.
- GitHub PR ready for code review; **not merged or deployed**. Current Production canary gates all default OFF. No Production data/role/config writes.

## Updated remaining roadmap / engineering hours

| Gate | Action | Remaining estimate |
|---|---|---|
| R3-RC | Final code review and exact HEAD GitHub checks | 0.5–1 h |
| R3-P0 | With separate owner approval: merge main and deploy dormant code + verified isolated migration, feature flags OFF | 1–2 h |
| R3-P1 | With separate owner approval: allowlisted read-only Production canary, true data/performance/permissions acceptance, verified OFF rollback | 1–2 h |
| R3-P2/P3 | Separately approve export, then real bulk-import single-small-batch canary only if needed; no inferred permission | 1–3 h |
| R4-A | Inventory overview, stock products and aged stock list | 4–7 h |
| R4-B | Domestic + overseas Logistics/fulfillment lists, unchanged shipment semantics | 5–9 h |
| R5-A | Product listings and SKU/barcode filters | 2–4 h |
| R5-B | KOL lists and associated permissions | 2–4 h |
| R5-C | Accounting/finance list views | 2–4 h |
| R6 | Remaining lists audit, regression, cleanup | 6–12 h |

R3 estimates are engineering time, not a promised delivery time. All R4+ begins only after Owner approves and verifies R3 Production canary.
