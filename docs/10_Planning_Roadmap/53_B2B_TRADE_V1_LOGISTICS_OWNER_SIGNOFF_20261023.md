# B2B Trade Order V1 RC2 — Logistics Owner approved catalogue parity

Date: 2026-10-23. This is a **specific Owner business-rule confirmation**, not approval of Architecture golden inventory rewrite, main merge, live shipments, Finance posting, hosted CI, or Production deployment.

## Explicit Owner approval
The Owner confirmed the recommendations:
- Mexico factory fulfillment through **Weship** uses its existing active service **Standard**, instead of the stale frozen test expecting null.
- **FedEx** bilingual display name must be **FedEx / 联邦快递** (active master/seed), not the outdated FedEx / 联邦 test label.

## Exact small change, no runtime policy change
In the isolated RC2 source, updated ONLY the two conflicting assertions in:
- tests/Unit/FulfillmentPlanPreviewServiceTest.php
- tests/Unit/WarehouseRoutingPolicyTest.php

The existing seeded carrier master, CarrierRoutingPolicy, shipment validation, Production carrier/service options and other Frozen Logistics expectations are not modified. No physical shipping transaction, warehouse label printing, money or order mutation.

## Verification at owner-approved source
- Targeted factory/route/master carrier suites: **24 PHPUnit tests / 307 assertions PASS**.
- Full isolated RC2 suite: **1,974 tests / 18,240 assertions / 0 errors / 3 failing assertions**, 6 skipped, compared with 5 failures before this Owner approval. The remaining **3 are all Architecture frozen inventory tests** in Issue #474. FULL CI is NOT PASS.
- GitHub-hosted Actions is blocked before steps begin by account billing/spending restriction Issue #498, independently from these three local failures.
- Both isolated private Dev services and original real ERP remain untouched, release Trade/Finance write flags remain disabled.

## Still on HOLD
1. Architecture Owner must audit frozen module file, directory, migration and frontend hashes and approve explicit narrowly reviewed diff; no bulk rewrite or suppression.
2. Full local/hosted CI or explicitly approved equivalent on the exact final source HEAD.
3. Real authorized multi-role login PC and physical M14 Owner UAT (no credential bypass).
4. Independent Finance Owner manual statement, double reviewer, refund, FX and cash source-of-truth acceptance.
5. Exact-head controlled GitHub main merge and separate backed-up production schema/deployment and staged Trade/Finance activation approvals.

This is an addition to the ONE consolidated main-targeted Draft PR #497, not an extra merge vehicle. Historic stacked Drafts remain read-only review evidence.
