# ERP-ACCOUNTING-V11C-P9-OVERSEAS-WAREHOUSE-STORAGE-AGING-COST-V1-001

**Status:** FOUNDATION IMPLEMENTED / LOCAL GATES PASS / PRODUCTION SCHEMA + BACKFILL PENDING
**Parent:** ERP Accounting V1.1C
**Purpose:** materialize overseas-warehouse inventory aging storage cost from canonical lot inventory without double-counting historical P7 storage allocation.

## 1. Owner-frozen business rule

Storage aging cost is charged **per physical inventory unit**, not per SKU.

- Applies only to canonical overseas fulfillment warehouses.
- China factory / domestic inventory locations never incur this storage aging fee.
- First 90 days after lot putaway are free.
- Starting after day 90, each inventory unit incurs **CNY 1.00 per billable month**.
- A partial month is charged as a full month.
- Billing months are 30-day aging buckets:
  `billable_months = max(0, ceil((age_days - 90) / 30))`.
- Therefore day 90 = 0 months; day 91–120 = 1; day 121–150 = 2; day 151–180 = 3; etc.
- Monthly accrual is idempotent: each lot aging-month index may be posted once only.
- Previously accrued months are immutable; later inventory changes affect only future aging-month accruals.

Canonical amount:
`storage_aging_cost = physical_units_snapshot × CNY 1.00 × newly_due_aging_months`.

## 2. Warehouse scope

Use canonical Warehouse Master identity, not the current `warehouses.is_domestic` column.

Eligible role:
- `role_label = 海外履约仓`: US-NY, US-LA, US-SM, DE, CA.

Always excluded:
- FACTORY / `factory` — 中国工厂 / 国内履约仓.
- 007 / `007` — 本地库存统计仓.
- Any CN/non-overseas warehouse not explicitly classified by Warehouse Master.
- Unknown warehouse identity fails closed.

## 3. Inventory lot identity

`warehouse_inventory_lots.id` is not durable because confirmed Inventory Sync replaces lot rows.

Canonical stable Aging Lot Key:
`warehouse_id + product_id + stock_status + putaway_date`.

A monthly accrual identity adds:
`aging_month_index`.

Missing `putaway_date` fails closed and creates no cost.

All positive physical stock quantities count while physically stored, including sellable or defective status. Zero quantity is ignored.

## 4. Historical boundary

Production has only one confirmed Inventory snapshot per current overseas warehouse, all from September 2026.

Therefore:
- Current remaining units may be backfilled using their current lot quantity + putaway date, because those units are still physically present.
- Units that left the warehouse before the first canonical snapshot are not reconstructed and are not charged retroactively.
- Initial backfill is explicitly labelled as current-remaining-unit backfill.
- After P9 activation, immutable monthly accrual rows preserve future history.

## 5. P7 coexistence / no double count

P7 historical Production allocations remain immutable and are not deleted or rewritten.

For a warehouse/billing period after P9 activation:
- P9 Aging Storage becomes the canonical storage-cost source.
- P7 Storage Pool allocation must not also materialize storage cost for the same warehouse/period.
- Provider warehouse bills may still exist as reconciliation evidence, but they do not create a second Finance storage charge for the same period.
- Any overlap between P7 and P9 evidence must fail closed to review rather than silently double count.

## 6. Ledger design

Create an immutable `warehouse_storage_aging_accruals` ledger with at minimum:
- warehouse_id
- product_id
- stock_status
- putaway_date
- aging_month_index
- quantity_snapshot
- unit_rate_cny = 1.00
- amount_cny
- source_sync_batch_id
- source_reference
- accrual_as_of
- accrued_at
- backfill flag

Unique business key:
`warehouse_id + product_id + stock_status + putaway_date + aging_month_index`.

Existing rows are never revalued because quantity changes later.

## 7. Commands and gates

Dry-run-first command:
`inventory:accrue-storage-aging-cost [--as-of=YYYY-MM-DD] [--warehouse=slug] [--apply] [...exact guards]`.

Production apply requires process-local:
`ERP_WAREHOUSE_STORAGE_AGING_ACCRUAL_PRODUCTION_APPLY_ENABLED=true`.

Production apply guard must verify exact:
- as-of date
- warehouses seen
- lots seen
- eligible lots / eligible units
- new accrual rows
- amount CNY
- blocked/review counts
- deterministic plan SHA

Persistent automatic monthly accrual remains disabled until Owner UAT and a separate activation gate.

## 8. Finance boundary

P9 V1 first closes warehouse-level Aging Accrual accurately.

Do **not** materialize new Order `warehouse_cost` from P9 until there is auditable lot-consumption evidence linking outbound Order units to inventory lots.

Current Shipment data does not carry `warehouse_inventory_lot_id`; therefore direct order-level allocation is not yet authoritative.

Future bridge:
1. introduce durable FIFO/explicit lot consumption evidence for future outbound units;
2. allocate already-accrued lot storage cost to consumed units without duplicating warehouse expense;
3. only then bridge P9 into Order Profit.

No guessed Order→lot mapping is allowed.

## 9. Production read-only baseline — 2026-09-18

Current confirmed overseas inventory:
- DE: 20 lots / 1,052 units.
- US-NY: 14 lots / 1,184 units.
- US-LA: 58 lots / 2,678 units.
- CA: 3 lots / 141 units.
- No current US-SM lot snapshot.
- All current overseas lots have non-null putaway_date.

Using the frozen formula as of 2026-09-18:
- eligible >90d lots: 93
- eligible current units: 5,043
- cumulative current-remaining-unit preview: CNY 27,955
- DE: CNY 6,793
- US-NY: CNY 5,625
- US-LA: CNY 14,388
- CA: CNY 1,149

This is read-only preview evidence only; no P9 Production business-data write has occurred.

## 10. Roadmap

| Stage | Description | Status |
|---|---|---|
| P9-0 | Canonical contract + no-double-count boundary | ✅ Done |
| P9-1 | Pure aging policy + preview math | ✅ Done |
| P9-2 | Immutable aging accrual ledger + dry-run command | ✅ Done |
| P9-3 | Production inventory-data quality / historical boundary gate | ✅ Done |
| P9-4 | Guarded initial current-remaining-unit backfill | ✅ Code ready / Production gate pending |
| P9-5 | Monthly automatic accrual activation | ⏳ |
| P9-6 | Lot-consumption / FIFO evidence bridge | ⏳ |
| P9-7 | Order Profit warehouse_cost integration without P7 duplication | ⏳ |
| P9-8 | Owner UAT + Production closure | ⏳ |

ERP Changelog remains manual-only.

## 11. Foundation implementation evidence — 2026-09-18
- Added pure `WarehouseStorageAgingPolicy`: 90 free days, 30-day rounded-up billing buckets, CNY 1.00 per physical unit.
- Added immutable `warehouse_storage_aging_accruals` ledger keyed by warehouse/product/status/putaway-date/aging-month-index; lot row IDs are intentionally not durable identity.
- Added dry-run-first `inventory:accrue-storage-aging-cost` with deterministic `plan_sha`, explicit current-remaining-unit backfill mode and process-local Production apply flag.
- Production apply requires explicit `--as-of`, exact warehouse set, lots/units/rows/noop/blocked counts, CNY amount and exact plan SHA.
- Domestic/FACTORY/007 inventory is excluded through Warehouse Master role semantics rather than the currently unreliable `warehouses.is_domestic` field.
- P9 foundation writes no `order_cost_entries` and does not yet replace/allocate P7 Order `warehouse_cost`; Order-level integration remains blocked until auditable lot-consumption evidence exists.
- P9 Policy focused: **5 tests / 18 assertions PASS**.
- P9 Ledger/Command focused: **6 tests / 42 assertions PASS**.
- Pre-rebase Inventory/P7/Warehouse-cost regression: **63 tests / 487 assertions PASS**. After rebase to latest `main@ce10d765`, the P9-independent subset is **58 tests / 385 assertions PASS**; `InventoryWarehouseBillReconciliationV1Test` has one failure that reproduces identically on exact latest main, so P9 regression delta = 0.
- Finance full regression: **135 tests / 966 assertions PASS / 1 MySQL-only skip**.
- Vite production build: **PASS** (936 modules transformed).
- P9 migration discovered and applied successfully by isolated SQLite `migrate:fresh` both before and after latest-main rebase; `warehouse_storage_aging_accruals` is present and migration status is `Ran`.
- Architecture/provider/migration-index gate: branch **3 fail / 15 pass / 186 assertions**; exact-main baseline reports the identical three frozen-baseline failure names and counts, so architecture regression delta = 0. MigrationIndexName and ModuleProvider gates pass.
- No P9 Production migration, ledger write, env activation or Order Profit write has occurred.
- ERP Changelog remains unchanged because publication is manual-only.
