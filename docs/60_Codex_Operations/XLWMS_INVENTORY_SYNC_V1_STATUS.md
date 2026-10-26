# XLWMS inventory sync V1 status

This backend-only package adds `xlwms:sync-inventory` for the canonical `US-SM` warehouse. It reads the Lingxing OMS v1 product and integrated-inventory pages, persists a sanitized snapshot and audit event, and never writes orders, shipping, WooCommerce, or another warehouse.

## Operation

```text
php artisan xlwms:sync-inventory --dry-run
php artisan xlwms:sync-inventory --apply
```

Dry-run is the default behavior. `--apply` is rejected unless `XLWMS_INVENTORY_APPLY_ENABLED=true` and then updates only after every SKU is validated in one transaction. ERP `quantity_reserved` is preserved. Missing OMS ledger rows are `not_ready`; they are not interpreted as zero. Existing lot/putaway state, lot-consumption history, or a reservation conflict blocks the complete apply and leaves only the diagnostic snapshot. No Product Master records are created.

The reader sends the exact configured OMS `whCode`, `stockType=0` (GOOD), and page parameters to the two read-only allowlisted endpoints. Product pages are account-level and are not required to return `whCode`; inventory pages must return the exact warehouse code. It rejects non-200 business codes, shape drift, changed totals, truncation, duplicate SKUs, unknown quality, negative/non-integer quantities, pagination gaps, and warehouse-scope mismatches. Sellable QOH is `productTotalAmount` and must equal `availableAmount + lockAmount`; the provider's `productStockDtl` has no `totalAmount` or `lockedAmount` field. Box, return, defective, and in-transit quantities are not sellable stock.

## Setup and current status

Configure the existing encrypted `warehouse_openapi_connections` row for the ERP warehouse whose canonical code is `US-SM`, with provider `xlwms` and an explicit `oms_warehouse_code`. No credentials are committed, read from local `.env` files by this package, or printed/logged. The worktree has no live OMS evidence; therefore this document does not claim that synchronization has occurred.

Optional scheduling is disabled by default. Set `XLWMS_INVENTORY_SYNC_SCHEDULE_ENABLED=true` and choose `XLWMS_INVENTORY_SYNC_SCHEDULE_MINUTES=10` or `30`; the scheduler uses `withoutOverlapping()` and runs read-only snapshots by default. Only when XLWMS_INVENTORY_APPLY_ENABLED=true does it invoke --apply. This remains disabled until local/UAT validation and safe lot reconciliation.

V1 accepts exact canonical SKU matches and existing source-verified Shimai PI aliases (source_type=smgj_pi backed by original warehouse PI). Unverified aliases, guessed translations, and auto-created Product records are rejected as unmatched. Exceptional warehouse-specific SKU mapping must use the already approved parallel mapping integration in PR #508; this package deliberately creates no competing mapping table.

## Live read evidence and rollout boundary (2026-10-26)

A separate **read-only** owner-authorized Dev API probe using existing encrypted US-SM credentials returned HTTP 200/business 200 and complete 2-page warehouse-scoped records for 17 distinct good-stock SKUs, aggregate productTotalAmount=102. The production ERP previously contained 21 US-SM stock rows (171 units) and 15 aged lot entries. Vendor missing-ledger SKUs are never assumed to be zero. The current app's stock-lot aging and inventory lot consumption must not be overwritten by a QOH-only API; the service therefore blocks canonical QOH apply if any mapped SKU has an aged lot, inbound date, or consumption trail. Real inventory sync **has not been applied to Production**; a read-only snapshot does not imply live quantities were changed.

Proof: tests/Feature/Logistics/XlwmsInventorySyncServiceTest.php and tests/Unit/Logistics/XlwmsInventoryPageParserTest.php. Real Dev read was observational only, not an execution of this unmerged service.
