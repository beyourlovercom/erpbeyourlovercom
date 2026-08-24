---
type: living-roadmap
project: BYL ERP
version: 1.0
updated: 2026-08-24
---

# Living Roadmap

## Program Roadmap

```text
Architecture Migration / Global Architecture Gate [COMPLETE]
→ MVP1 Existing Operations / Tencent Docs Replacement [IMPLEMENTATION COMPLETE; HUMAN UAT REMEDIATION OPEN]
→ ERP-INQUIRY-M1 M1.0 Contract + M1.1 Company [COMPLETE]
→ ERP-ORDER-FULFILLMENT-M1 Unified Manual Order → Logistics [CURRENT]
→ ERP-ORDER-FULFILLMENT-M1 Local UAT / Owner Gate
→ Resume ERP-INQUIRY-M1 at M1.2 Contact [PAUSED, NOT CANCELLED]
→ ERP-INQUIRY-M1 M1.2–M1.9 + Local Milestone UAT
→ Milestone Remote Release / https://erp.beyourlover.com
→ MVP1.1 WooCommerce Inbound
→ MVP1.2 Single Tracking Outbound
→ MVP1.3 Sync Orchestration
→ Product / Media
→ WordPress Product and Image Sync
→ Content / SEO
→ Posts / Pages
→ GA4 / GSC / Cloudflare
→ Google Docs Publishing
→ Full Order Workspace
→ Logistics OS
→ KOL OS and Social Providers
→ Inventory and Procurement
→ Accounting / ROI Expansion
→ Shopify and Additional Integrations
→ Production Hardening
```

Program sequence, estimate classification and remaining-hours evidence are maintained in [[13_PROGRAM_ROADMAP_AND_HOURS]].

## Current status

```text
Architecture Foundation = COMPLETE / PASS
Global Architecture Gate = PASS
MVP1 Foundation = COMPLETE / PASS / CLOSED
MVP1 Order Core = COMPLETE / PASS / CLOSED
MVP1 Fulfillment = COMPLETE / PASS / CLOSED
MVP1 Manual Sync = COMPLETE / PASS / CLOSED
MVP1 Finance / Order Analysis = COMPLETE / PASS / CLOSED
MVP1 Automated UAT Readiness = PASS
MVP1 Human UAT = CHANGES REQUIRED / 4 LEGACY FINDINGS OPEN / DEFECT-001 CLOSED BY PR #87
ERP-INQUIRY-M1.0 Contract = COMPLETE / OWNER APPROVED / MERGED
ERP-INQUIRY-M1.1 Company = COMPLETE / MERGED BY PR #89
ERP-INQUIRY-M1.2 Contact = PAUSED / NOT CANCELLED
US Local Runtime = http://erp.b / primary full verification
China Local Runtime = http://erp.b / exact-SHA verification
GitHub = UNIQUE SOURCE OF TRUTH
CURRENT_PHASE = ERP-ORDER-FULFILLMENT-M1 / CONTRACT FORMALIZATION
CURRENT_WORK_PACKAGE = ERP-ORDER-FULFILLMENT-M1-001 / IMPLEMENTATION NOT AUTHORIZED
CURRENT_MAIN_BASELINE = 6ac25f2168dea7e8cd9e8f4c49c10752642a44df
NEXT_GATE = OWNER AUTHORIZATION FOR US CLAUDE IMPLEMENTATION PREFLIGHT / IMPLEMENTATION
Remote Milestone Target = https://erp.beyourlover.com
Deployment = NO / MILESTONE GATED
```

## Current business objective

```text
Primary objective: make one canonical manual Order flow serve customer orders and KOL fulfillment, then feed one Logistics / Shipment workflow.
Primary user outcome: operators can create their permitted Orders, logistics can reliably copy shipment information and ship them, and Finance can rely on the same orders.id for later profitability analysis.
Canonical Order rule: all order transaction projections use one orders.id; no customer/KOL/logistics/accounting duplicate order truth.
Order-number rule: external_order_number is the primary user-visible business order number; internal_order_number is generated, unique and hidden by default.
Inquiry rule: ERP-INQUIRY-M1.2 Contact is paused only; Inquiry resumes after this package passes its Owner gate unless Owner changes priority again.
Local-first rule: normal development validation remains on local http://erp.b environments.
Remote-release rule: https://erp.beyourlover.com is updated only after a stable Milestone passes local regression and Owner UAT.
```

## Immediate sequence

```text
1. Freeze BYL ERP Canonical Order / Finance / KOL Data Contract V1 and formalize ERP-ORDER-FULFILLMENT-M1-001 on one delivery branch / Draft PR.
2. Owner explicitly authorizes the bounded implementation package before Claude writes product code or Migration files.
3. US Mac Claude Code performs the required read-only implementation preflight, reports exact migration/backfill and permission/data-scope evidence, then implements only the approved scope.
4. US Mac performs full package verification.
5. Because schema semantics + authorization are affected, China Mac Codex performs full exact-SHA Tier B independent verification and evidence only; no source mutation or diagnosis.
6. GPT Web performs all diagnosis/root-cause analysis; Owner performs Local UAT and merge authorization.
7. Revalidate the four remaining MVP1 Order/Fulfillment Human UAT findings; close only those supported by exact evidence and Owner UAT.
8. After ERP-ORDER-FULFILLMENT-M1 passes and merges, resume ERP-INQUIRY-M1 at M1.2 Contact unless Owner issues another priority change.
9. Remote deployment remains milestone-gated and is not part of this package.
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
