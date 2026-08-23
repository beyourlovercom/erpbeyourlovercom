---
type: living-roadmap
project: BYL ERP
version: 1.0
updated: 2026-08-23
---

# Living Roadmap

## Program Roadmap

```text
Architecture Migration / Global Architecture Gate [COMPLETE]
→ MVP1 Existing Operations / Tencent Docs Replacement [IMPLEMENTATION COMPLETE; HUMAN UAT REMEDIATION OPEN]
→ ERP-INQUIRY-M1 B2B Inquiry Foundation [CURRENT]
→ ERP-INQUIRY-M1 Local Milestone UAT
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
Local Baseline Reconciliation = COMPLETE / PR #82 MERGED / main faa45f9620211f3c398dde7f30c1a886313ced14
US Local Runtime = http://erp.b / primary full verification
China Local Runtime = http://erp.b / exact-SHA smoke by default
GitHub = UNIQUE SOURCE OF TRUTH
CURRENT_PHASE = ERP-INQUIRY-M1 / CONTRACT ACCEPTED / IMPLEMENTATION PREPARATION
CURRENT_WORK_PACKAGE = ERP-GOV-INQUIRY-M1.0-ACCEPTANCE-SYNC-001
NEXT_AFTER_MERGE = ERP-INQUIRY-M1.1 Company Work Package preparation
Remote Milestone Target = https://erp.beyourlover.com
Deployment = NO / MILESTONE GATED
```

## Current business objective

```text
Primary objective: automatically capture Email and WhatsApp inquiries into ERP.
Primary user outcome: see yesterday-new inquiries, current waiting-to-handle inquiries, and the underlying Company / Contact / Case timeline.
Local-first rule: normal development validation remains on China Mac at http://erp.b.
Remote-release rule: https://erp.beyourlover.com is updated only after a stable Milestone passes local regression and Owner UAT.
```

## Immediate sequence

```text
1. ERP-INQUIRY-M1.0 contract is Owner approved and merged through PR #85.
2. Synchronize accepted contract status, estimates and next-package position into canonical Roadmap sources.
3. GPT Web prepares the bounded ERP-INQUIRY-M1.1 Company implementation Work Package.
4. Owner explicitly authorizes the M1.1 implementation package.
5. US Mac Claude Code implements only that approved scope and pushes to GitHub.
6. China Mac Codex performs the required exact-SHA verification tier on http://erp.b (daily smoke by default; full independent verification when milestone/release/high-risk or explicitly escalated) and returns evidence only.
7. GPT Web performs all diagnosis/root-cause analysis; Owner performs UAT / merge authorization.
8. Only after ERP-INQUIRY-M1 Milestone PASS, deploy the frozen GitHub revision to https://erp.beyourlover.com.
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

### ERP-INQUIRY-M1 — B2B Inquiry Foundation

- M1.0 Inquiry architecture / data / API contract;
- M1.1 Company;
- M1.2 Contact;
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
