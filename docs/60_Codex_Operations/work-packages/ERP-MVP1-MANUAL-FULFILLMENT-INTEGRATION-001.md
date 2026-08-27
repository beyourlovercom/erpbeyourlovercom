---
type: work-package
project: BYL ERP
work_package_id: ERP-MVP1-MANUAL-FULFILLMENT-INTEGRATION-001
title: MVP1 Manual Fulfillment Integration / Tencent Docs Replacement
status: ACTIVE / OWNER AUTHORIZED
baseline: main@1255a301b69db79da25aad1ce78ec63b94293ca1
branch: feature/erp-mvp1-manual-fulfillment-integration-001
draft_pr: TBD
updated: 2026-08-27
---

# ERP-MVP1-MANUAL-FULFILLMENT-INTEGRATION-001

## 1. Objective

Connect the already-implemented Order + Product + Inventory + Logistics foundations into one manual-first fulfillment workflow that can replace the current Tencent Docs operational handoff.

```text
Order
→ Product identity
→ Warehouse inventory visibility
→ operator selects Warehouse
→ Logistics workspace
→ operator selects carrier/service
→ tracking + shipped time
→ Shipment
→ Manual Sync / Audit
```

## 2. Frozen product rule

MVP1 preserves human judgment. ERP presents canonical facts, records the operator decision and carries the workflow; it does not make the decision automatically.

```text
HUMAN DECIDES
→ ERP SHOWS CANONICAL DATA
→ ERP RECORDS DECISION
→ ERP EXECUTES / TRACKS MANUAL WORKFLOW
→ ERP PRESERVES AUDIT
```

Long-term interfaces must remain compatible with:

```text
Reservation
→ Warehouse Allocation recommendation
→ Packing
→ Chargeable Weight
→ Shipping Cost
→ Transport Mode
→ Carrier Rule Engine
→ Warehouse Connector
→ Automatic Fulfillment
```

None of those engines are implemented in this Work Package.

## 3. Existing foundations to reuse

- canonical `orders.id`, Order Items and Shipping Address;
- Product Master `product_id` + SKU identity;
- Warehouse Master + Warehouse Inventory + Batch Sync;
- ShipmentService + pending Logistics queue;
- Manual Sync + immutable Audit;
- V1 shipment cardinality remains 1 Order : 1 Shipment.

## 4. Required implementation

### 4.1 Inventory visibility for fulfillment

For every Order Item with canonical `product_id`, the fulfillment UI must show current inventory by active Warehouse.

Minimum projection per Warehouse:

```text
warehouse_id
warehouse_name
quantity_on_hand
quantity_reserved
quantity_available
```

Inventory remains read-only from the fulfillment surface. The fulfillment package must not create a second inventory truth or directly mutate QOH/Reserved.

### 4.2 Manual Warehouse assignment

The operator must be able to select one active Warehouse for the V1 Shipment.

Persist on Shipment:

```text
warehouse_id nullable FK → warehouses.id
warehouse_selection_source = manual | rule_engine | override
```

MVP1 writes `manual`. `rule_engine` and `override` are forward-compatible values only; no rule engine is implemented now.

Assignment must be auditable and editable while the Shipment remains pending.

### 4.3 Manual carrier/service entry

Extend Shipment with:

```text
service nullable string
carrier_selection_source = manual | rule_engine | override
```

MVP1 writes `manual`. Carrier/service remain operator-entered.

The Shipment workflow must continue to require carrier, tracking number and shipped time before mark-shipped. Existing Manual Sync creation and Audit semantics must remain intact.

### 4.4 Fulfillment workspace

Replace the current prompt-only Logistics interaction with an explicit bounded workflow that exposes:

- Order business number and order kind;
- recipient + Shipping Address;
- Order Items / SKU / quantity;
- inventory by active Warehouse;
- selected Warehouse;
- carrier;
- service;
- tracking number;
- shipped time;
- logistics note;
- copy recipient / copy complete shipment / copy tracking;
- mark shipped / reopen behavior.

The operator must be able to leave and return without losing a saved pending Warehouse/carrier/service selection.

## 5. Future architecture reservation only

This package must not block the frozen long-term backbone:

```text
Product → Order → Inventory Availability → Warehouse Allocation → Reservation
→ Packing → Chargeable Weight → Shipping Cost → Transport Mode
→ Carrier → Warehouse Connector → Shipment
```

Future logistics rule, architecture only:

```text
chargeable_weight = max(actual_weight, volumetric_weight)
volumetric_divisor = carrier/service configuration
```

Future cost rule, architecture only:

```text
if best_express_cost > configurable threshold
then evaluate ocean freight
```

Initial business threshold is 300 with currency configurable. Ocean evaluation is not a forced ocean decision; later rules also consider SLA/time, destination and product/business constraints.

Do not add unused MVP2+ calculation engines merely to satisfy this reservation.

## 6. Explicit exclusions

- Inventory Reservation / Reserved mutation;
- automatic warehouse recommendation or allocation;
- automatic carrier recommendation;
- packing engine;
- volumetric/chargeable-weight calculation;
- shipping rate lookup/cost estimator;
- express-vs-ocean decision engine;
- WMS/Warehouse API connectors;
- WooCommerce inbound or tracking outbound automation;
- split shipment 1:N;
- Production deployment.

## 7. Preflight findings

Baseline review confirms:

- Order Items already carry canonical `product_id` and SKU;
- Warehouse Inventory already exposes QOH / Reserved / computed Available;
- pending Logistics already uses canonical Orders and ShipmentService;
- current Shipment lacks Warehouse, service and selection-source fields;
- current Logistics UI uses browser prompts for carrier/tracking and does not expose inventory;
- current Order Detail shows Order/Shipment state but not Warehouse-level inventory or selected Warehouse.

No architecture conflict was found. This is an additive connection package, not a rewrite.

## 8. Persistence contract

Add a bounded migration extending `shipments` with:

```text
warehouse_id nullable FK → warehouses.id
service nullable string(128)
warehouse_selection_source string(32) nullable/default manual when selected
carrier_selection_source string(32) nullable/default manual when selected
```

No Reservation table is added in MVP1.

Shipment remains the V1 one-Order/one-Shipment fulfillment record. Future 1:N shipment widening must remain possible without replacing canonical Order truth.

## 9. Application boundary contract

Inventory facts consumed by Logistics must be exposed through Inventory Application/Contract boundaries; Logistics Application must not reach into Inventory Infrastructure as a hidden second implementation path.

Warehouse selection mutation belongs to the Logistics/Shipment workflow. Inventory data remains read-only from this package.

## 10. Audit contract

At minimum record auditable changes for:

```text
shipment.fulfillment_saved
shipment.marked_shipped
shipment.reopened
```

Audit before/after state for manual fulfillment must include Warehouse, carrier, service, tracking, shipped time and selection-source fields when relevant.

## 11. Verification contract

US Mac is the default and sufficient verification/UAT host per Owner decision D-038/D-039. China verification is not required unless Owner explicitly requests it or a concrete cross-machine defect requires it.

Required gates:

- migration fresh / rollback / reapply on permitted local disposable data;
- focused PHP tests for inventory projection, manual Warehouse assignment, persistence, Audit, mark-shipped and reopen;
- focused Vue tests for explicit fulfillment form, inventory visibility, saved pending state and copy behavior;
- existing Order/Inventory/Logistics regression;
- Architecture/Menu checks;
- full PHPUnit;
- full Vitest;
- typecheck;
- build;
- `git diff --check`;
- US Local UAT.

## 12. Owner UAT acceptance

A real operator must be able to complete the former Tencent Docs fulfillment flow inside ERP:

1. open a pending Order;
2. see each SKU and its Warehouse-level inventory;
3. manually choose a fulfillment Warehouse;
4. save/reopen the pending fulfillment state without losing the choice;
5. enter carrier + service + tracking + shipped time;
6. copy recipient/full shipment/tracking data;
7. mark shipped;
8. see Shipment state and Manual Sync pending state;
9. reopen when correction is required;
10. confirm Audit evidence exists.

## 13. Delivery governance

```text
ONE_WORK_PACKAGE_ONE_BRANCH_ONE_PR
BRANCH = feature/erp-mvp1-manual-fulfillment-integration-001
BASE = main@1255a301b69db79da25aad1ce78ec63b94293ca1
DEFAULT_TEST_HOST = US Mac
PRODUCTION_DEPLOYMENT = NO
```

The same Draft delivery PR carries contract → implementation → verification → remediation → Owner UAT → merge gate.
