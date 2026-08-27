---
type: current-context
project: BYL ERP
updated: 2026-08-27
status: mvp1-manual-fulfillment-roadmap-current
---

# Current Context

## Authority and operating model

```text
OWNER: final product, UAT, merge and release authority
DESIGN_DIAGNOSIS_AUTHORITY: GPT Web + Owner
PRIMARY_IMPLEMENTATION_HOST: US Mac
PRIMARY_FULL_VERIFICATION_HOST: US Mac
OWNER_UAT_HOST: US Mac local/remote UAT
CHINA_VERIFICATION: special-case only unless Owner explicitly requests it or a concrete cross-machine/region-specific defect requires it
SOURCE_OF_TRUTH: GitHub / beyourlovercom/erpbeyourlovercom
REMOTE_MILESTONE_TARGET: https://erp.beyourlover.com
AICC_IN_ERP_EXECUTION_CHAIN: NO
```

Normal development does not require China verification. GitHub remains the unique synchronization/source-of-truth path. Remote Production deployment is milestone-gated and not part of normal development debugging.

## Canonical baseline
```text
CURRENT_MAIN_SHA: b99cc8934e8e041ae8aa004383dae41ac1be207f
ARCHITECTURE_FOUNDATION: COMPLETE / PASS
GLOBAL_ARCHITECTURE_GATE: PASS
ERP-ORDER-FULFILLMENT-M1: COMPLETE / MERGED BY PR #90
PRODUCT_MASTER_SKU_M1: COMPLETE / MERGED BY PR #93
ORDER_CREATE_EDIT_V2: COMPLETE / MERGED BY PR #95
INVENTORY_FOUNDATION: COMPLETE
INVENTORY_BATCH_SYNC_M1: COMPLETE / OWNER UAT PASS / MERGED BY PR #96
INVENTORY_DYNAMIC_WAREHOUSE_SHORTCUTS: COMPLETE
GITHUB_UNIQUE_SYNC_SOURCE: YES
REMOTE_DEPLOYMENT: NOT AUTHORIZED UNTIL MILESTONE STABLE
```

Existing foundations now include canonical Order/Order Items/Shipping Address, Product Master identity, Warehouse Master, Warehouse-level Inventory truth and batch sync, Logistics/Shipment foundation, Manual Sync/Audit, Finance/Order Analysis, App Shell/Auth/Permissions and local verification infrastructure.

## Owner-frozen program priority

```text
CURRENT_PROGRAM_PRIORITY: MVP1 Manual Fulfillment Integration / Tencent Docs Replacement
CURRENT_WORK_PACKAGE: NONE / NEXT PACKAGE NOT YET FORMALIZED
CURRENT_ACTIVITY: Roadmap frozen; next bounded package must connect existing Order + Product + Inventory + Logistics manually first
ERP-INQUIRY-M1.2 CONTACT: PAUSED / NOT CANCELLED
```

MVP1 is deliberately manual-first. The goal is not automatic fulfillment yet. The goal is to remove Tencent Docs from the operational handoff while preserving current human decision-making.

## MVP1 operating model
```text
HUMAN DECIDES
→ ERP SHOWS CANONICAL DATA
→ ERP RECORDS THE DECISION
→ ERP EXECUTES / TRACKS THE MANUAL WORKFLOW
→ ERP PRESERVES AUDIT
```

Required MVP1 flow:

```text
Order / Shipping Address / Order Items
→ Product Master identity
→ Warehouse-level Inventory visibility
→ operator manually selects fulfillment Warehouse
→ Logistics workspace
→ operator manually selects/records carrier + service
→ tracking + shipped time
→ Shipment
→ Manual Sync queue where applicable
→ operator manually updates website
→ mark sync complete / reopen with Audit
```

MVP1 acceptance means this workflow can be completed end-to-end inside ERP without relying on Tencent Docs.

## Long-term fulfillment backbone

MVP1 contracts must remain forward-compatible with this future chain, but the intelligent engines are post-MVP1:
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

### Future logistics-cost rules

```text
chargeable_weight = max(actual_weight, volumetric_weight)
volumetric_divisor = carrier/service configuration
```

Later, if the best eligible express cost exceeds a configurable threshold, the system must evaluate ocean freight.

Initial business threshold:

```text
amount = 300
currency = configurable
behavior = evaluate ocean freight, not force ocean automatically
```
The later Transport Mode decision must also consider SLA/time commitment, destination, package facts, product restrictions and explicit business rules.

## Frozen phased roadmap

```text
MVP1   Manual Fulfillment / Tencent Docs Replacement
MVP1.1 WooCommerce Inbound
MVP1.2 Tracking Outbound
MVP1.3 Sync Orchestration
MVP2   Inventory Reservation Foundation
MVP2.1 Warehouse Allocation Recommendation
MVP2.2 Packing + Chargeable Weight
MVP2.3 Shipping Cost Estimator
MVP2.4 Transport Mode Decision / Express-to-Ocean Evaluation
MVP2.5 Carrier Rule Engine
MVP3   Warehouse Connector Foundation + real warehouse adapters
MVP4   Automatic Fulfillment
```

MVP2 is recommendation/assistance first with human confirmation/override. Full autonomous decisions are not required until MVP4.

## MVP1 scope protection

MVP1 must not be expanded by pulling in these future engines:

```text
NO automatic Reservation requirement
NO automatic warehouse recommendation/assignment requirement
NO automatic packing optimization
NO chargeable-weight engine requirement
NO carrier rate shopping requirement
NO automatic express-to-ocean decision
NO automatic carrier/service selection
NO live Warehouse/WMS connector requirement
NO automatic fulfillment requirement
```

MVP1 may preserve future-compatible provenance/extension points such as manual vs future rule-engine/override Warehouse and Carrier selection, but it must not create premature complexity merely to anticipate later phases.

## Verification / release gates

```text
NORMAL DEVELOPMENT: US Mac
NORMAL OWNER UAT: US Mac local/remote UAT
CHINA: NOT REQUIRED BY DEFAULT
GITHUB: UNIQUE SOURCE OF TRUTH
REMOTE URL: https://erp.beyourlover.com
REMOTE USE: STABLE MILESTONE RELEASE ONLY
PRODUCTION DIRECT EDIT: FORBIDDEN
```

## Immediate sequence

```text
1. Merge this Roadmap synchronization to main so D-039 becomes canonical.
2. Formalize one bounded MVP1 Manual Fulfillment Integration Work Package.
3. Connect Order + Product + Inventory + Logistics with manual decisions first.
4. Owner UAT proves the real Tencent Docs workflow can be completed entirely inside ERP.
5. Close MVP1 Tencent Docs Replacement.
6. Proceed to MVP1.1 WooCommerce Inbound, MVP1.2 Tracking Outbound and MVP1.3 Sync Orchestration.
7. Only after MVP1.x closure begin MVP2 assisted-intelligence work.
```
