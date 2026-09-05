---
type: living-roadmap
project: BYL ERP
version: 1.2
updated: 2026-08-29
---

# Living Roadmap

## V2 current-status overlay — 2026-09-05

This overlay supersedes older mutable `CURRENT_*` markers below while preserving them as historical planning records. Canonical current status is `27_ERP_ROADMAP_PRODUCTION_RECONCILIATION_V2.md`. Strategic line: **Production Consolidation → Fulfillment Intelligence**. KOL V1 and Purchase OS V1 are closed; Purchase is now live in the current bounded Production release; Product final cross-phase closure/promotion, Inventory/Packaging production closure, Logistics V1 and MVP2 Reservation are the next active-ready lanes.


## Program Roadmap

```text
Architecture Migration / Global Architecture Gate [COMPLETE]
→ ERP-INQUIRY-M1 M1.0 Contract + M1.1 Company [COMPLETE]
→ ERP-ORDER-FULFILLMENT-M1 [COMPLETE]
→ Product Master / SKU M1 [COMPLETE]
→ Order Create/Edit V2 [COMPLETE]
→ Inventory Foundation + Inventory Batch Sync M1 [COMPLETE]
→ MVP1 Manual Fulfillment Integration / Tencent Docs Replacement [COMPLETE / PR #101]
→ MVP1.1 WooCommerce Inbound [COMPLETE / PR #104]
→ MVP1.2 Tracking Outbound [COMPLETE / PR #106]
→ MVP1.3 Sync Orchestration [COMPLETE / PR #108]
→ KOL M1.0 Foundation [COMPLETE / PR #103]
→ KOL M1.1 CRM [COMPLETE / PR #110]
→ ROADMAP RECONCILIATION [COMPLETE / PR #111]
→ KOL M1.2A Collaboration [COMPLETE / PR #113]
→ KOL M1.2B Canonical Fulfillment Bridge [COMPLETE / PR #114]
→ KOL M1.3 Content [COMPLETE / PR #116]
→ KOL M1.4A AffiliateWP Connector Foundation [COMPLETE / PR #117]
→ KOL M1.4B Influencer Sync + KOL Approval + Provisioning [CURRENT]
→ KOL M1.4C Coupon Sync + Provisioning
→ KOL M1.4D Referral + Commission Sync
→ KOL M1.4E Commission Payout Workflow
→ KOL M1.4F Approval Center + Weekly/Monthly Dashboard
→ KOL V1 Cross-phase Closure / Full Regression / Owner UAT
→ MVP2 Inventory Reservation Foundation [PAUSED UNTIL KOL V1 CLOSED]
→ MVP2.1 Warehouse Allocation Recommendation
→ MVP2.2 Packing + Chargeable Weight
→ MVP2.3 Shipping Cost Estimator
→ MVP2.4 Transport Mode Decision / Express-to-Ocean Evaluation
→ MVP2.5 Carrier Rule Engine
→ MVP3 Warehouse Connector Foundation + real warehouse adapters
→ MVP4 Automatic Fulfillment
→ Accounting / Procurement expansion
→ ERP-INQUIRY-M1 remaining M1.2–M1.9 when reactivated
→ Milestone Remote Release / https://erp.beyourlover.com
→ Product / Media / Content / SEO / Shopify expansion
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
Inventory Foundation + Batch Sync M1 = COMPLETE / MERGED BY PR #96
MVP1 Manual Fulfillment Integration = COMPLETE / MERGED BY PR #101
WooCommerce Inbound = COMPLETE / MERGED BY PR #104
Tracking Outbound = COMPLETE / MERGED BY PR #106
Sync Orchestration = COMPLETE / MERGED BY PR #108
KOL M1.0 Foundation = COMPLETE / MERGED BY PR #103
KOL M1.1 CRM = COMPLETE / MERGED BY PR #110
ERP-INQUIRY-M1.2 Contact = PAUSED / NOT CANCELLED
Entity Architecture V2 PR #97 = DRAFT DESIGN REFERENCE / NOT EXECUTION AUTHORITY
Release Rebaseline PR #98 = CLOSED / SUPERSEDED BY CURRENT RECONCILIATION
KOL duplicate CRM PR #109 = CLOSED / SUPERSEDED BY PR #110
US Mac = default full verification + Owner UAT host
China Mac = special-case verification only unless explicitly required
GitHub = UNIQUE SOURCE OF TRUTH
CURRENT_PHASE = KOL V1 FULL COMPLETION TRACK / M1.4B INFLUENCER APPROVAL + PROVISIONING
CURRENT_WORK_PACKAGE = ERP-KOL-M1.4B-INFLUENCER-APPROVAL-PROVISIONING-001
NEXT_PRODUCT_WORK_PACKAGE = ERP-KOL-M1.4C-COUPON-SYNC-PROVISIONING-001
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
1. M1.2A, M1.2B, M1.3 and M1.4A are complete and merged; M1.4A merged as PR #117 at `1a7347f94938be2db29cbcd1fad6822aedcd9052`.
2. Current execution is M1.4B Influencer Sync + KOL Approval + Provisioning from `main@1a7347f94938be2db29cbcd1fad6822aedcd9052`.
3. Andy Core v1.5.3 WordPress Connector remains a separately implemented external dependency in the Andy Core repository/chat; fake-provider verification is allowed before live compatibility UAT.
4. Continue serially through M1.4B → M1.4C Coupon → M1.4D Referral/Commission → M1.4E Payout → M1.4F Approval/Dashboard.
5. KOL Specialists and Managers must be able to complete normal operations without WordPress login after M1.4F.
6. AffiliateWP/WooCommerce/WordPress provider-truth boundaries remain intact; ERP mirrors and operates but does not create competing provider truth.
7. ERP-INQUIRY-M1.2 Contact and MVP2 remain paused until KOL V1 closes or Owner explicitly reprioritizes.
8. Remote deployment remains milestone-gated and separate from normal local development.
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
