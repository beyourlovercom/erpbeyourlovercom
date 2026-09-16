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

## Production apply + Owner UAT closure — 2026-09-16
After PR #328 merged, a fresh Production preflight was generated. The pre-merge preview fingerprints were not reused for the write gate.

Final authorized source / plan:
- source checksum: `e9f692507d0aafbb28fe44c5d568d2424e644f387f0a58c0fa360d091a16361b`
- source scan SHA: `ae5425bca9edc00836f859a1ad27d9a4c59fd2766c6624a142693b570d280b5c`
- plan SHA: `1e3dc7ada671ec7f6f09c79dd88bc7e1e86d07bf70269839fd76c92b31723c26`
- preflight: READY 197 / REVIEW 4,157 / SKIP 1,000
- READY manifest SHA: `59a299fcfc9954507533f9508bc2d2bd3874e9d03e4819e2b0b5cd8ea0fa7d94`

Production apply result:
- shipments created: 197
- shipments total: 1,019 -> 1,216
- immutable HFR3 apply audits: 974 -> 1,171
- target ShipmentAttempt rows created: 0
- target Woo sync outbox rows created: 0
- target Order business-state hash unchanged: yes
- all 197 applied rows use warehouse `US-LA` / 美西 MorePlus, carrier `USPS`, service `USPS Ground Advantage`
- verification SHA: `7056fdeca326e207d67906e9a826ddf62744d2f2a03d0023508a80f56347bd74`

Idempotency rerun after apply:
- READY: 0
- REVIEW: 4,157
- SKIP: 1,197
- already applied: 197

Owner UAT sample: `007-1125-c30920` / Order 2673. Production UI rendered the canonical order, 美西 MorePlus 仓, `USPS`, `USPS Ground Advantage`, and tracking `9434650899561073028159`. Owner replied `pass` on 2026-09-16.

HFR-5 is CLOSED / UAT PASS. Remaining 4,157 review candidates are not silently backfilled. No changelog entry was created, per manual-only changelog policy.
