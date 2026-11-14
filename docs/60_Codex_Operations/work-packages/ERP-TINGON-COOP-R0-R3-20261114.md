# ERP-TINGON-COOP-R0-R3-20261114 — Partner Fulfillment Dev

- Owner authorization: 2026-11-14, implement only R0–R3 Dev core.
- EXECUTION_PROVIDER: GPT_WEB (direct bounded implementation; local Claude Code unavailable, Codex limited to editorial tasks).
- VERIFICATION_TIER: HIGH_RISK.
- Repository: beyourlovercom/erpbeyourlovercom, isolated US Mac mini worktree; baseline origin/main `3ef751a8`.
- Scope: warehouse TINGON, Vita manual allocation and split handling, Orders 合作订单, Logistics Tingon发货 with brand/partner separation and manual carrier + Tracking.
- Forbidden: merge to main, Production rollout, live shipments/provider API calls, Woo site writes, loosening payment/stock/approval gates, changing unrelated tabs.

## Design and data contract

- TINGON is a partner-type warehouse, with inventory not tracked by ERP; never automatically recommended/reserved.
- Source brands are product facts; partner fulfillment is shipment warehouse identity. Original orders are shown once in Orders and can produce individual Tingon derived shipments.
- New L2 entries in both approved baseline and runtime config. Existing status authority remains unchanged.
- Partner shipping requires a real carrier and Tracking; save draft does not ship; duplicate ship blocked 409; Tracking correction uses existing audited service and periodic Sync OS handoff.
- All actions protected by existing logistics permissions and eligibility/payment gates.
- Existing Gift placeholder stays subject to real gift mapping/stock rules; no fabricated gift SKU or bypass.

## Acceptance and evidence (isolated local SQLite, not Production)

- R0 repo route, schema, menu and core status audit: COMPLETE.
- R1 partner warehouse + allocation + inventory/manual exception: CODE COMPLETE and HTTP integration PASS.
- R2 cooperation order route, original vs derived, source filtering and export: CODE COMPLETE and integration PASS.
- R3 Tingon page, brand vs allocation, search, pagination, manual shipment tracking: CODE COMPLETE and integration PASS.
- Targeted Tingon PHP tests: 6 scenarios, 144 assertions PASS (test runner prints deprecation warnings from preexisting global test config).
- FulfillmentAllocationV1 + GiftZone: 19 scenarios, 243 assertions PASS.
- npm ci, composer install, typecheck, npm build and ERP 30-tab build verifier: PASS.
- Full Vitest: 915 tests PASS, 7 failed across untouched InventoryBatchSync, WarehouseClearanceGiftPool and WarehouseCostCenterP3 files; these failures are not represented as PASS.
- Existing ApprovedMenuBaselineTest: two baseline discrepancies (accounting-loss-orders URL/slug and preexisting runtime-vs-approved labels/order such as carrier/marketing); scoped Tingon menu contract test PASS. Owner should resolve baseline governance separately before merge.
- 007windows browser UAT: NOT RUN in this Dev package.
- Dev hosting site: NOT DEPLOYED; isolated branch and local tests only.
- Production: NOT MODIFIED.

## Merge and Dev gates

- Draft PR only; no merge or release without explicit Owner PASS and CI checks.
- Any Dev staging installation must be isolated and use test database; migration may only be executed there, never on Production under this authorization.
- Follow-up R4 regression/role UAT and R5 release only after Owner review.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture → MVP1 operations → Inquiry → Woo Integration → Products/Media → Content → Orders → Logistics → KOL → Inventory/Procurement → Accounting → Integrations → Hardening
CURRENT_TRACK_PHASE_MAP: Tingon R0 audit COMPLETE → R1 partner warehouse COMPLETE → R2 cooperation view COMPLETE → R3 fulfillment view DEV CODE COMPLETE → R4 role/UAT PENDING → R5 separately gated release WAITING
COMPLETED_PHASES: R0 R1 R2 R3 local implementation and targeted tests
CURRENT_PHASE: Tingon Dev PR preparation, Owner UAT pending
CURRENT_WORK_PACKAGE: ERP-TINGON-COOP-R0-R3-20261114
PACKAGE_STATUS: DEV_CODE_COMPLETE / OWNER_UAT_PENDING / FULL_BASELINE_CHECKS_RED
WAITING_PHASES: Owner UAT → baseline CI closure → approved merge → Production separately gated
BASELINE: origin/main 3ef751a8
GITHUB_STATE: isolated feature branch, Draft PR only (number assigned after push)
SOURCE_CONFLICTS: APPROVED_MENU baseline already mismatched runtime on unrelated existing menu entries; full Vitest Inventory tests 7 failures; both recorded above
GLOBAL_GATE: Dev code checks pass; full CI and Owner browser UAT not passed; merging prohibited
PRODUCTION_DEPLOYMENT_STATUS: NO; repository branch only; no live ERP mutation
NEXT_DEPENDENCY: Create Draft PR, surface Owner UAT checklist, resolve red baseline before merge
EVIDENCE_AS_OF: 2026-11-14

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 14 effective h planning, range 11–17 effective h (R0–R3 only)
KNOWN_CURRENT_TRACK_REMAINING: R4-R5 3–7 h planning, excludes UAT waiting
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE (general ERP program separate)
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE (not part of this branch)
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: Tingon R0–R5 preliminary total 14–24 h
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE (no accepted work log)
ESTIMATE_CONFIDENCE: Medium for isolated code; low for Dev hosting/legacy baseline
ESTIMATE_AS_OF: 2026-11-14
ESTIMATE_METHOD: Actual existing Laravel/Vue code and tests; planning range from initial approved roadmap

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 2.0 h
CORE_EXECUTION: 8.0 h
TEST_AND_VALIDATION: 3.0 h
REMEDIATION_BUFFER: 1.0 h
ESTIMATED_EFFECTIVE_HOURS: 14.0 h
ESTIMATE_RANGE: 11–17 h
ESTIMATE_BASIS: Existing order and logistics services + one new warehouse + two L2 views
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: INCLUDED (file authoring only; no database execution outside local tests)
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
