---
type: work-package
project: BYL ERP
work_package_id: ERP-ORDER-EDIT-V2-001
title: Order Create/Edit V2
status: OWNER AUTHORIZED / READY FOR CLAUDE IMPLEMENTATION
execution_provider: CLAUDE_CODE
design_authority: GPT_WEB + OWNER
test_provider: CODEX_CLI
baseline: 7bbb7f934f973f7079cecded67762888878601e9
branch: work/order-edit-v2-001
verification_tier: DAILY
updated: 2026-08-25
---

# ERP-ORDER-EDIT-V2-001

## Objective

Implement the Owner-authorized Order Create/Edit V2 UI described in `docs/10_Planning_Roadmap/20_ORDER_EDIT_V2_UI_SPEC.md` while preserving the frozen canonical Order/Finance/KOL contract and existing fulfillment behavior.

## Parallel/dependency declaration

BASELINE: `7bbb7f934f973f7079cecded67762888878601e9` from Product Master committed branch state.
DEPENDENCIES: Product Master committed functionality / PR #93 lineage only; Product Edit V2 uncommitted WIP is explicitly excluded.
DEPENDENCY_TYPE: STACKED_PR.
DEPENDENCY_STATUS: OWNER AUTHORIZED by continuation after the baseline/worktree was explicitly declared in chat.
PARALLEL_SAFE: YES.
FOUNDATION_CHANGE_REQUIRED: NO.
## Scope

Expected implementation scope:
- `resources/js/Pages/Orders/Create.vue`
- `resources/js/Pages/Orders/Show.vue`
- Order-specific Vue tests and/or Order-specific stylesheet when needed
- `app/Modules/Orders/Experience/Controllers/OrderController.php` only for the smallest existing-item projection required by editable Product relations
- `tests/Feature/OrderFulfillmentM1Test.php` only for bounded regression/contract coverage
- `tests/Architecture/ModuleArchitectureTest.php` only for legitimate frozen frontend file/hash evidence

Explicitly prohibited:
- `resources/css/shell.css`
- schema/migrations
- routes/menu/permissions
- Order truth, source/kind/status semantics
- Shipment/Manual Sync/Finance behavior changes
- Product Edit V2 worktree mutation
- merge, remote deploy, production access

## Acceptance criteria

Create and Edit must match the V2 spec, reuse one customer/KOL implementation, preserve canonical Product selection, display field-level validation, disable duplicate saves, keep immutable identity read-only, and retain the existing fulfillment projection/actions.
## Validation contract

US Claude must run before handing back:
- focused Vitest for Order Create/Edit;
- `php artisan test tests/Feature/OrderFulfillmentM1Test.php tests/Feature/Products/ProductOrderIntegrationTest.php --compact`;
- Architecture focused test with no FAIL;
- `npm run typecheck`;
- `npm run build`;
- `git diff --check`;
- bounded browser smoke for customer Create/Edit and KOL Create/Edit using local `http://erp.b` when runtime is pointed at this worktree.

China Codex is not started in this round. This package is being developed ahead of a combined Owner UAT and remains local/unmerged until that gate.

## Stop conditions

Stop and return evidence instead of widening scope if implementation requires schema, route, permission, menu, shared `shell.css`, Product V2 WIP, or a new business rule.

## Owner/UAT gate

`ORDER_EDIT_V2 = UAT_READY_FROZEN` may be declared only after US focused verification and local browser smoke pass. Final UAT remains Owner-only and may be performed together with Product Edit V2.
PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Gate → MVP1 Existing Operations → ERP-INQUIRY-M1 → Inquiry Local UAT → milestone-gated remote release → WooCommerce Inbound → Tracking Outbound → Sync Orchestration → Product/Media → WordPress Product/Image Sync → Content/SEO → Posts/Pages → GA4/GSC/Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS → Inventory/Procurement → Accounting/ROI → additional integrations → Production Hardening
CURRENT_TRACK_PHASE_MAP: Order Fulfillment M1 committed baseline → Product Master committed baseline → Product Edit V2 UAT_READY_FROZEN → Order Edit V2 CURRENT → combined Owner UAT → independent PR/merge gates
COMPLETED_PHASES: Order Fulfillment M1 implementation; Product Master committed implementation; Product Edit V2 US focused verification
CURRENT_PHASE: Order Edit V2 implementation
CURRENT_WORK_PACKAGE: ERP-ORDER-EDIT-V2-001
PACKAGE_STATUS: OWNER AUTHORIZED / READY FOR CLAUDE IMPLEMENTATION
WAITING_PHASES: Order Edit V2 verification; combined Owner UAT; Product/Order final PR gates
BASELINE: 7bbb7f934f973f7079cecded67762888878601e9
GITHUB_STATE: stacked local branch work/order-edit-v2-001; Product Master PR #93 lineage; no Order Edit PR yet
SOURCE_CONFLICTS: AI/CURRENT_CONTEXT.md predates Product Master/Product Edit V2 progress and remains stale for current package position; repository/worktree evidence governs this task
GLOBAL_GATE: PASS / existing architecture foundation
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE; remote deployment explicitly prohibited
NEXT_DEPENDENCY: US Claude bounded implementation on dedicated worktree
EVIDENCE_AS_OF: 2026-08-25
ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: planning value 2.5 effective hours / range 1.5–4.0 hours
KNOWN_CURRENT_TRACK_REMAINING: Product Edit V2 Owner UAT + Order Edit V2 implementation/verification/UAT; exact total UNKNOWN WITH EVIDENCE because Owner UAT/remediation is not time-bounded
ARCHITECTURE_GATE_REMAINING: 0 for existing global architecture gate; package-local Architecture regression still required
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE; this package does not revise the canonical total estimate ledger
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE; not recalculated in this package
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE; not recalculated in this package
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; accepted actual-hour overlap ledger not recomputed here
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE; outside this package
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE; outside this package
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; outside this package
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; no accepted Work Log for this new package yet
ESTIMATE_CONFIDENCE: MEDIUM
ESTIMATE_AS_OF: 2026-08-25
ESTIMATE_METHOD: bounded UI/projection change using existing Order backend and shared Vue surface
ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.3h planning / 0.2–0.5h
CORE_EXECUTION: 1.2h planning / 0.7–2.0h
TEST_AND_VALIDATION: 0.7h planning / 0.4–1.0h
REMEDIATION_BUFFER: 0.3h planning / 0.2–0.5h
ESTIMATED_EFFECTIVE_HOURS: 2.5h
ESTIMATE_RANGE: 1.5–4.0h
ESTIMATE_BASIS: existing shared Order backend/routes and bounded Create/Show V2 refactor; label retained only for repository schema compatibility, execution provider is Claude Code
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
