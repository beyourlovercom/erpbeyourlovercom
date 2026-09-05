# ERP-INVENTORY-V2-STOCK-STATUS-ALERTS-001

Status: IN_PROGRESS
Program: BYL ERP / Inventory V2
Branch: `feature/erp-inventory-v2-stock-status-alerts-001`
Base: `main@c945416a873686271d882c5c3b6c126337e1882d`

## Mission

Turn canonical Inventory quantity truth into deterministic operational stock status and dashboard alerts.

## Frozen rule

Status is derived from canonical `quantity_available` only:

- `never_imported` warehouse truth remains `库存未导入` / unknown and is never treated as zero.
- `quantity_available <= 1` → `out_of_stock` / `没货`.
- `quantity_available 2..9` → `low_stock` / `库存紧张`.
- `quantity_available >= 10` → `in_stock` / `有货`.

`quantity_available = max(quantity_on_hand - quantity_reserved, 0)` remains canonical.
No stored stock-status column is added.
## Delivered surface

- Inventory dashboard counts `没货 / 库存紧张 / 有货` SKU rows.
- Product × Warehouse inventory rows expose status and label.
- Warehouse detail inventory rows expose the same status and label.
- Status automatically reacts to Reservation because it uses available quantity.

## Explicit exclusions

This WP does not send external notifications and does not implement replenishment forecasting, procurement triggering, warehouse assignment, WMS or automatic fulfillment.

## Acceptance

- exact boundary tests for 0 / 1 / 2 / 9 / 10;
- pre-existing reserved stock must affect the status through available quantity;
- never-imported warehouses must not inflate out-of-stock counts;
- existing Inventory M1, batch sync and Reservation regressions remain green;
- no migration and no duplicate inventory truth.
