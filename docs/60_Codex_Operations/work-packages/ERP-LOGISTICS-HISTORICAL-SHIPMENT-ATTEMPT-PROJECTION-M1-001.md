---
type: work-package
id: ERP-LOGISTICS-HISTORICAL-SHIPMENT-ATTEMPT-PROJECTION-M1-001
status: IMPLEMENTED_LOCAL_NOT_DELIVERED
execution_provider: GPT_WEB
verification_tier: HIGH_RISK
baseline: current dedicated worktree HEAD before this package
dependencies: ERP-LOGISTICS-HISTORICAL-IMPORT-M1-M1-1-CLOSURE; main PR #219 queue boundary
dependency_type: MERGED_PR
parallel_safe: YES
provider_handoff: US_CODEX_CLI initial implementation -> GPT_WEB bounded review/remediation; Codex session ended before GPT edits
---

# Historical ShipmentAttempt projection M1

Additive, disabled-by-default projection of applied historical order materialization evidence into `ShipmentAttempt` snapshots. Preview is idempotent by materialization batch and source checksum; apply is transactional, ready-only, drift-checked, and creates no `Shipment`, order lifecycle change, reservation, sync, packaging, billing, or review record. Unknown warehouses remain ready with null warehouse authority, and the XLSX agent remains raw evidence rather than an ERP carrier.

Expected Production replay baseline at freeze: 765 applied orders; 598 exact warehouse; 167 unresolved; 765 unique tracking; carrier-master exact 0; active Shipment 0; ShipmentAttempt 0.

## Local validation evidence — 2026-09-06

- Focused projection contract: 9/9 tests, 85 assertions PASS.
- Historical Logistics regression: 64/64 tests, 574 assertions PASS.
- `git diff --check`: PASS.
- PHP lint for all changed PHP files: PASS.
- Architecture A/B: this branch and pure `main@960e65f` both show the same 3 pre-existing stale baseline failures in `ModuleArchitectureTest`; no architecture baseline update is in scope.
- No Production mutation occurred during implementation/verification. A post-implementation read-only Production reconciliation was run after local gates.

## Production read-only reconciliation — 2026-09-06

- Materialization batch: 1; source checksum `164fe6242ec612d024bd521623879b0d3ede7afef2054347a8bce07f8f9d8c41`.
- Applied rows evaluated: 765.
- Projection classification: 765 Ready / 0 Review.
- Warehouse: 598 exact / 167 unresolved.
- Active Shipment conflicts: 0; existing ShipmentAttempt conflicts: 0.
- Frozen Ready manifest SHA256: `9e298dbf73e0a42a8d7138370fd969ea264fe11b6500aa60e7d36dc6cf2bb310`.
- Production remained `shipments=0`, `shipment_attempts=0`; reconciliation was read-only.

## Validation contract

- Gate default is false; preview/apply audit once and repeat apply is inert.
- Exact warehouse mapping is limited to USA 美西仓→US-LA, 美东仓→US-NY, Factory 国内仓→FACTORY, Canada 加拿大仓→CA.
- `shipment_status=historical_evidence`, `shipment_id=null`, `shipped_at=null`, and all carrier fields null are frozen invariants.
- Tracking conflicts, prior attempts, and active shipments are Review; apply aborts the whole transaction on frozen-source drift.
- Migration uses explicit MySQL-safe names shorter than 64 characters.
- Tests: focused Logistics projection/materialization and Inventory demand safety; `git diff --check`.

## Delivery boundary

No Production access, replay, `.env` change, commit, push, PR, merge, or stale Architecture baseline update is included.

## PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration → MVP1 Existing Operations → Inquiry M1 → WooCommerce Inbound → Tracking Outbound → Sync Orchestration → Product/Media → WordPress Product/Image Sync → Content/SEO → Posts/Pages → GA4/GSC/Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS → Inventory/Procurement → Accounting/ROI → Shopify/Additional Integrations → Production Hardening
CURRENT_TRACK_PHASE_MAP: Logistics historical import/materialization complete → historical ShipmentAttempt projection M1 current → Owner/UAT and delivery gates waiting
COMPLETED_PHASES: Logistics historical import M1/M1.1
CURRENT_PHASE: Historical ShipmentAttempt projection M1 local implementation
CURRENT_WORK_PACKAGE: ERP-LOGISTICS-HISTORICAL-SHIPMENT-ATTEMPT-PROJECTION-M1-001
PACKAGE_STATUS: IMPLEMENTED_LOCAL_NOT_DELIVERED
WAITING_PHASES: Owner acceptance; Git delivery; Production replay
BASELINE: Dedicated worktree baseline; no Production access
GITHUB_STATE: UNKNOWN WITH EVIDENCE; no GitHub delivery performed by this task
SOURCE_CONFLICTS: NONE WITH EVIDENCE
GLOBAL_GATE: Owner-controlled UAT, irreversible real-data replay, and Production release remain closed
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE; no Production access or deployment performed
NEXT_DEPENDENCY: Owner review of local validation, then normal repository delivery gates
EVIDENCE_AS_OF: 2026-09-06 local gates + Production read-only reconciliation

## ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: UNKNOWN WITH EVIDENCE; no issued estimate supplied in task context
KNOWN_CURRENT_TRACK_REMAINING: UNKNOWN WITH EVIDENCE
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE; architecture baseline intentionally untouched
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; no accepted Work Log ledger supplied
ESTIMATE_CONFIDENCE: UNKNOWN WITH EVIDENCE
ESTIMATE_AS_OF: 2026-09-05
ESTIMATE_METHOD: Not estimated in supplied package request

## ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: UNKNOWN WITH EVIDENCE
CORE_EXECUTION: UNKNOWN WITH EVIDENCE
TEST_AND_VALIDATION: UNKNOWN WITH EVIDENCE
REMEDIATION_BUFFER: UNKNOWN WITH EVIDENCE
ESTIMATED_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE
ESTIMATE_RANGE: UNKNOWN WITH EVIDENCE
ESTIMATE_BASIS: No issued estimate supplied
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
