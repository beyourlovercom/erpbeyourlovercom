# ERP2 R1/R2 isolated Laravel read-only adapters — Work Package 2026-11-19

STATUS: OWNER APPROVED R0-R2 / BACKEND ONLY / OWNER UI DESIGN PASS PENDING.
EXECUTION_PROVIDER: CODEX_CLI_US
EXECUTION_MODEL: gpt-6-luna (Owner-mandated; no Claude Code)
PROVIDER_ROUTING_REASON: Owner explicitly corrected the provider to US Mac mini Codex CLI Luna on 2026-11-19; this package supersedes the original Claude selection.
VERIFICATION_TIER: HIGH_RISK
WORK_PACKAGE_ID: ERP2-R1R2-LARAVEL-READONLY-20261119
BRANCH: feat/erp2-logistics-readonly-r1r2-20261119
WORKTREE: /Users/a/ai/007/worktrees/erp2-logistics-readonly-r1r2-20261119
BASELINE: GitHub main@5026ddc060c43ab44c8e09c1b229e3c744036b3e
DEPENDENCIES: Laravel source main only; ERP2 UI Draft PR #766 is a later, unmerged frontend dependency
DEPENDENCY_TYPE: NONE for this backend-only package
PARALLEL_SAFE: YES if ownership limited to new read-only route/service/tests/config and necessary bounded source extraction
FOUNDATION_CHANGE_REQUIRED: NO for this backend-only delivery
OWNER_AUTHORITY: Owner explicitly approved ERP2 R0-R2 implementation on 2026-11-19
COMPLEXITY_REASON: Preserve existing OrderProgressQuery, allocation, domestic and overseas physical-shipment predicates across three distinct canonical Laravel query roots; avoid silent permission/state drift.

## Scope (backend only, no visual changes)

1. Add three authenticated, LOCAL/TESTING-only, GET/HEAD-only, JSON read projections, with stable versioned route names for pending allocation, domestic and overseas logistics. Feature flag disabled by default in any environment; never enable in Production. Distinct routes must match original Laravel business queues.
2. Reuse the existing canonical query/builder/row-projection owners. If exact reuse requires bounded extraction from original controllers into shared read services, keep original Laravel Inertia pages behavior/permissions/queries unchanged. Do not copy three pages' SQL into unrelated ERP2-only business definitions.
3. Keep source-of-truth: allocation & domestic Order-root, overseas Shipment-root; same source-filter/count/pagination; role-specific can:logistics.view and original domestic outsourced exception; no client supplied role switching.
4. Preserve domestic pending WITHOUT mandatory domestic_reviewed gate; overseas warehouse-review revisions, OMS issued/draft/shipped differences, aliases (us-sm, us-sj, usa-sj to us-3sm), archived/cancelled/historical exclusion, split A/B/C.
5. Serialize display order number, original SKU, resolved SKU, persisted final_sku, warehouse code, tracking, carrier, status, page meta; final_sku absent must remain null/unknown. No synthetic final SKU or new data truth tables.
6. Apply request allowlist for view, warehouse, page, per_page, search, sort; no arbitrary SQL keys, no N+1; Content-Type application/json and Cache-Control private, no-store; hard error for unexpected permission/schema/source.

## Hard prohibitions

- No Production orders or Production DB read/write/import/migration. No Woo, OMS, third-party warehouse calls or real shipping.
- No mutation routes, Laravel POST/PUT/PATCH/DELETE business actions, new tables, seed production rows, or database schema edits in this package.
- No ERP2 Vue page/component/router modification before separate rendered Owner design PASS. Do not modify #766 or stack a branch onto #766 without explicit Owner stacked authorization.
- No changes to workspace tabs, menus, user preferences, existing Kaven flow, or legacy ERP page behavior.

## Acceptance tests and verification

- Exact HEAD PHPUnit feature HTTP tests: owner/staff allow; KOL/logistics-forbidden 403; unauthenticated login or 401/302; non-local/flag-off denied; verbs rejected; invalid warehouse/view/page/sort rejected; no data leakage.
- Synthetic fixtures distinguish: unallocated root; factory unreviewed pending but shippable; factory reviewed; warehouse review stale after re-route; ready overseas; OMS draft; cancelled/archived/historical; warehouse alias. Assert each list and count from same query.
- Tracking and final_sku null semantics, A/B/C rows, 20/50/100 pagination, strict source host/identity guards where applicable. Query-time no write/OMS network; validate no outbound side-effect.
- Compare unchanged existing Logistics HTTP tests (PendingAllocationEntryPolicyTest, DomesticBusinessReviewTest, OverseaWarehouseWorkbenchTest) and run PHP style/tests on exact branch HEAD.
- Separate verification of actual isolated Laravel Dev DB hostname/name and migrate:status is R0 gate; do not run migrate:fresh until the Owner-approved isolated DB boundary has been verified.
- After backend delivery merge readiness and separate ERP2 frontend Owner visual PASS/dependency resolution, wire 3 new ERP2 screens, execute 007windows Dev UAT using isolated synthetic rows and actual staff sessions. Empty queues alone are not PASS.

## Delivery and handoff

- One branch, one worktree, one Draft PR. Commit implementation + focused tests and generated evidence; show exact SHA and skipped/failed checks.
- Existing Draft PR #800 contains static R0 source contract, including 2026-11-19 delta commit 1adc5e768e846a32c4bcec2496109afab9810905.
- Existing ERP2 frontend PR #766 remains unmerged; this backend package cannot claim browser parity or UI UAT PASS.
- Formal Production deployment and real-data Migration require separate Owner approval.

PROJECT POSITION
PROGRAM_ROADMAP: Existing ERP canonical operations -> ERP2 Code First architecture -> isolated read-only parity -> owner UAT -> isolated data-migration rehearsal -> later explicit real data migration and cutover
CURRENT_TRACK_PHASE_MAP: R0 baseline delta documented; R0 runtime schema status pending; R1/R2 backend adapter active; R1/R2 frontend pending Owner visual/dependency; R3-R5 pending; R6-R7 forbidden until separate gate
COMPLETED_PHASES: R0 GitHub static source audit at old SHA and latest main delta documented, NOT runtime PASS
CURRENT_PHASE: R1/R2 backend-only read contract
CURRENT_WORK_PACKAGE: ERP2-R1R2-LARAVEL-READONLY-20261119
PACKAGE_STATUS: BACKEND TESTS PASS / R0 RUNTIME + UI GATES PENDING
WAITING_PHASES: ERP2 UI design visual PASS, frontend integration, 007windows UAT, isolated migration, Production migration
BASELINE: main@5026ddc060c43ab44c8e09c1b229e3c744036b3e
GITHUB_STATE: PR #800 Draft; PR #766 Draft; this branch starts from latest known main
SOURCE_CONFLICTS: main advanced 50 commits since old R0 contract; Dev runtime HEAD/schema not yet independently attested
GLOBAL_GATE: Owner visual PASS before new UI; owner UAT PASS before UI closure; exact SHA CI before merge; separate Production release
PRODUCTION_DEPLOYMENT_STATUS: NO, no Production writes in scope
NEXT_DEPENDENCY: Backend implement and synthetic HTTP matrix, then Owner visual preview for new ERP2 screens
EVIDENCE_AS_OF: 2026-11-19, GitHub main@5026ddc
ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: planning 22h / range 16-28h
KNOWN_CURRENT_TRACK_REMAINING: R0 runtime gate 4-8h; R1/R2 backend 16-28h; frontend/UAT deferred until Owner visual
ARCHITECTURE_GATE_REMAINING: R0 runtime 4-8h
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE; separate ERP2 MVP estimates not fully frozen
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; no accepted Work Log
ESTIMATE_CONFIDENCE: LOW-MEDIUM; cross-controller source extraction risk
ESTIMATE_AS_OF: 2026-11-19
ESTIMATE_METHOD: non-overlapping preflight/core/tests/buffer ranges, excludes R0 runtime and frontend/Owner UAT
ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 3h planning / range 2-4h
CORE_EXECUTION: 12h planning / range 9-15h
TEST_AND_VALIDATION: 5h planning / range 4-6h
REMEDIATION_BUFFER: 2h planning / range 1-3h
ESTIMATED_EFFECTIVE_HOURS: 22h
ESTIMATE_RANGE: 16-28h
ESTIMATE_BASIS: 3 independent canonical query projections with shared extraction, permissions, SKU fields, synthetic HTTP tests
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED until independent mandatory gates pass
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED frontend Owner UAT pending design gate
DEPLOYMENT: EXCLUDED
