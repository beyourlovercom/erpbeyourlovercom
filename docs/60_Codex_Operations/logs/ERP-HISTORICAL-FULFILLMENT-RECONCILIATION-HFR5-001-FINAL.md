# ERP-HISTORICAL-FULFILLMENT-RECONCILIATION-HFR5-001 — FINAL

## Baseline
- origin/main at branch creation: `037374d68e9062c6010972775617876abf8603b0`
- branch: `feature/erp-historical-fulfillment-reconciliation-hfr5-001`
- worktree: `/Users/a/AI/007/worktrees/erp-historical-fulfillment-reconciliation-hfr5-001`

## Implemented
- Added `HistoricalWarehouseEvidenceRecoveryAdapter`.
- Added `HistoricalCarrierServiceInvoiceEvidenceAdapter`.
- Added focused unit coverage for exact identity/tracking, frozen warehouse aliases, unresolved warehouses, carrier/service conflicts and idempotency.
- HFR-3 classifier/write service remains unchanged.

## Production read-only discovery
Remaining post-HFR4 REVIEW: 4,354.
Primary first reason before HFR5: missing approved warehouse 3,812.
ERP workflow/audit tables did not contain row-level historical warehouse evidence for those Orders.
Tencent BYL logistics database supplied 1,058 exact Order+Tracking warehouse matches with zero warehouse conflicts.
835 of those use already frozen canonical warehouse aliases.
## US warehouse invoice evidence
Scanned 16 unique monthly invoice workbooks across 2025-07 through 2026-09.
Explicit row-level Shipping-sheet service evidence produced:
- raw valid rows: 874
- unique resolved tracking: 675
- conflicting tracking/service pairs: 0
- USPS: 550
- FedEx: 85
- GOFO Express: 37
- UPS: 3

Exact candidate join:
- invoice tracking matches: 513
- exact identity + carrier-compatible: 418
- newly service-approved: 418
- new carrier/source conflicts: 27, fail closed
- identity-not-exact: 68, untouched

After warehouse + service enrichment, 197 candidates satisfy all HFR Class A requirements.
All 197 are unique Orders/tracking, warehouse `US-LA` id=1, carrier `USPS`, service `USPS Ground Advantage`.
## Validation
- HFR focused suite: 37 tests / 115 assertions PASS.
- HFR-3 backfill regression: 7 tests / 34 assertions PASS.
- Pint PASS.
- `git diff --check` PASS.

## Production read-only preview
- source checksum: `39f092cd3796e8344d87fab3c29e702e7988e46ef0ec030a40514b1fd6738afd`
- source scan SHA256: `d9f9c1f42a76ca25e3344293ec9d8e5537dd85b7f4917f8d88e2dfaa60287eda`
- plan checksum: `b2a6058ca2e673799145c1edcff2f6fdd2c79cfc60e15702741cd8ddfadf13fe`
- READY: 197
- REVIEW: 4,157
- SKIP: 1,000
- READY manifest SHA256: `59a299fcfc9954507533f9508bc2d2bd3874e9d03e4819e2b0b5cd8ea0fa7d94`

Zero-write proof before/after preview:
- shipments: 1,019 -> 1,019
- shipment_attempts: 1,010 -> 1,010
- HFR3 apply audits: 974 -> 974

No HFR-5 Production mutation has occurred.
A fresh post-merge preflight is required before any new Production Data Write Gate.
