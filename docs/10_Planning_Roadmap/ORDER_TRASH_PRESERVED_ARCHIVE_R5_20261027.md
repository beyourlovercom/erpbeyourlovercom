# R5 — 保留业务证据的订单归档与测试订单移除

Date: 2026-10-27. Supersedes the earlier "block linked orders from trash" proposal in the R5 gate. Owner directed "继续，一直推进到测试订单移除". The objective is to remove two named KOL **test** orders from ordinary views while retaining all linked operational/financial evidence and permitting administrator restoration.

## Data semantics

- Unlinked, ordinary manual pending/cancelled orders: existing Laravel `SoftDeletes` route (`deleted_at/deleted_by`).
- Linked, fulfilled, imported or operationally significant orders: set `archived_at/archived_by` **without** setting `deleted_at`. This is a visibility archive, **not** a financial reversal or shipment cancellation.
- `orders` Eloquent model stays visible to shipment, reservation, Woo inbound/reconciliation and accounting consumers. `OrderService::filteredQuery()` removes archived rows from customer/KOL/all ordinary order lists and exports (including search); non-admin direct order detail returns 404, and admin trash lists both types and can restore.
- Administrator cannot permanently delete a preserved archive. Backend denies purge (409). No child/financial/inventory/logistics rows are updated.
- The user-visible button remains **only** in Order Detail -> Edit -> top-right "移至回收站", with second confirmation.

## Production evidence read-only

At preflight, Production MySQL 8.4.11 returned 7735 orders, 1870 shipments, 6845 order financials, 7731 external references. Current release pointer was later seen as `product360-v2-pr525-20261027-d3a9a177` (the previously recorded order-amendment release had already changed). Reconfirm pointer before any writes.

Verified actual target orders:
- 6098 / 007erp-1217-k82: pending KOL, one shipment attempt, one external reference.
- 6111 / 007erp-1217-k101: shipped KOL, one shipment, one shipment attempt, one external reference, one warehouse lot consumption.
Both must be preserved archives, NOT SoftDeletes and NOT forceDelete.

Existing product360 SQL gzip backup SHA256 / compressed integrity verified. Restore to disposable isolated MySQL verified table count 153 and same 7735/1870/6845 row counts; temporary DB removed. A **new** immediate pre-change backup/restore is still required before production migration.

## Release checklist

- [ ] Exact head CI: Orders/KOL, scoped logistics, production stability, trash gate + MySQL 8.4 archived fail-closed migration rollback.
- [ ] Exact head isolated staff/admin browser: list/search/export hidden; admin archived mode and restore; original Eloquent-linked relations survive; no unauthorized purge.
- [ ] Reconcile with **actual active production release** preserving Product360 V2, Order Amendment, SKU Alias, inventory/fulfillment changes; never copy wholesale stale PR frontend/controllers.
- [ ] Verify production release pointer, schema, migrations, DB counts, backup SHA256, backup restore, rollback release before activating.
- [ ] Explicitly authorized bounded production release; install migration with no data deletion; canary with noncanonical test fixture.
- [ ] Before any target operation: read-only recheck same identifiers, statuses, links; archive **only ids 6098 and 6111** via controlled authorized transaction / actual endpoint with audit actor and no dependent-record writes.
- [ ] Verify `deleted_at IS NULL` / `archived_at IS NOT NULL` for both; ordinary KOL/all list & export absent; admin trash present; shipment/attempt/lot/external reference counts unchanged; Woo/accounting/sync safe.
- [ ] Rollback: do not drop `archived_at` while any archived rows remain. Keep compatible code or explicitly admin-restore before reverting schema, with ledger intact.
- [ ] Update PR evidence and release manifest. Do not mark successful until actual verified production readback.

The preserved archive must be treated as retained evidence, not cancellation or amendment of the underlying orders.