---
type: living-roadmap
project: BYL ERP
version: 1.0
updated: 2026-08-27
---

# Living Roadmap

## Program Roadmap

```text
Architecture Migration / Global Architecture Gate [COMPLETE]
→ MVP1 Existing Operations / Tencent Docs Replacement [IMPLEMENTATION COMPLETE; HUMAN UAT REMEDIATION OPEN]
→ ERP-INQUIRY-M1 M1.0 Contract + M1.1 Company [COMPLETE]
→ ERP-ORDER-FULFILLMENT-M1 [COMPLETE]
→ Product Master / SKU M1 [COMPLETE]
→ Order Create/Edit V2 [COMPLETE]
→ Inventory Foundation + Inventory Batch Sync M1 [COMPLETE / MERGED BY PR #96]
→ Shipping ↔ Inventory M1 [NEXT]
→ Carrier / Shipping Rule Engine M1
→ Warehouse Connector Foundation
→ Automatic Fulfillment M1
→ WooCommerce Inbound + Tracking Outbound
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
CURRENT_PHASE = Inventory M1 CLOSED / Shipping ↔ Inventory M1 planning next
CURRENT_WORK_PACKAGE = NONE / NEXT WORK PACKAGE NOT YET FORMALIZED
NEXT_PHASE = Shipping ↔ Inventory M1
Remote Milestone Target = https://erp.beyourlover.com
Deployment = NO / MILESTONE GATED
```

## Current business objective

```text
Primary objective: connect the existing canonical Order + Product Master + Inventory + Logistics foundations into an automated fulfillment decision chain.
Immediate outcome: determine the eligible fulfillment warehouse from Shipping Address + product_id/SKU + available inventory, then reserve inventory safely.
Next outcome: apply explicit carrier/shipping rules, then integrate warehouse/WMS connectors through one canonical connector contract.
Allocation rule for M1: one Order = one Warehouse; if no warehouse can fully satisfy the Order, require manual handling instead of automatic split shipment.
Local-first rule: normal development validation uses the US Mac by default; China verification is special-case only unless explicitly requested.
Remote-release rule: https://erp.beyourlover.com remains milestone-gated.
```

## Immediate sequence

```text
1. Formalize Shipping ↔ Inventory M1: warehouse eligibility + available-stock check + inventory reservation + selected_warehouse_id.
2. Implement one-Order/one-Warehouse automatic recommendation with manual exception handling when no warehouse can satisfy the complete Order.
3. Formalize Carrier / Shipping Rule Engine M1 after warehouse allocation is stable.
4. Add Warehouse Connector Foundation, then individual adapters for the real US East / US West / China / Germany warehouse systems.
5. Connect automatic fulfillment end-to-end before expanding WooCommerce automation, Accounting/Procurement, or resuming paused Inquiry work.
6. Remote deployment remains milestone-gated and separate from normal local development.
```

Architecture migration and its Global Gate are closed. MySQL 8.x remains the supported database baseline. Historical architecture evidence is retained in its Work Packages and logs; it is no longer a current blocker. Remote deployment remains milestone-gated and is not part of normal development.

## Incremental releases

### MVP1 — Existing Operations on Final Core

- manual website/KOL order entry;
- shipment work queue;
- copy shipment data;
- carrier/tracking/shipped time;
- manual website-sync queue;
- order-level cost and profit;
- audit and export.

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
