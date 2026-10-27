# ERP Order Trash V1 — R5 Release Gate (2026-10-27)

Status: **NO-GO until exact-head safety CI, production read-only preflight, isolated MySQL migration/rollback exercise, and final owner release authorization.** This document is a release runbook, not permission to deploy.

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

- [ ] Latest exact SHA, merge conflicts and required GitHub CI confirmed
- [ ] Operational/finance raw query audit captured, direct-link guards reviewed
- [ ] Isolated MySQL 8 up/down and fail-closed rollback tested
- [ ] Production read-only schema/counters and backup **restore test** verified
- [ ] Current candidate's linked-order negative browser/API cases verified
- [ ] Owner explicitly approves Production release
- [ ] Post-release canary, finance/stock/logistics smoke and rollback watch complete
- [ ] Only then handle the real KOL test orders #6098 and #6111 after checking they are unlinked; if linked, refuse and escalate, **never force-delete**
