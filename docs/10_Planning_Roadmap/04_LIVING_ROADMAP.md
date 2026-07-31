---
type: living-roadmap
project: BYL ERP
version: 1.0
updated: 2026-07-31
---

# Living Roadmap

## Current status

```text
001A Foundation Bootstrap = PASS
Repository Governance = PASS
001B Auth / Authorization = MERGED (merge SHA: 5e100cc8e80480ecb6dbd1b38cbfbe9bddba12f4)
001C App Shell = current package
001D Runtime / Audit / Sync Foundation = waiting
001E Shared UI / E2E = waiting
001F Architecture Re-audit = waiting
Global Architecture Gate = FAIL
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
1. Complete 001B remediation.
2. Re-review and merge 001B.
3. Build bounded MVP1 application shell/runtime.
4. Build final-compatible order/shipment/sync/cost foundations.
5. Build manual order and fulfillment workflow.
6. Build manual website-sync queue.
7. Build order analysis.
8. Test, UAT and deploy MVP1.
```

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
