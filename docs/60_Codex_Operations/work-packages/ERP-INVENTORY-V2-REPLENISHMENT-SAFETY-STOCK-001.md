# ERP-INVENTORY-V2-REPLENISHMENT-SAFETY-STOCK-001

STATUS: IMPLEMENTED / VERIFICATION IN PROGRESS
EXECUTION_PROVIDER: GPT_WEB
OWNER_AUTHORIZATION: Owner explicitly authorized continuing Inventory V2 after Assisted Allocation into replenishment recommendation / Safety Stock.
BASELINE: `main@7c236b2ed7652a339b94460f825d8f717917c807` after PR #199.

## Scope

Turn canonical Product × Warehouse inventory plus recent dispatch history into replenishment analytics without fabricating warehouse policy.

Canonical demand source:
- `order_items.product_id`;
- canonical current `shipments.warehouse_id` plus immutable historical `shipment_attempts.warehouse_id`;
- non-null `shipped_at` within the trailing 90 days from both sources;
- quantity = shipped Order Item quantity per physical shipment attempt, so a real re-shipment is counted again rather than lost after the current shipment record resets;
- rows older than 90 days are excluded.

Metrics:
- 90-day shipped total;
- monthly average = 90-day total / 3;
- daily average = 90-day total / 90;
- current available stock = `quantity_on_hand - quantity_reserved`;
- days of cover = available / daily average;
- expected inbound = existing `expected_restock_qty`.

No fixed replenishment thresholds are invented. Each warehouse has an explicit nullable replenishment policy.
## Warehouse policy

The three values are configured together or left unconfigured:
- replenishment lead time days;
- safety stock days;
- target cover days.

Invariant: target cover days must be at least lead time + safety stock days.

When configured:
- safety stock qty = `ceil(daily_average × safety_stock_days)`;
- reorder point = `ceil(daily_average × (lead_time_days + safety_stock_days))`;
- target stock = `ceil(daily_average × target_cover_days)`;
- reorder is evaluated against canonical available quantity;
- recommended restock = `max(target_stock - available - expected_restock_qty, 0)` when at/below reorder point.

When policy is not configured, analytics remain visible but recommendation is explicitly `补货策略未配置`; the ERP does not turn missing policy into zero or a guessed recommendation.

## UI

Existing Inventory surfaces only; no L1/L2 menu change:
- `/inventory/products` exposes 90-day demand, monthly/daily velocity, cover, safety stock, reorder point and recommended restock;
- warehouse detail exposes policy inputs and compact SKU replenishment results;
- existing stock-status thresholds remain separate from replenishment intelligence.

## Production

No Production deployment or Production policy mutation is authorized by this Work Package. Production activation remains subject to the normal consolidated release gate.
## Verification evidence

Latest reconciled baseline: `origin/main@88c037693a982a2674bb9853d1977e619ba03ac2`.

- `InventoryFoundationM1Test`: PASS, 162 assertions; includes current shipment + immutable historical re-shipment attempt demand.
- Inventory Reservation + Assisted Fulfillment + Warehouse Recommendation + Inventory Batch Sync integration: PASS; combined focused run 401 assertions plus 6 unit passes.
- Inventory UI: PASS, 3/3.
- Production frontend build: PASS.
- `git diff --check`: PASS.
- Architecture: 5/8 PASS. The remaining three failures are repository-baseline drift already present on `origin/main`: frozen module-directory list, application-layer file list, and `GlobalFeedbackIsland.test.ts` frozen FE hash. This WP reconciles migration hashes/file-set needed for its new migration but does not rewrite unrelated architecture baselines.

No Production deployment or Production replenishment-policy data mutation occurred.
