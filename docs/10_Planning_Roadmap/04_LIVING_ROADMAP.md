---
type: living-roadmap
project: BYL ERP
version: 1.0
updated: 2026-08-04
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
001E Shared UI / Browser E2E = COMPLETE / PASS
001E-A Shared UI and Design Tokens = MERGED / PASS (PR #17; accepted Head: e46fb54a1b41a1efa620b4af3646e104ff7bcbbc; main commit: 442a3a872628764fecb7209049d2daf752624e9a)
ERP-LOCAL-ADMIN-BYPASS-001 = MERGED / PASS (PR #16; accepted Head: 6b63229b7d776104a5736334de639d9b20fb69f3; main commit: 55ba7320a7046deb04a03abd99053ccd7b7ed11e)
ERP-SHELL-ACCOUNT-DOCK-001 = MERGED / PASS / OWNER VISUAL ACCEPTANCE PASS (PR #15; accepted Head: 0cac340443d47591b7e242ebe051fb4407d0a986; main commit: 32d7244b9b1c6d1fb0cf8863cb9af694e7acc10f)
ERP-MENU-BASELINE-001 = MERGED / PASS (PR #14; accepted Head: 6d5595e879778895105215dc98b1f740521b6f1a; main commit: 493f32ce7bf1be4368a7e9f97de9ecf9f9c9c9d8)
001E-B Browser E2E Foundation = MERGED / PASS / CLOSED (PR #21; main: 4b9fbb0deacdfc02439fb947398b87c148602c75)
001E-C Authentication / Permission / App Shell E2E = MERGED / PASS / CLOSED (PR #22; main: 3218473b1cfc93f098a2e9607aabe9adb16bcf0d)
001F Architecture Re-audit = ACTIVE / PREFLIGHT REVIEWED
001F-A Governance Status Correction & Gate Scope Freeze = COMPLETE / CLOSED (PR #23; accepted Head: f327910941dc0b6107bfaf40f5803b1448544ce4; main: 70b492772eedc993e5790d563c9c261cb0b1e0b7)
001F-B Modular Boundary Decision / Remediation = COMPLETE / REVIEWED / OPTION A1 APPROVED
001F-B1 Module Architecture Contract & Decision Freeze = COMPLETE / PASS / CLOSED (PR #24; accepted Head: f26b510b499ffef95e1723eb19d12bac84c93718; main: b3ac6268488b9ea96ceebaf7e7dcb23e8b5f2c9f; closure PR #25)
001F-B2 Module Skeleton & Automated Boundary Enforcement = PREFLIGHT COMPLETE / IMPLEMENTATION NOT STARTED
001F-B3 through 001F-B5 = PROPOSED / NOT AUTHORIZED
001F-C through 001F-F = PROPOSED / NOT AUTHORIZED
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
1. Prepare and review the B2 formal Work Package.
2. Obtain separate Owner authorization before B2 implementation.
3. Resolve the remaining Architecture Gate blockers in separately authorized packages.
4. Complete the final 001F re-audit before Global Architecture Gate closure.
5. Keep MVP1, UAT and deployment blocked until their explicit gates.
```

The accepted 001D-C evidence remains bounded: real-MySQL two-connection claim concurrency is a Global Gate blocker and no Dev Migration was run. MySQL 5.7 is outside the current supported baseline, does not block the Global Gate, and current code is not declared compatible; future support requires a new Architecture Decision and Compatibility Work Package. Staging Migration remains a UAT blocker, and Production Migration planning/rehearsal remains a deployment blocker.

001F-B Modular Boundary Decision / Remediation: COMPLETE / REVIEWED / OPTION A1 APPROVED. 001F-B1 Module Architecture Contract & Decision Freeze: COMPLETE / PASS / CLOSED (PR #24; closure PR #25). 001F-B2 Module Skeleton & Automated Boundary Enforcement: PREFLIGHT COMPLETE / IMPLEMENTATION NOT STARTED. 001F-B3 through 001F-B5 and 001F-C through 001F-F: PROPOSED / NOT AUTHORIZED. The v1.1 `app/Modules/**` contract is forward-effective; no module code or directory exists yet. MVP1 remains NOT STARTED / 0%.

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
