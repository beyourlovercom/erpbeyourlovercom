---
type: living-roadmap
project: BYL ERP
version: 1.0
updated: 2026-08-01
---

# Living Roadmap

## Program Roadmap

```text
Architecture Migration / Global Architecture Gate
→ MVP1 Manual Fulfillment / Tencent Docs Replacement
→ MVP1.1 WooCommerce Inbound
→ MVP1.2 Single Tracking Outbound
→ MVP1.3 Sync Orchestration
→ Customer / Lead Pilot
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
→ Dev UAT and Production Hardening
```

Program sequence, estimate classification and remaining-hours evidence are maintained in [[13_PROGRAM_ROADMAP_AND_HOURS]].

## Current status

```text
001A Foundation Bootstrap = MERGED / PASS
Repository Governance = PASS
001B Auth / Authorization = MERGED (merge SHA: 5e100cc8e80480ecb6dbd1b38cbfbe9bddba12f4)
001C App Shell = MERGED / PASS (PR #5; main commit: ba1638277bac5d333ee693d627a41ca9c950c565)
001D Runtime / Audit / Sync Foundation = COMPLETE / PASS
001D-A Runtime Foundation = MERGED / PASS (PR #9; main commit: 50803c3fcd51e9f8b3485f4fb57565154a284bf7)
001D-B Audit Foundation = MERGED / PASS (PR #12; main commit: e2247fcaaae690c94ef07d45c8807ce749de1b46)
001D-C Sync Outbox Foundation = MERGED / PASS (PR #13; accepted Head: e4bc57b7a3f0edbe84ccfde63d02d9135a4875e3; main commit: 548d53f1eae2ec14eea7261d3a101c1f2fda0dcc)
001E Shared UI / Browser E2E = NEXT / PREPARED / IMPLEMENTATION NOT STARTED
001F Architecture Re-audit = WAITING
Global Architecture Gate = FAIL
Deployment = NO
```

## Fast-track objective

```text
Target calendar: approximately one month
Target: replace Tencent Docs manual fulfillment workflow
Planning baseline: approximately 166 effective hours
Maximum planning envelope: approximately 192 effective hours
```

## Immediate sequence

```text
1. Independently review the 001D closure record and frozen 001E preparation package.
2. Owner separately decides whether to authorize 001E-A implementation and any dependency change.
3. Keep 001E implementation, 001F, Migration, UAT, deployment and business MVP packages blocked until their explicit gates.
```

The accepted 001D-C evidence remains bounded: real-MySQL two-connection claim concurrency and MySQL 5.7 are unverified, and no Dev, Staging or Production Migration was run.

## Incremental releases

### MVP1 — Manual Fulfillment on Final Core

- manual website/KOL order entry;
- shipment work queue;
- copy shipment data;
- carrier/tracking/shipped time;
- manual website-sync queue;
- order-level cost and profit;
- audit and export.

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

1. Customer / Lead Pilot.
2. Product / Media.
3. WordPress products and images.
4. Content / SEO.
5. Posts / Pages.
6. GA4 / GSC / Cloudflare.
7. Google Docs publishing.
8. Full Order Workspace.
9. Logistics OS.
10. KOL OS and social providers.
11. Inventory and procurement.
12. Accounting / ROI expansion.
13. Shopify and additional integrations.
14. Dev UAT and Production hardening.

## Change rule

This Roadmap is editable.

Any change must update:

- Decision Register;
- Change Log;
- affected module specification;
- affected estimate;
- current context.

Architecture changes require an explicit conflict decision.
