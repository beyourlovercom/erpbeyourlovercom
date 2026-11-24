# ERP Unified Order Identity V2 — ERP 统一订单编号标准 V2

Status: Owner-approved naming standard; implementation pending controlled release.
Scope: ERP1 and ERP2 share the same backend identity contract.

## Canonical IDs for new orders

| Type | Contract | Example |
| --- | --- | --- |
| WooCommerce customer | C + original website order number + -007 | C40618-007 |
| KOL root | K + YYMMDD + daily sequence (minimum two digits) + -007 | K26092807-007 |
| Customer original fulfillment child | parent + uppercase suffix | C40618-007A |
| KOL original fulfillment child | parent + uppercase suffix | K26092807-007A |

C = Customer; K = KOL. Department 007 is fixed in V2. The KOL date uses the actual China-time creation clock, not the editable business date. The daily counter is global across staff, begins at 01, continues existing root orders on rollout day, and expands beyond 99. A transaction and locked daily counter row serialize allocation. Soft-deleted orders do not release IDs. Existing internal and external order IDs are not changed.

## Compatibility and release

- Existing ERP IDs remain unchanged; Woo re-import is idempotent and does not rename old orders.
- Manual customer IDs without a website source retain the existing contract; historical imports retain their legacy generation until separately migrated.
- Warehouse reference adapters accept new C/K and historical formats. Existing warehouse and carrier contracts are preserved.
- No bulk renaming of existing orders, including sent orders, is authorized by the new-order rollout.
- Database change: additive kol_daily_order_sequences table. Run only this approved migration after backup and schema preflight; no historical order writes.
- Rollback: restore previous release, retain additive counter table and allocated identities. Do not drop the table after live allocation.
- R6 historical identity migration requires a separate plan, collision preflight and provider reference protection.
