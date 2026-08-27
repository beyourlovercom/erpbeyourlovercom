---
type: mvp-spec
project: BYL ERP
release: MVP1
version: 1.2
updated: 2026-08-27
---

# MVP1 — Manual Fulfillment / Tencent Docs Replacement

## Goal

Replace the current Tencent Docs order/fulfillment handoff with the smallest useful ERP workflow while preserving one canonical Order truth and the existing human decision process.

MVP1 is deliberately **manual-first**:

```text
HUMAN DECIDES
→ ERP SHOWS CANONICAL DATA
→ ERP RECORDS THE DECISION
→ ERP EXECUTES / TRACKS THE MANUAL WORKFLOW
→ ERP PRESERVES AUDIT
```

Automatic warehouse selection, automatic carrier selection, rate shopping, ocean-freight decisions and WMS automation are post-MVP1. Their future contracts must be anticipated, but the engines are not required to close MVP1.

Detailed canonical Order semantics remain governed by `16_BYL_ERP_CANONICAL_ORDER_FINANCE_KOL_DATA_CONTRACT_V1.md`.

## Canonical rules

```text
CANONICAL_ORDER = orders.id
BUSINESS_ORDER_NUMBER = external_order_number
ERP_TECHNICAL_NUMBER = internal_order_number / generated / unique / hidden by default
ORDER_KIND = customer | kol_fulfillment
ORDER_SOURCE = independent from order kind
PRODUCT_IDENTITY = Product Master product_id + SKU
INVENTORY_TRUTH = Warehouse-level Inventory
V1_SHIPMENT = 1 Order : 1 Shipment
```

Customer Orders and KOL fulfillment Orders are rows in the same canonical `orders` table. Affiliate-attributed consumer sales remain customer Orders plus attribution; they are not KOL fulfillment Orders.

## MVP1 workflow

```text
Manual order creation / existing Order
→ canonical orders.id + Order Items + Shipping Address
→ show Product Master identity and Warehouse-level Inventory
→ operator manually selects fulfillment Warehouse
→ Logistics work queue / shipment workspace
→ operator manually selects/records carrier + service
→ operator records tracking + shipped time
→ mark Shipment shipped
→ Manual Sync queue where applicable
→ operator updates website manually
→ mark manual sync complete / reopen with Audit if needed
```

The acceptance criterion is operational, not algorithmic: a real operator must be able to complete the Tencent Docs fulfillment workflow end-to-end inside ERP without relying on Tencent Docs.

## Required MVP1 capabilities

### Product

- Product Master is the canonical product identity;
- Order / Inventory / Logistics reference the same `product_id` / SKU;
- Product weight/dimensions remain Product Master facts for later logistics costing;
- MVP1 does not automatically choose a shipping method from product facts.

### Orders

- one shared manual create implementation;
- customer/KOL fulfillment classification;
- human-entered business order number as the primary UI identifier;
- list with server-side pagination/search/filter/sort;
- details with bounded fulfillment workspace;
- unlimited practical item/SKU rows;
- Shipping Address snapshot;
- independent Order / Shipment / Manual Sync status;
- notes, source and owner.

### Inventory visibility

- show where each Order SKU is stocked by Warehouse;
- show on-hand / reserved / available semantics where present;
- Inventory Batch Sync remains the canonical batch-maintenance path;
- no automatic warehouse decision is required in MVP1.

### Manual Warehouse assignment

MVP1 must allow an operator to select the fulfillment Warehouse manually.

Future-compatible provenance must be allowed conceptually:

```text
warehouse_selection_source = manual | future rule_engine | future override
```

MVP1 only requires `manual`. The rule engine is not implemented yet.

### Logistics / Shipment

- one pending shipment queue for customer and KOL fulfillment Orders;
- business order number + order-kind indicator;
- copy full shipment information with explicit success/failure handling;
- copy recipient/address/phone/product information;
- record selected Warehouse;
- record carrier and service;
- record tracking;
- record shipped time;
- mark shipped;
- preserve ShipmentService / Manual Sync / Audit behavior.

Future-compatible carrier provenance must be allowed conceptually:

```text
carrier_selection_source = manual | future rule_engine | future override
```

MVP1 only requires `manual`.

### Manual Sync

- manual pending;
- manually completed today;
- needs confirmation;
- returned/failed;
- mark completed;
- reopen with Audit;
- automatic WooCommerce write-back is not required for MVP1.

### Finance / Accounting compatibility

Finance uses the same `orders.id`; no second accounting-order truth.

- product/commercial revenue;
- shipping revenue separate from shipping cost;
- tax collected separate from tax cost/liability;
- discount/refund;
- product cost;
- warehouse/first-mile/logistics cost;
- recognized KOL/Affiliate commission where applicable;
- payment fee;
- other cost;
- total cost, profit, margin and completeness;
- USD base-currency analysis with preserved FX snapshot when needed.

## Future fulfillment backbone — reserve now, implement later

MVP1 code must not block this long-term chain:

```text
Product
→ Order
→ Inventory Availability
→ Warehouse Allocation
→ Reservation
→ Packing Plan
→ Chargeable Weight
→ Shipping Cost Estimator
→ Transport Mode Decision
→ Carrier Rule Engine
→ Warehouse Connector
→ Shipment
```

### Future chargeable-weight rule

```text
chargeable_weight = max(actual_weight, volumetric_weight)
```

`volumetric_weight` uses a carrier/service-configured divisor; the divisor must not be hard-coded into Order or Inventory logic.

### Future shipping-cost / transport-mode rule

The later Shipping Cost Estimator evaluates eligible rate candidates. If the best express cost exceeds a configurable threshold, the system must enter ocean-freight evaluation.

Initial business threshold:

```text
amount = 300
currency = configurable
behavior = evaluate ocean freight; do not force ocean automatically
```

The later mode decision must also consider SLA/time commitment, product restrictions, destination, package size/weight and other explicit business rules.

### Future decision evidence

Later intelligent decisions should be able to preserve:

```text
recommended_warehouse_id
warehouse_selection_source
chargeable_weight
rate_candidates
best_express_cost
transport_mode
transport_mode_reason
carrier/service
carrier_selection_source
estimated_shipping_cost
actual_shipping_cost
currency
rule_id
rule_version
decision_snapshot
```

These are roadmap/contract extension points, not a requirement to add all fields or engines in MVP1.

## Post-MVP sequence

### MVP1.1 — WooCommerce Inbound

- connection;
- single/incremental/historical import;
- normalized DTO;
- upsert into existing canonical Orders;
- no second Order truth.

### MVP1.2 — Tracking Outbound

- push one tracking update from existing Shipment truth;
- verify remote;
- retry and audit.

### MVP1.3 — Sync Orchestration

- sync runs/items;
- selected and all-pending batch;
- retries, failures, conflicts and reconciliation.

### MVP2 — Assisted fulfillment intelligence

1. Inventory Reservation primitives: reserve / release / commit / expire.
2. Warehouse Allocation recommendation from destination + availability + region + priority.
3. Packing Plan + chargeable weight.
4. Shipping Cost Estimator.
5. Transport Mode Decision, including express-cost threshold → ocean evaluation.
6. Carrier Rule Engine.
7. Recommendation-first with human confirmation/override before full automation.

### MVP3 — Warehouse Connectors

- one canonical WarehouseConnector contract;
- US East / US West / China / Germany adapters;
- pushOrder / cancelOrder / getInventory / getShipmentStatus / getTracking.

### MVP4 — Automatic Fulfillment

- automatic availability + reservation;
- automatic warehouse decision;
- automatic packing / chargeable-weight / cost evaluation;
- automatic transport-mode / carrier decision under explicit rules;
- WMS dispatch + tracking/status ingestion;
- decision snapshot and estimated-vs-actual cost feedback loop.

## Explicit MVP1 exclusions

- automatic WooCommerce import;
- automatic tracking write-back;
- batch external sync orchestration;
- automatic Inventory Reservation;
- automatic warehouse recommendation/assignment;
- split-shipment automation;
- automatic packing optimization;
- chargeable-weight engine;
- carrier rate shopping;
- shipping-cost estimator;
- automatic express-to-ocean decision;
- automatic carrier/service selection;
- live Warehouse/WMS connectors;
- automatic fulfillment;
- remote deployment / Production access.

## MVP1 acceptance

- a complete customer Order can be created/read from canonical Order truth;
- a complete KOL fulfillment Order can use the same canonical Order service/database;
- Product identity is consistent across Order / Inventory / Logistics;
- operator can see Warehouse-level inventory for Order SKUs;
- operator can manually choose the fulfillment Warehouse;
- both Order kinds can enter the same pending Logistics queue;
- logistics copy behavior is browser-verified and cannot fail silently;
- operator can manually choose/record carrier/service, tracking and shipped time;
- Shipment state is persisted and auditable;
- Manual Sync behavior remains compatible and can be completed/reopened with Audit;
- the end-to-end workflow no longer requires Tencent Docs as the operational handoff;
- future automation extension points are preserved without implementing the intelligent engines now;
- normal verification/UAT uses the US Mac by default; China is special-case only unless explicitly requested;
- Owner Local/remote UAT passes before merge.
