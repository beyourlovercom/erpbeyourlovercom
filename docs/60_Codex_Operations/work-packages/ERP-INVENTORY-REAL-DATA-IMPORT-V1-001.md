# ERP-INVENTORY-REAL-DATA-IMPORT-V1-001

Status: OWNER AUTHORIZED / IMPLEMENTATION ACTIVE

## Objective
Activate Phase 1 real warehouse stock truth without introducing Inventory V2 rules or automation.

Target real warehouses for this phase:
- `us-ny` — 美东
- `de` — 德国
- `us-la` — 美西

All other warehouses remain `never_imported`; absence of data must never become zero stock.

## Bounded implementation scope
1. Extend the existing XLSX adapter to accept real warehouse quantity header aliases such as `在库库存`, `当前库存`, `库存数量`, `Quantity`, `Quantity on hand`, `On Hand`, and `Standard_QoH`.
2. Preserve the canonical DB authority: `warehouse_inventory.quantity_on_hand`; do not add a duplicate generic quantity column.
3. Support `Damage_QoH` as defective quantity evidence when a source row provides Standard and Damage quantities separately.
4. Accept `Inbound Time` as putaway/inbound date input.
5. Preserve existing standard `Qty/数量 + 库存属性` behavior unchanged.
6. Add focused synthetic tests for the new header contracts and dual Standard/Damage parsing.

## Explicit exclusions
- No stock-state thresholds (`没货/库存紧张/有货`) in Phase 1.
- No alerts, Reservation, replenishment, procurement automation, WMS or automatic fulfillment.
- No Product/SKU fuzzy matching or automatic alias creation.
- No Production data mutation in the implementation package.
- No menu/global roadmap change.

## Verification
- Focused PHP tests for XlsxAdapter and Inventory Batch Sync compatibility.
- Existing Inventory Batch Sync regression must remain green.
- `git diff --check` must pass.

## Real-data gate after implementation
Product identities must resolve deterministically before any real inventory Confirm.
Then execute Full Snapshot in order: `us-ny` → `de` → `us-la`.

Before Production Product/Alias/Inventory mutation, freeze exact data-operation scope and stop at `READY_FOR_PRODUCTION` for Owner `pass`.

Final Phase 1 target:
`INVENTORY_REAL_DATA_IMPORT_V1 = CLOSED`
