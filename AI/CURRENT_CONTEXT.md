---
type: current-context
project: BYL ERP
updated: 2026-08-28
status: roadmap-reconciliation-current
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
```

GitHub remains the unique synchronization/source-of-truth path. Remote Production deployment is milestone-gated and is not part of normal development debugging.

## Canonical baseline through PR #110

```text
BASELINE_MAIN_SHA: 8c3a24d0a202d3180106d1ac7befbd848539494b
ARCHITECTURE_FOUNDATION: COMPLETE / PASS
ERP-ORDER-FULFILLMENT-M1: COMPLETE / PR #90
PRODUCT_MASTER_SKU_M1: COMPLETE / PR #93
ORDER_CREATE_EDIT_V2: COMPLETE / PR #95
INVENTORY_FOUNDATION_BATCH_SYNC_M1: COMPLETE / PR #96
MVP1_MANUAL_FULFILLMENT_INTEGRATION: COMPLETE / PR #101
WOOCOMMERCE_INBOUND: COMPLETE / PR #104
TRACKING_OUTBOUND: COMPLETE / PR #106
SYNC_ORCHESTRATION: COMPLETE / PR #108
KOL_M1_0_FOUNDATION: COMPLETE / PR #103
KOL_M1_1_CRM: COMPLETE / PR #110
ERP_INQUIRY_M1_2_CONTACT: PAUSED / NOT CANCELLED
REMOTE_DEPLOYMENT: NOT AUTHORIZED UNTIL MILESTONE STABLE
```

## Current program position

```text
CURRENT_PROGRAM_PHASE: ERP PROGRAM ROADMAP RECONCILIATION / DOCS ONLY
CURRENT_WORK_PACKAGE: ERP-GOV-ROADMAP-RECONCILIATION-001
CURRENT_PRODUCT_WORK_PACKAGE: NONE
NEXT_PRODUCT_WORK_PACKAGE: OWNER SELECTION REQUIRED AFTER RECONCILIATION
```

No product track becomes current merely because it is listed as eligible next work.

Eligible next bounded tracks after reconciliation:

```text
A. ERP-KOL-M1.2A-COLLABORATION-001
B. MVP2 Inventory Reservation Foundation
C. ERP-INQUIRY-M1.2 Contact reactivation
```

These are alternatives until the Owner chooses one.

## Canonical truth ownership

```text
KOL = KOL master + CRM + Collaboration + Content + KOL finance projections
PRODUCT = products.id / SKU
INVENTORY = Warehouse + SKU×Warehouse stock truth
ORDER = orders.id
SHIPMENT = fulfillment / carrier / service / tracking truth
SYNC = external synchronization orchestration/evidence
AFFILIATEWP = Affiliate / Referral / Payout provider truth
```

No module may create a second truth for another module's canonical entity.

## KOL / Fulfillment boundary

KOL M1.2 is split to prevent duplicate fulfillment logic:

```text
M1.2A Collaboration
M1.2B Canonical Fulfillment Bridge
```

M1.2A owns formal cooperation facts. If physical sample/product fulfillment is required, M1.2B creates or links the existing canonical Order using `order_kind=kol_fulfillment`, then projects existing Shipment/Tracking state back into the KOL collaboration view.

KOL must not implement its own Inventory, Reservation, warehouse allocation, Shipment, Tracking, shipping-cost, transport-mode, carrier-rule or Warehouse Connector engine.

## Global Fulfillment roadmap retained

```text
MVP2   Inventory Reservation Foundation
MVP2.1 Warehouse Allocation Recommendation
MVP2.2 Packing + Chargeable Weight
MVP2.3 Shipping Cost Estimator
MVP2.4 Transport Mode Decision / Express-to-Ocean Evaluation
MVP2.5 Carrier Rule Engine
MVP3   Warehouse Connector Foundation + real warehouse adapters
MVP4   Automatic Fulfillment
```

Future chargeable weight remains `max(actual_weight, volumetric_weight)`. Express cost above the configurable threshold triggers ocean evaluation, not forced ocean.

## Open Draft classification

```text
PR #97 Entity Architecture V2: RETAIN / DESIGN REFERENCE ONLY / NOT EXECUTION AUTHORITY
PR #81 Dashboard pilot: RETAIN AS LEGACY EXPERIMENT / STALE BASELINE / NOT ROADMAP AUTHORITY
PR #98 Roadmap Rebaseline: CLOSED / SUPERSEDED
PR #109 duplicate KOL CRM: CLOSED / SUPERSEDED BY PR #110
```

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
1. Deliver ERP-GOV-ROADMAP-RECONCILIATION-001 as docs/governance only.
2. Owner reviews and separately authorizes merge.
3. After merge, Owner chooses exactly one next bounded product track.
4. No stale Draft PR may override the merged main roadmap.
5. No KOL work may duplicate canonical Product / Inventory / Order / Shipment / Tracking truth.
```
