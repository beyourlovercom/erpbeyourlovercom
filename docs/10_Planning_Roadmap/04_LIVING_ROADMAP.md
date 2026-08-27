---
type: living-roadmap
project: BYL ERP
version: 1.1
updated: 2026-08-27
---

# Living Roadmap

## Program Roadmap

```text
Architecture Migration / Global Architecture Gate [COMPLETE]
→ ERP-INQUIRY-M1 M1.0 Contract + M1.1 Company [COMPLETE]
→ ERP-ORDER-FULFILLMENT-M1 [COMPLETE]
→ Product Master / SKU M1 [COMPLETE]
→ Order Create/Edit V2 [COMPLETE]
→ Inventory Foundation + Inventory Batch Sync M1 [COMPLETE / MERGED BY PR #96]
→ MVP1 Manual Fulfillment Integration / Tencent Docs Replacement [CURRENT]
→ MVP1.1 WooCommerce Inbound
→ MVP1.2 Tracking Outbound
→ MVP1.3 Sync Orchestration
→ MVP2 Inventory Reservation Foundation
→ MVP2.1 Warehouse Allocation Recommendation
→ MVP2.2 Packing + Chargeable Weight
→ MVP2.3 Shipping Cost Estimator
→ MVP2.4 Transport Mode Decision / Express-to-Ocean Evaluation
→ MVP2.5 Carrier Rule Engine
→ MVP3 Warehouse Connector Foundation + real warehouse adapters
→ MVP4 Automatic Fulfillment
→ Accounting / Procurement expansion
→ Resume ERP-INQUIRY-M1 at M1.2 Contact [PAUSED, NOT CANCELLED]
→ ERP-INQUIRY-M1 M1.2–M1.9 + Local Milestone UAT
→ Milestone Remote Release / https://erp.beyourlover.com
→ later Product / Media / Content / SEO / KOL / Shopify expansion
→ Production Hardening
```

Program sequence, estimate classification and remaining-hours evidence are maintained in [[13_PROGRAM_ROADMAP_AND_HOURS]].

## Current status

```text
Architecture Foundation = COMPLETE / PASS
Global Architecture Gate = PASS
ERP-ORDER-FULFILLMENT-M1 = COMPLETE / MERGED
Product Master / SKU M1 = COMPLETE / MERGED BY PR #93
Order Create/Edit V2 = COMPLETE / MERGED BY PR #95
Inventory Foundation = COMPLETE / ACCEPTED
Inventory Batch Sync M1 = COMPLETE / OWNER UAT PASS / MERGED BY PR #96
ERP-INQUIRY-M1.2 Contact = PAUSED / NOT CANCELLED
US Mac = default full verification + Owner UAT host
China Mac = not required by default; special-case verification only
GitHub = UNIQUE SOURCE OF TRUTH
CURRENT_PHASE = MVP1 Manual Fulfillment Integration / Tencent Docs Replacement
CURRENT_WORK_PACKAGE = NONE / NEXT WORK PACKAGE NOT YET FORMALIZED
NEXT_PHASE = connect Order + Product + Inventory + Logistics with manual decisions first
Remote Milestone Target = https://erp.beyourlover.com
Deployment = NO / MILESTONE GATED
```

## Current business objective

```text
Primary objective: replace the existing Tencent Docs manual fulfillment workflow with ERP while preserving the current human decision process.
MVP1 principle: people decide; ERP presents canonical data, records the decision, executes the manual workflow and preserves audit/history.
MVP1 must connect Product identity, Order demand, Inventory visibility, manual Warehouse assignment, Logistics workspace, Shipment and Manual Sync without requiring automatic warehouse/carrier decisions.
Future-intelligence principle: all MVP1 contracts must remain forward-compatible with Reservation, warehouse recommendation, packing, chargeable weight, shipping-cost estimation, transport-mode evaluation, carrier rules and WMS connectors, but those engines are explicitly post-MVP1.
Long-term backbone: Product → Order → Inventory Availability → Warehouse Allocation → Reservation → Packing → Chargeable Weight → Shipping Cost → Transport Mode → Carrier → Warehouse Connector → Shipment.
Shipping-cost rule reserved for later: chargeable_weight = max(actual_weight, volumetric_weight); volumetric divisor is carrier/service configuration. If best express cost exceeds the configurable threshold (initial business threshold 300, currency configurable), the system must evaluate ocean freight rather than automatically force ocean. SLA and business constraints remain part of the later decision.
Local-first rule: normal development validation uses the US Mac by default; China verification is special-case only unless explicitly requested.
Remote-release rule: https://erp.beyourlover.com remains milestone-gated.
```

## Immediate sequence

```text
1. Formalize MVP1 Manual Fulfillment Integration: connect existing Order + Product + Inventory + Logistics screens/data without replacing human judgment.
2. MVP1 acceptance target: an operator can complete the Tencent Docs workflow entirely inside ERP — create/view Order, inspect SKU inventory by Warehouse, manually choose Warehouse, enter carrier/service/tracking/shipped time, and complete the manual website-sync queue with Audit.
3. Preserve forward-compatible selection provenance (`manual`, future `rule_engine`, future `override`) for Warehouse and Carrier decisions; do not implement the rule engines in MVP1.
4. After MVP1 closure, implement MVP1.1 WooCommerce inbound, MVP1.2 Tracking outbound and MVP1.3 Sync orchestration.
5. Only then begin smart fulfillment foundations: Reservation → Warehouse Allocation recommendation → Packing/Chargeable Weight → Shipping Cost → Transport Mode → Carrier rules.
6. Build Warehouse Connectors after the internal fulfillment contract is stable; Automatic Fulfillment is MVP4, not MVP1.
7. Remote deployment remains milestone-gated and separate from normal local development.
```

Architecture migration and its Global Gate are closed. MySQL 8.x remains the supported database baseline. Historical architecture evidence is retained in its Work Packages and logs; it is no longer a current blocker. Remote deployment remains milestone-gated and is not part of normal development.

## Incremental releases

### MVP1 — Manual Fulfillment / Tencent Docs Replacement

MVP1 is intentionally manual-first. It replaces the Tencent Docs operating surface without requiring automatic operational judgment.

- Product Master remains the canonical product identity (`product_id` + SKU);
- Order remains the canonical demand/address truth;
- Inventory exposes current Warehouse-level stock and batch-sync truth;
- operator manually selects the fulfillment Warehouse;
- Logistics provides the shipment work queue and copy actions;
- operator manually records carrier, service, tracking and shipped time;
- Shipment persists the fulfillment result;
- Manual Sync queue records website update completion/reopen/failure with Audit;
- order-level cost/profit and export remain compatible;
- MVP1 does not require Reservation, automatic warehouse selection, automatic carrier selection, WMS push, automatic rate shopping or ocean-freight decisions.

MVP1 acceptance: the existing Tencent Docs fulfillment workflow can be completed end-to-end inside ERP with human judgment unchanged.

### ERP-ORDER-FULFILLMENT-M1 — Unified Manual Order → Logistics

- one canonical `orders.id` for customer and KOL fulfillment Orders;
- `external_order_number` as primary user-visible business order number;
- generated/unique `internal_order_number` hidden by default;
- `order_kind = customer | kol_fulfillment`, independent from source;
- one shared manual Order Create flow;
- scoped customer and KOL fulfillment Order projections;
- one shared pending Logistics/Shipment workflow;
- reliable clipboard/copy behavior with explicit fallback/error handling;
- bounded Order Detail fulfillment workspace;
- server-side pagination/search/filter/sort/authorization and required indexes;
- Tier B dual-host verification and Owner Local UAT;
- revalidation/remediation of MVP1 UAT DEFECT-002, DEFECT-003, DEFECT-004 and FINDING-005.

The broader Canonical Order / Finance / KOL contract also freezes future Finance/KOL/AffiliateWP semantics, but full KOL CRM, AffiliateWP connector and advanced SKU-profit allocation are not automatically part of this first implementation slice.

### ERP-INQUIRY-M1 — B2B Inquiry Foundation

- M1.0 Inquiry architecture / data / API contract — COMPLETE;
- M1.1 Company — COMPLETE / MERGED;
- M1.2 Contact — PAUSED / resumes after ERP-ORDER-FULFILLMENT-M1;
- M1.3 Case;
- M1.4 Inbound Message;
- M1.5 Inquiry Inbox;
- M1.6 Email Intake;
- M1.7 WhatsApp Intake;
- M1.8 Yesterday / Waiting Dashboard;
- M1.9 Local Milestone UAT;
- milestone-gated remote release to `erp.beyourlover.com`.

### MVP1.1 — WooCommerce Inbound

- connection;
- single/incremental/historical import;
- normalized DTO;
- upsert into existing canonical orders;
- no second order truth.

### MVP1.2 — Single Tracking Outbound

- process existing shipment/outbox;
- push one tracking update;
- verify remote;
- retry and audit.

### MVP1.3 — Sync Orchestration

- sync runs/items;
- selected and all-pending batch;
- retries;
- failures;
- conflicts;
- reconciliation.

### MVP2 — Assisted Fulfillment Intelligence

- Inventory Reservation primitives: reserve / release / commit / expire;
- Warehouse Allocation recommendation using destination + availability + service region + priority;
- first release remains one Order = one Warehouse, with manual exception instead of automatic split shipment;
- Packing Plan and chargeable-weight calculation;
- `chargeable_weight = max(actual_weight, volumetric_weight)`;
- carrier/service-specific volumetric divisor;
- Shipping Cost Estimator returns rate candidates and estimated costs;
- Transport Mode Decision evaluates express / special line / air / ocean;
- if best express cost exceeds configurable threshold (initial business threshold 300), ocean evaluation is triggered, not forced;
- Carrier Rule Engine recommends carrier/service with decision reason;
- recommendation-first, human confirmation/override before full automation.

### MVP3 — Warehouse Connectors

- one canonical WarehouseConnector contract;
- adapters for US East / US West / China / Germany real warehouse systems;
- pushOrder / cancelOrder / getInventory / getShipmentStatus / getTracking;
- no warehouse-specific business truth outside the canonical ERP contracts.

### MVP4 — Automatic Fulfillment

- automated availability check and reservation;
- automated warehouse decision;
- automated packing/chargeable-weight/cost evaluation;
- automated transport-mode and carrier decision under explicit rules;
- automatic WMS dispatch and tracking/status ingestion;
- decision snapshots preserve rule/version/reason and estimated-vs-actual shipping cost for historical audit.

## Post-MVP modules

1. Product / Media.
2. WordPress products and images.
3. Content / SEO.
4. Posts / Pages.
5. GA4 / GSC / Cloudflare.
6. Google Docs publishing.
7. Full Order Workspace.
8. Logistics OS.
9. KOL OS and social providers.
10. Inventory and procurement.
11. Accounting / ROI expansion.
12. Shopify and additional integrations.
13. Production hardening.

## Change rule

This Roadmap is editable.

Any change must update:

- Decision Register;
- Change Log;
- affected module specification;
- affected estimate;
- current context.

Architecture changes require an explicit conflict decision.
