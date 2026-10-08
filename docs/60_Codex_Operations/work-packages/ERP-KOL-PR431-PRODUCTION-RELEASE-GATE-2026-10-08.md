# ERP KOL PR #431 — Production Release Gate (read-only preparation)

Status: **BLOCKED — no Production release authorization**
Canonical repository: `beyourlovercom/erpbeyourlovercom`
Merged main: `db1a192cc31d8e545f690ad220e6ee59b3ac68d2`
Scope: manual KOL order ERP identities and KOL specialist/manager order authorization.

## Verified facts

- PR #431 merged to main; its last head was `4559dd750d5c88cc86d820716199a769bc8375ce`.
- PR CI completed successfully (MySQL8 runtime and SQLite regression).
- Merged diff changes six paths:
  - Runtime: `app/Modules/Orders/Application/KolOrderAccessScope.php`
  - Runtime: `app/Modules/Orders/Application/OrderService.php`
  - Runtime: `app/Modules/Orders/Experience/Controllers/OrderController.php`
  - Tests: `tests/Feature/Orders/KolOrderOwnerScopeTest.php`
  - CI: `.github/workflows/erp-production-stability-v1.yml`
  - Tests: `tests/Feature/ProductionStability/LogisticsWaitStockQueryBudgetTest.php`
- PR does not introduce database migrations.
- Public `https://erp.beyourlover.com` returned HTTP 200 when probed from China Mac. This **does not establish exact Production source revision**.
- Historic operations documentation places Production `current` on US Mac at `/Users/a/AI/production/erp.beyourlover.com/current`. That path is not local to China Mac.
- A read-only SSH attempt from China Mac failed authentication; therefore **the actual active release SHA, backup currency, rollback release, and current runtime compatibility remain unverified**.

## Hard gates before any Production code deployment

1. Obtain **authenticated read-only evidence** from the actual runtime: `current` symlink target, immutable release manifest/revision, PHP/runtime metadata, and whether code is built from Git or packaged copy. Do not infer this from public HTTP 200.
2. Confirm Production baseline includes the Orders/KOL service contracts required by #431. Compare all changed PHP files against the **actual** baseline, including route signatures, owner scope and identity allocation. Main may contain unrelated unreleased changes: **never bulk-deploy main without explicit scope review**.
3. Capture verified database backup time/identifier, restore method, immutable previous release pointer and rollback steps. No service or database write during the preflight.
4. Prepare a bounded **runtime-only** candidate overlay matching the exact baseline and three PHP source files above; preserve live Production environment/secrets/storage, existing runtime dependencies and unrelated features. Build and test the overlay in isolation. Do not assume blindly copying three files is safe.
5. Review test evidence: Emma sees own KOL orders only; KOL manager sees all KOL orders but not customer orders; generic direct URLs, edit/marker/export/customer-create cannot bypass; manual KOL order gets a unique `erp_order_id`; collaboration identity unchanged; operational roles unaffected.
6. Obtain **separate explicit Owner Production code deployment approval**, identifying exact candidate release SHA/artifact and exact active base. Do not run migrations or backfills as part of the release.
7. Deploy only after approval, then read-only smoke as Emma and manager; if abnormal use prepared rollback. Record evidence.

## Historical data: separately gated

Existing rows with `erp_order_id IS NULL` (including reported Mason Ashley) are **not fixed by new-order allocation logic**. Historical repair requires a read-only inventory by order ID/kind/date/source, a collision-free proposed identity list and explicit approval for a transactional auditable one-time update. Orders shown as `Owner(andy)` must **not** be silently assigned to Emma. Define accountable owner vs creator and obtain assignment approval separately.

## Current next action

Remain blocked on authenticated Production release/backup/rollback evidence. China Mac may continue GitHub review, isolated tests and documentation. No US DC troubleshooting in this workstream, no Production deploy, migration, historical mutation, or Woo sync.
