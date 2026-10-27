# ERP Order Trash V1 — R5 Release Gate (2026-10-27)

Status: **CONDITIONAL NO-GO — CI, disposable MySQL 8.4 up/down, production read-only schema preflight and exact-current-code Dev negative-path smoke PASS. Remaining blockers: independent restore drill for production backup, owner decision for linked real test orders #6098/#6111, and explicit Production release authorization.** This is a release runbook, not deployment permission.

GitHub source: `beyourlovercom/erpbeyourlovercom`, Issue #520, Draft PR #523; record exact candidate HEAD and all CI run IDs immediately before approval. Main / production remains untouched by this work.

## Canonical product contract

- Staff with BOTH `orders.view` and `orders.edit` and access to a specific order may move it to trash **only via Order Detail → Edit → top-right Trash**; no list/batch deletion.
- Staff cannot see, restore, or purge trash. Administrator permission is `system.permissions.manage` for trash view, restoration, permanent removal.
- Business-safe soft deletion is restricted to unlinked pending/cancelled orders. If shipment or attempt, financial or cost entry, inventory reservation, warehouse consumption, affiliate referral, procurement, fulfillment parent/child, synchronization/external reference, or other recorded operational evidence exists, the backend must refuse with a clear conflict and **must not release reserves, undo postings, or unlink records**. Historical evidence is immutable.
- Administrative permanent deletion is also denied when any such link exists, **including legacy trashed orders**. Otherwise physical deletion is irreversible and deserves separate confirmation; never use direct SQL DELETE against live ERP.

## Cross-module compatibility findings

| Area | Existing query pattern | Risk without R5 guard | Release rule |
|---|---|---|---|
| Finance | `ProfitDashboardController`, `EloquentAccountingContinuousDeltaCandidateStore`: Eloquent `Order::query()` | Excludes trashed orders from finance reports/continuous-delta processing | Block any order with `financials` or `costEntries` from trash; do not interpret disappearing posted revenue as cancellation |
| Finance historical estimators | `EstimatedCostFallbackService`, `LastMileHistoricalProjectionService`: `DB::table` joins `orders as o` | Bypass Eloquent soft-delete filter; sample pool could include trashed orders | Preserve historical posted evidence; R5 guard prevents linked samples from being newly trashed; verify historical aggregates in MySQL and ensure no preexisting unsafe trash rows |
| Inventory | `EloquentInventoryReservationRepository`: loads `Order::query()` under lock | Active reservations become orphaned / cannot be reconciled when order hidden | Block orders with **any** inventory reservation record, including released historical records |
| Inventory lot accounting | `WarehouseLotConsumptionService`: `Shipment->order` | Soft-deleted order becomes null in consumption path | Block shipments and lot consumption evidence on any order being trashed |
| Logistics | `ShipmentService`, `CustomerShipmentProjection`, `WarehouseShipmentProjection`, fulfillment allocation | Shipment relation can disappear and work queues/statuses break | Block shipment, attempt, review, allocation, split/replacement and procurement links |
| Woo inbound / outbound | `OrderService::upsertInbound`: external reference lookup, then `Order::query()->whereKey(...)->firstOrFail()` | Soft-deleted Woo order would become invisible and inbound update would throw 404 | Block `order_external_references` and `manualSync` links before trash; verify no historical linked trash before production launch |
| KOL | `kol_affiliate_referrals`, `kol_collaborations` contain order_id | Commissions/collaboration lookup could lose canonical order | Block direct table links even when not mapped as Eloquent relation |

A global assertion that every raw SQL consumer honors SoftDeletes is **not** established. The V1 safety strategy deliberately forbids archiving financially or operationally linked orders rather than silently changing the meaning of historical reports. Follow-on support for archiving financialized orders requires a separately approved lifecycle and accounting contract.

## R5 CI evidence (2026-10-27)

- Safety branch implementation HEAD (before this documentation-only update): `b079d1c1b62f470ee68387d27fb165a919ec0c06`.
- **Order Trash V1 Gate run 40445033513: PASS**. Backend 8 tests / 75 assertions; Vue 108 tests; dedicated MySQL 8.4 container job `mysql8-rollback` PASS for migrate-up, fail-closed down with trash rows, clean down and re-up.
- **Orders/KOL run 40445033373, KOL Scoped Logistics run 40445033284, Production Stability run 40445033262: PASS.**
- Prior US Mac Chrome Employee/Admin and Owner visual UAT PASS on `2dc6f63...`, **before the R5 linked-business safety guard**. Do not present this as an exact-current-SHA live-browser pass. Rerun negative-path HTTP/Chrome against latest merged candidate before Production.

## Actual Production read-only preflight evidence (2026-10-27)

Verified using the self-hosted `mcp.beyourlover.com` OAuth MCP on US Mac mini, against the current `erp.beyourlover.com` Production Laravel environment. **All database inspection was inside an explicit MySQL READ ONLY transaction and rolled back.** No migration, data change, worker restart or service downtime occurred.

- Production MySQL 8.4.11; `orders` uses InnoDB; `orders.deleted_at` and `orders.deleted_by` do not yet exist.
- Rows: `orders=7735`, `shipments=1870`, `order_financials=6845`, `order_cost_entries=21385`, `inventory_reservations=20`, `warehouse_inventory_lot_consumptions=6`, `order_external_references=7731`, `kol_affiliate_referrals=3351`.
- Information-schema size estimate for `orders`: 1.52 MiB data / 2.39 MiB indexes, InnoDB.
- Planned index `orders_deleted_at_order_kind_index` and FK `orders_deleted_by_foreign` name conflicts: both zero.
- Backup inventory: 58 backup runs, 56 artifacts, daily schedule enabled. Database run #58 was `ready` at `2026-10-27 02:17:46`; full backup run #56 was `verified` at `2026-10-26 18:00:21`. These statuses are **not** proof of an independently restored production snapshot; restoration drill remains mandatory.
- Exact code `a186023229750be625e8028d04b27512c66d2053` in dedicated US Mac clean worktree: `php artisan test tests/Feature/Orders/OrderTrashV1Test.php` = 8 passed / 75 assertions; `Show.test.ts` = 54 passed; Vite build PASS. Isolated Tailscale Dev :55244 real Chrome: synthetic staff login, 409 guard for linked Woo order, unlinked soft-delete, staff 403, admin login/trash/restore, original linked test row retained — PASS. **The two real production IDs were not touched.**
- Real KOL orders, validated read-only: `6098 (007erp-1217-k82)` pending_shipment has 1 shipment attempt + 1 external reference; `6111 (007erp-1217-k101)` shipped has 1 shipment + 1 attempt + 1 lot consumption + 1 external reference. **Neither is V1 soft-delete eligible.** Their original cleanup objective requires explicit separate business-data treatment; do not force-delete, rewrite history or silently weaken guard.

## Production read-only preflight — before deploying code or running migration

Run queries against the intended production DB connection **read-only** and save results to controlled evidence (counts only; never publish credentials or PII):

```sql
SELECT VERSION() AS db_version, DATABASE() AS db_name;
SELECT COUNT(*) AS order_count FROM orders;
SELECT COUNT(*) AS shipment_links FROM shipments;
SELECT COUNT(*) AS reservation_links FROM inventory_reservations;
SELECT COUNT(*) AS financial_links FROM order_financials;
SELECT COUNT(*) AS cost_entry_links FROM order_cost_entries;
SELECT COUNT(*) AS external_links FROM order_external_references;
SELECT COUNT(*) AS affiliate_links FROM kol_affiliate_referrals;
SHOW CREATE TABLE orders;
SHOW INDEX FROM orders;
```

Confirm the exact schema/index/FK on `orders`, MySQL version, write traffic and table size, concurrent migrations and lock risks, sufficient disk space, and available verified snapshot/restore. Obtain a tested pre-change snapshot of **orders and its dependent business evidence**, not only the new columns. Before first production trash action verify all workers/queues and the Woo inbound cycle still resolve normal orders.

## Isolated migration verification

1. Use exact candidate HEAD on an **isolated disposable MySQL 8 database** (no production `DB_URL`, credentials, queues, mail, or shared storage).
2. Run migration up; assert nullable `orders.deleted_at`, `orders.deleted_by`, composite index and FK, and unchanged preexisting order counts/identities.
3. Create a synthetic unlinked order, trash and restore; assert counts in list/export, audit and access gates.
4. Create synthetic finance/shipment/reservation/affiliate/fulfillment-linked orders; assert HTTP 409 for soft delete and purge; assert all downstream records retained.
5. Test `down()` with **nonzero trashed rows**: must throw, without dropping schema or reviving rows. Restore/reconcile rows first, then test rollback to old schema on a fresh disposable dataset.
6. Confirm the exact head passes scoped Order Trash V1 Gate, Orders/KOL, KOL Logistics, Production Stability and relevant complete regression gates; repeat isolated browser negative path if backend guard changed after Owner's visual PASS.

## Production release and rollback safety

**Explicit Owner production authorization required.** Freeze the exact GitHub HEAD and approved deployment window. Prepare a rollback package from the immediately deployed production version. Never deploy straight from the old 2026-10-27 UAT SHA if subsequent safety commits changed the candidate. Apply schema migration only after verified backup and DB preflight; verify no changed row counts and no unexpected locks; canary a synthetic non-financial order if allowed by Owner; verify normal Woo synchronization and financial, logistics, inventory dashboards without mutations; monitor errors and queue health before staff feature visibility.

If no orders have entered trash, rollback can revert app code and (after snapshot) run the migration `down()` on a controlled window. If ANY orders have `deleted_at IS NOT NULL`, **DO NOT deploy old application code that lacks SoftDeletes filtering** and **DO NOT drop `deleted_at`**: both actions could make archived orders visible again. Disable new trash writes, keep current schema, use a forward fix/compatible app rollback or explicitly approved, auditable restoration of each trashed order before a schema rollback. The migration deliberately fails closed if trashed rows remain.

Post-migration verification only (column does not exist before up):

```sql
SELECT COUNT(*) AS trashed_count FROM orders WHERE deleted_at IS NOT NULL;
SELECT COUNT(*) AS trashed_with_external_ref FROM orders o
  JOIN order_external_references r ON r.order_id=o.id WHERE o.deleted_at IS NOT NULL;
SELECT COUNT(*) AS trashed_with_shipments FROM orders o
  JOIN shipments s ON s.order_id=o.id WHERE o.deleted_at IS NOT NULL;
SELECT COUNT(*) AS trashed_with_financials FROM orders o
  JOIN order_financials f ON f.order_id=o.id WHERE o.deleted_at IS NOT NULL;
SELECT COUNT(*) AS trashed_with_inventory_reservations FROM orders o
  JOIN inventory_reservations r ON r.order_id=o.id WHERE o.deleted_at IS NOT NULL;
```

Any positive linkage above blocks production release until reconciled. Ordinary archived manual orders remain retrievable to admins via `onlyTrashed()`.

## Signoff checklist

- [x] R5 safety code SHA and required GitHub CI confirmed (rerun on final merge candidate if commits or main change)
- [x] Targeted financial/inventory/logistics/sync raw query audit and direct-link guards reviewed (not a claim of exhaustive repository-wide SQL coverage)
- [x] Isolated MySQL 8.4 up/down, fail-closed rollback and re-up tested in dedicated CI
- [x] Production read-only schema/counters and available backup metadata verified (MySQL transaction read only)
- [ ] Independently verify a current encrypted production backup can be restored into an isolated, access-controlled test database; no production-data copy/restore performed during R5
- [x] R5 candidate's linked-order negative browser/API cases and positive restore verified on isolated US Mac
- [ ] Owner explicitly approves Production release
- [ ] Post-release canary, finance/stock/logistics smoke and rollback watch complete
- [ ] Owner approves a separate disposition for **linked** real KOL test orders #6098 / #6111. Both have operational evidence and cannot be soft-deleted by V1; **never force-delete or bypass this guard**
