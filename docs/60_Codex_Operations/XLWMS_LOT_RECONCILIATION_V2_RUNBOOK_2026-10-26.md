# US-SM XLWMS OMS inventory lot reconciliation V2 — 2026-10-26

## Scope / non-production gate
- Backend-only extension of Draft PR #514; **only** canonical ERP US-SM warehouse bound to verified XLWMS OMS whCode. No other warehouse, Woo, outbound order, dispatch or payment writes.
- Existing encrypted API credentials reused server-side. Never check in secrets, signed URLs or sampled live customer data.
- V2 release flags: `XLWMS_LOT_RECONCILIATION_V2_ENABLED=false` (default), `XLWMS_INVENTORY_APPLY_ENABLED=false` (default), and `XLWMS_INVENTORY_SYNC_SCHEDULE_ENABLED=false` (default). Scheduling with apply not fully enabled is read-only.
- Run `php artisan migrate` **only inside approved isolated Dev/UAT** before V2 trial. Migration is additive and reversible; no existing lot is deleted.

## Policy
- Source truth for US-SM physical good stock QOH = XLWMS `productTotalAmount` (quality `stockType=0`); do not include box, returns, defectives, in-transit. The OMS `productStockDtl.availableAmount` and `lockAmount` must sum to QOH, with lock <= ERP confirmed local reserved.
- Vendor stock SKUs not in canonical/verified-PI ERP identity cause whole batch block. Warehouse catalog SKUs with **no stock ledger** remain **unknown, not zero**, ERP rows untouched. Multiple remote SKUs mapping to one ERP product cause block.
- Existing dated sellable lots are **not deleted or redated**. Dated lot reduction takes oldest first (FIFO). Quantity reductions persist with original lot id/date and V2 audit event.
- Provider stock **exceeding recorded dated lots** is physical stock of **unverified age**: QOH becomes authoritative, excess goes only into `xlwms_inventory_unverified_ages`, **never** create a missing-date `warehouse_inventory_lots` row, which would disrupt aging. Downstream available/reservation/OMS-dispatch safety caps exclude the unverified quantity.
- A touched lot that already has shipment lot consumption, storage-aging accrual, conflicting reservation, or a linked source sync batch requiring adjustment causes **all-or-nothing block**. All cross-SKU checks complete before any QOH or dated lot mutation. Applied journal is append-only unique per snapshot and ERP product; replay never double-reduces lots.
- Existing ERP stocks missing from complete OMS stock result are retained, because absence is not a confirmed zero. Full warehouse total need not equal vendor summed ledger until those identities are resolved; always report coverage and unverified/external-only rows.

## CLI and Dev acceptance
- Dry-run default: `php artisan xlwms:sync-inventory --dry-run`. Read-only OMS request, saved audit snapshot, no ERP QOH or dated lot update.
- For **isolated UAT only**, set `XLWMS_LOT_RECONCILIATION_V2_ENABLED=true XLWMS_INVENTORY_APPLY_ENABLED=true` for one command invocation, then run `php artisan xlwms:sync-inventory --apply`. Requires verified full OMS pagination, warehouse identity, SKU resolution, and lot safety. Do not enable production.
- Review `xlwms_inventory_snapshots`, `xlwms_inventory_snapshot_rows`, `xlwms_inventory_lot_reconciliations`, `xlwms_inventory_unverified_ages`. Confirm sums of dated sellable lots + unverified age = QOH per touched SKU, source timestamps and exact total, no duplicate journal entries.
- Repeat `--apply` and verify no lot drift or repeated reductions. Test negative/positive vendor differences, 5/3/8 unverified new Pillow stock, unmatched SKU block, protected lot block, other warehouses unchanged, available-reservation-dispatch guard.
- **Production formal release and migration require separate Owner approval after real Dev/UAT sign-off**. At handoff, no live Production inventory has been updated.
