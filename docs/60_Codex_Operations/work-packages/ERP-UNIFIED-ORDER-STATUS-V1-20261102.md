---
type: work-package
project: BYL ERP
work_package_id: ERP-UNIFIED-ORDER-STATUS-V1-20261102
title: Unified Order Workflow Status V1
status: R1–R6 CODE COMPLETE / SCOPED TESTS PASS / BASELINE FULL-SUITE FAILURES MATCH / OWNER UAT PENDING
execution_provider: US_CODEX_CLI (implementation) + GPT_WEB (bounded exact remediation)
design_authority: GPT_WEB + OWNER
owner_visual_design_pass: GitHub Issue #595
baseline: origin/main at worktree start
branch: feature/erp-unified-order-status-v1-20261102
verification_tier: HIGH_RISK
classification: HIGH_RISK
estimate: 27–43h
production: NOT AUTHORIZED
---

# Scope and safety

R1–R6 implementation is limited to an additive staff workflow-progress classification. `orders.order_status` remains the canonical immutable fulfillment/payment/cancel truth. No Shipment, WooCommerce, AfterShip, OMS, Inventory, Accounting, Payment, refund, archive or menu business-state behavior is changed. No external API, real OMS, real-data write, production migration, browser launch, service restart, main merge or deployment is authorized. Owner-approved routine Git commit/push/Draft-PR delivery is allowed; this document does not authorize Production.

## R1–R6 status

- R1 registry/projection: remediated; canonical shipped/completed/cancelled display as real system states when staff progress is absent or stale pre-shipment; verified later KOL stages (e.g. Creating) remain visible after shipment, while separate canonical order_status remains unchanged.
- R2 persistence: implemented as nullable `orders.workflow_status` plus `workflow_revision` and append-only `order_workflow_status_histories`; existing rows receive NULL/0 with no backfill.
- R3 service/security: remediated with required revision-only optimistic compare, archived/deleted recheck inside the row lock, KOL scope, idempotency, and AuditRecorder evidence.
- R4 views: remediated in Orders, domestic, finance, and all overseas warehouse rows; overseas retains existing Dede/shipping controls and adds a read-only unified status column.
- R5 UI: shared compact color-coded, accurately numbered (1.1/1.2/1.3/1.5/1.6/2.1...3.2) pill/dropdown uses revision-only PATCH, watches refreshed props, disables authoritative target choices, allows authorized post-shipment KOL workflow progression and restores actual DOM selection on rejection.
- R6 tests/verification: focused PHPUnit 5/5 (25 assertions), focused Vitest 40/40, typecheck PASS, production build PASS with isolated `--configLoader runner`, PHP lint PASS, and diff-check PASS. Full Vitest is 678/683; same five Inventory failures reproduced on exact clean origin/main@1c9fc23e (baseline 675/680) in WarehouseClearanceGiftPool and WarehouseCostCenterP3. No new full-suite failures. No migration was executed against a real or project database.

## Risks and pending gates

This is HIGH_RISK because it introduces a migration and authorization/concurrency behavior. Focused tests, typecheck, build, PHP lint and diff checks are complete; migration execution and architecture checks were not run. Post-development live Local/UAT inspection is pending and remains an Owner gate. Production gate is NOT AUTHORIZED.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Gate → MVP1 Existing Operations → ERP-INQUIRY-M1 → Inquiry Local UAT → milestone-gated remote release → WooCommerce Inbound → Tracking Outbound → Sync Orchestration → Product/Media → WordPress Product/Image Sync → Content/SEO → Posts/Pages → GA4/GSC/Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS → Inventory/Procurement → Accounting/ROI → additional integrations → Production Hardening
CURRENT_TRACK_PHASE_MAP: Existing Orders baseline → ERP-UNIFIED-ORDER-STATUS-V1 R1–R6 implementation → focused verification → Owner live Local/UAT → Production gate
COMPLETED_PHASES: Owner visual design PASS recorded in GitHub Issue #595; R1–R6 implementation + scoped tests + exact-main full-suite comparison
CURRENT_PHASE: R6 Owner live Local/UAT preparation; Draft PR delivery
CURRENT_WORK_PACKAGE: ERP-UNIFIED-ORDER-STATUS-V1-20261102
PACKAGE_STATUS: IMPLEMENTED / SCOPED TESTS PASS / BASELINE-MATCH FULL-SUITE FAILURES / POST-DEV LIVE UAT PENDING
WAITING_PHASES: Owner live Local/UAT PASS; Production release gate
BASELINE: origin/main at worktree start; isolated feature branch; no uncommitted baseline changes observed
GITHUB_STATE: dedicated feature worktree at main 1c9fc23e; Draft-PR delivery in progress; main merge NOT AUTHORIZED before Owner live UAT
SOURCE_CONFLICTS: NONE WITH EVIDENCE for feature; 5 full-suite Inventory UI expectations fail identically on exact main baseline; repository governance loaded before mutation
GLOBAL_GATE: PASS for Owner-authorized bounded implementation; visual design PASS recorded in Issue #595
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE; no production access, migration or deployment performed
NEXT_DEPENDENCY: deliver Draft PR; prepare real Local/Dev UAT with isolated data; Owner inspect and PASS; no Production until separate release
EVIDENCE_AS_OF: 2026-11-02

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: planning value/range 27–43h
KNOWN_CURRENT_TRACK_REMAINING: dependency-backed verification and Owner UAT only for this package; exact program remainder UNKNOWN WITH EVIDENCE
ARCHITECTURE_GATE_REMAINING: 0 for existing architecture; package-local migration/security verification pending
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE; not recalculated here
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE; not recalculated here
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE; not recalculated here
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; accepted actual-hour ledger not available
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE; outside this package
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE; outside this package
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; outside this package
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; no accepted Work Log ledger
ESTIMATE_CONFIDENCE: MEDIUM
ESTIMATE_AS_OF: 2026-11-02
ESTIMATE_METHOD: frozen R1–R6 contract, additive migration, service authorization/concurrency, shared projections, focused verification

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 3h / 2–5h
CORE_EXECUTION: 20h / 15–25h
TEST_AND_VALIDATION: 6h / 5–10h
REMEDIATION_BUFFER: 4h / 4–4h
ESTIMATED_EFFECTIVE_HOURS: 33h
ESTIMATE_RANGE: 27–43h
ESTIMATE_BASIS: Owner-approved high-risk R1–R6 package; estimate includes migration authoring, implementation, focused tests and verification preparation, excludes production release
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
