# ERP-GLOBAL-FULFILLMENT-PLAN-PREVIEW-001

## Owner-frozen business rules

- Remove the global no-split assumption.
- Evaluate both unsplit and split fulfillment plans by total logistics cost.
- Split is human-review only; the system may recommend but must not auto-execute split shipments.
- Prefer local-country warehouses, then approved nearby warehouses, then China Factory as final fallback.
- China Factory shipping channel is `SD经济挂号-带电` from `仓库-物流-国内小包费用.xlsx` / same-named sheet.
- US military destinations must ship from US warehouses; China Factory is forbidden.
- Non-military AK/HI orders require US-vs-China cost comparison; do not offer one-click US warehouse adoption before review.

## Current execution boundary

Current schema has `shipments.order_id UNIQUE`, `Order::shipment(): HasOne`, and Inventory Reservation allows only one active whole-order reservation. Therefore this WP is read-only planning plus safe single-shipment recommendation guards. It MUST NOT add migrations or change Production schema.

Split-plan adoption requires a future explicit Multi-Shipment + Multi-Reservation Schema Gate.

## Implementation

- `warehouse_routing.php` V5: split preview enabled, auto split disabled, factory fallback, US military and AK/HI constraints.
- `carrier_routing.php` V5: China Factory fixed to `SD经济挂号-带电`.
- `WarehouseRoutingPolicy`: destination constraint helpers and factory/split policy accessors.
- `WarehouseRecommendationService`: Military local-US hard rule and AK/HI cost-review block.
- `FulfillmentPlanPreviewService`: enumerates single, factory, and line-level split plans, attaches cost-evidence slots, ranks costed plans only when currencies match.
- `LogisticsController`: exposes `fulfillment_plan_preview` per pending order.

## Safety

- No schema changes.
- No Production data mutation.
- No automatic split execution.
- No automatic Factory execution.
- No auto-ship.
- PR #245 Germany policy remains undeployed until this superseding policy is reviewed/deployed.
