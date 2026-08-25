---
type: living-roadmap
project: BYL ERP
version: 1.0
updated: 2026-08-25
---

# Living Roadmap

## Program Roadmap

```text
Architecture Migration / Global Architecture Gate [COMPLETE]
→ Release 1 Internal Operations / Tencent Docs Replacement [CURRENT]
  → Order / Fulfillment truth closure
  → Product Master / canonical SKU
  → Shipping / Carrier workspace
  → ERP-INVENTORY-FOUNDATION-M1-001 Warehouse / Inventory truth
  → Shipping ↔ Inventory warehouse selection
  → Tencent Docs productivity layer: single/batch copy, batch paste, batch fill
  → Order workspace / manual state correction / Release 1 Owner UAT
→ MVP1.1 WooCommerce Inbound
→ MVP1.2 Single Tracking Outbound
→ MVP1.3 Sync Orchestration
→ Release 2 Growth Operations
→ Release 3 Full Core ERP
→ Release 4 Platform Expansion / Production Hardening

PAUSED B2B TRACK: ERP-INQUIRY-M1 M1.2–M1.9 / OWNER REACTIVATION REQUIRED
```

Program sequence, estimate classification and remaining-hours evidence are maintained in [[13_PROGRAM_ROADMAP_AND_HOURS]].

## Current status

```text
Architecture Foundation = COMPLETE / PASS
Global Architecture Gate = PASS
MVP1 Foundations = COMPLETE / PASS / CLOSED
User & Access + Permission Matrix = COMPLETE / PR #92 MERGED
Order / Fulfillment = ACTIVE / PR #90 NOT MERGED
Product Master = ACTIVE PARALLEL STREAM
Shipping / Carrier = ACTIVE / US OWNER UAT REMEDIATION
ERP-INVENTORY-FOUNDATION-M1-001 = OWNER APPROVED PLAN / IMPLEMENTATION NOT STARTED
ERP-INQUIRY-M1 = PAUSED B2B TRACK / OWNER REACTIVATION REQUIRED
GitHub main baseline = d4bd3a6ae9eba16e8b3e5e35a957fa4632c8669d
GitHub = UNIQUE SOURCE OF TRUTH
Deployment = NO / LOCAL DEVELOPMENT ONLY
```

## Current business objective

```text
Primary objective: replace Tencent Docs for daily order → fulfillment → shipping operations.
Release 1 go-live condition: staff can complete a real day without Tencent Docs.
Required productivity: single copy, batch copy, batch paste and batch fill.
Inventory role: provide real Warehouse / SKU availability truth to Shipping; Shipping must not hard-code warehouse selection.
B2B Inquiry: separate roadmap, paused until explicit Owner reactivation.
Remote deployment: not part of this current local development plan.
```

## Immediate sequence

```text
1. Close current Shipping UAT defects without merging incomplete warehouse selection logic.
2. Formalize ERP-INVENTORY-FOUNDATION-M1-001 dependency/menu/schema preflight.
3. Implement Warehouse Master and SKU × Warehouse inventory truth on a dedicated branch/worktree.
4. Deliver /inventory, /inventory/add, /inventory/list and /inventory/products.
5. Deliver six warehouse detail pages, warehouse addresses, stay-time/restock views and editable Markdown billing tables.
6. Integrate Shipping with Inventory candidate/recommendation logic and operator override + save + Audit.
7. Complete Tencent Docs productivity layer and Release 1 Owner UAT.
8. Continue WooCommerce inbound/outbound/sync only after Release 1 operational closure.
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

### ERP-INQUIRY-M1 — B2B Inquiry Foundation [PAUSED]

- M1.0 Inquiry architecture / data / API contract — COMPLETE;
- M1.1 Company — COMPLETE;
- M1.2 Contact — PAUSED;
- M1.3 Case — PAUSED;
- M1.4 Inbound Message — PAUSED;
- M1.5 Inquiry Inbox — PAUSED;
- M1.6 Email Intake — PAUSED;
- M1.7 WhatsApp Intake — PAUSED;
- M1.8 Yesterday / Waiting Dashboard — PAUSED;
- M1.9 Local Milestone UAT — PAUSED;
- reactivation requires explicit Owner decision.

### MVP1.1 — WooCommerce Inbound

- connection;
- single/incremental/historical import;
- normalized DTO;
- upsert into existing orders;
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
