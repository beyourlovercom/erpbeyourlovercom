# US-SM Gift stock visibility and OMS V2 reconciliation — 2026-11-14

Work package: ERP-USSM-GIFT-P0-P3-20261114
Execution provider: GPT_WEB + US Remote DC
Verification tier: MILESTONE_RELEASE (stock gate and fulfillment).
Source baseline: GitHub erpbeyourlovercom main 4972e1ef.
Scope: Dev/UI diagnostics, read-only production evidence, regression coverage. No production data writes or release.

## Observed Production evidence (read-only at snapshot 23)

- Warehouse US-SM ID 7; snapshot 23 V2 status `blocked` (fetched 2026-11-14 08:20:13 UTC). It has `unmatched_sku_count=4` and `blocked_sku_count=13` (12 per-product blockers plus an unmatched-stock summary blocker). This snapshot is NOT `applied`.
- Gift product VC549A (Product 1356; OMS SKU C549A): ERP 10 / reserved 0; OMS available 9, OMS locked 0; Gift effective 0 under fail-closed gate.
- Gift product VP757B (Product 1426): ERP 16 / reserved 0; OMS available 14, OMS locked 0; Gift effective 0 under fail-closed gate.
- Both Gift mappings active, priority 1 and 2; warehouse and product active.
- `ERP_INVENTORY_ROW_MISSING` (7): A1, MW35, VA617A, VA617A-Pink, VG515A, VX357A, VX357D.
- `LOT_CONSUMPTION_EXISTS` (5): C549A/VC549A, CL279B, VP676A, VP757B, VX760A.
- Provider SKUs without verified product identity (4): BV339A, MF414, TL278A, TL278B. TL278A and TL278B have active local aliases but no `oms_verified_at` or `oms_dispatch_approved_at` approval. MF414 cannot be assumed equal to MF414A.
- The snapshot also reports 3 `catalog_only` entries; these are NOT counted among the four unmatched stock SKUs.

## Implemented P0/P3

1. Keep authoritative, fail-closed effective inventory quantity unchanged.
2. Surface stock-gate reason codes `oms_snapshot_blocked`, `oms_snapshot_not_applied`, `oms_sku_not_confirmed`, `oms_no_available_stock`, `stock_age_or_reservation_gate` in the Gift projection.
3. Show disabled Gift candidates and accurate explanatory text in the allocation modal, rather than incorrectly reporting that all physical stock is out of stock. Reject forged submission server-side while V2 is blocked.
4. Show physical ERP stock alongside effective Gift stock and warn on divergence on US-SM clearance page. The diagnostic runs on each normal page read, not as a new background cron.
5. Automatically restore Gift pickability ONLY when the latest V2 snapshot is `applied` and real per-SKU available stock and other guards permit it. No manual mapping re-entry is needed.

## P1/P2 Production reconciliation still gated

- P1 inventory truth has **not** been repaired on Production. Inventory row creation, alias verification, or changing protected lot or consumption records must not be invented or silently applied.
- Obtain authoritative SKU identity proof for BV339A, MF414, TL278A/B.
- Reconcile seven missing ERP inventory records with current OMS SKU and product identity; protect existing reservations and shipment history.
- Reconcile five SKU batch ledgers while preserving consumption and historical aging/accrual evidence. Do not set V2 snapshot status to applied by hand or delete consumption rows.
- Only after full OMS V2 integrity gates pass, perform a controlled fresh snapshot/compare/apply, then re-read both gift SKUs and test a non-writing allocation preview.
- Production release requires separate Owner approval and exact SHA/rollback.

## Checks

- PHPUnit `WarehouseClearanceGiftPoolV1Test`: 7 tests / 72 assertions PASS.
- PHPUnit `FulfillmentAllocationV11GiftZoneTest`: 8 tests / 80 assertions PASS.
- Vitest `FulfillmentAllocation.test.ts`: 20/20 PASS.
- TypeScript `npm run typecheck`: PASS.
- `npm run build`: PASS; workspace 30-tab artifact check PASS.
- 007windows Dev browser visual acceptance: pending before the Owner UAT gate.
