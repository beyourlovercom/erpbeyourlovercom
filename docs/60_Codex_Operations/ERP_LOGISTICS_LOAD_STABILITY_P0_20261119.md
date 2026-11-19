# ERP 物流页面加载稳定性 P0 — Work Package

WORK_PACKAGE_ID: ERP-LOGISTICS-LOAD-STABILITY-P0-20261119
ISSUE: https://github.com/beyourlovercom/erpbeyourlovercom/issues/809
EXECUTION_PROVIDER: GPT_WEB + US_MAC_SELF_HOSTED_DC
VERIFICATION_TIER: HIGH_RISK
BRANCH: fix/erp-logistics-load-stability-p0-20261119
WORKTREE: /Users/a/AI/007/_worktrees/erp-logistics-load-stability-p0-20261119
BASELINE: origin/main 2cbb405c (2026-11-19), verified before branch creation
DEPENDENCIES: NONE; independent from current ERP2, KOL, product-cost branches
DEPENDENCY_TYPE: NONE
DEPENDENCY_STATUS: PASS
PARALLEL_SAFE: YES, exclusive owned worktree and targeted files
OWNER_AUTHORITY: 2026-11-19 explicit P0 Dev fix → Owner UAT → controlled Production release request

## Scope and evidence

- Stale Vue chunks: /assets/LogisticsL2Grid-D8EfoiCJ.js 404/504 in runtime logs, absent from current release.
- Current /build/assets/LogisticsL2Grid-d_Wja6uj.js returns 200; Nginx fallback sends missing static files through PHP.
- A read-only overseas GET failed with 500 after 35.372s, request_id=4bbbc46a4a8e71680171d736cd73538e.
- Existing Overseas Inertia controller calculates seven counts even when partial reload requests only rows.
- Fix stale bundle recovery once per 60s, preserving unsaved form drafts; missing static assets return direct 404.
- Lazy-evaluate Overseas counts only when actually requested by Inertia.
- No navigation/menu redesign, no permission/status changes, no order/warehouse/OMS mutations.
- Production service config is a template change only until independently authorized release.

## Validation and stop conditions

1. Frontend unit tests: only one recovery refresh; dirty form draft preserved; no infinite reload.
2. Nginx template test: /assets and /build/assets miss returns static 404 without entering PHP-FPM.
3. PHP Inertia tests: full overseas counts preserved; rows-only requests skip seven counts.
4. Existing Overseas Workbench suite, login and route tests, TS typecheck, Vite build, 30-tab bundle guard PASS.
5. Dev-only authenticated UAT: pending allocation, China, overseas warehouse navigation, hard refresh, tabs.
6. Record any unexplained full-page 500 separately; do not claim real DB timeout resolved by partial-reload optimization.
7. Owner must explicitly PASS prepared live Dev UAT before closure. Production has a separate formal gate.
8. No Production writes, restart, real OMS call, real order mutation, or database migration during Dev.

## Actual Dev and regression evidence (2026-11-19)

- `npm run typecheck`, `npm run typecheck:e2e`, `npm run build` and 30 Workspace tabs guard: PASS.
- Overseas workbench suite: 77 tests / 1,828 assertions PASS; overseas Inertia partial count test: 1 test / 19 assertions PASS.
- Domestic partial test corrected outdated view-count expectation (actual 9): 2 tests / 23 assertions PASS.
- Frontend focused tests: 19 PASS; runtime Nginx template test: PASS; PHP Pint and `git diff --check`: PASS.
- Isolated Mac mini Dev: temporary SQLite, seeded synthetic records, Chrome browser test; logistics root, wait-stock, China, MorePlus, Germany, New York and Canada routes all returned HTTP 200 with rendered content and successful reload. No Production DB or external integrations used.
- Owner's live authenticated Dev window prepared; separate human UAT gate remains PENDING.
- Caution: Production includes intermittent DB/Redis latency and PHP-FPM 30-second timeouts. The isolated synthetic-data smoke test does NOT prove that these production-size timeout causes are eliminated. A real-user latency/error-rate canary is mandatory before widening traffic or closing the P0.
- Legacy `/assets` 404 totals include bot/scanner probes and our diagnostic requests: they are NOT a count of affected employees.

## PROJECT POSITION
PROGRAM_ROADMAP: Architecture COMPLETE → MVP1 ops COMPLETE → KOL/Orders/Product/Logistics/Finance phased releases → current Production stability → future automation/expansion; see roadmap document 13 and its V3 overlay.
CURRENT_TRACK_PHASE_MAP: R0 diagnostics COMPLETE → R1 code fix COMPLETE → R2 local/isolated Dev tests PASS → R3 Owner UAT CURRENT → R4 controlled release WAIT.
COMPLETED_PHASES: R0, R1, R2
CURRENT_PHASE: R3
CURRENT_WORK_PACKAGE: ERP-LOGISTICS-LOAD-STABILITY-P0-20261119
PACKAGE_STATUS: DEV_VERIFIED / OWNER_UAT_PENDING / PRODUCTION_UNCHANGED
WAITING_PHASES: R3, R4
BASELINE: origin/main 2cbb405c on 2026-11-19
GITHUB_STATE: Issue #809; one dedicated branch; PR pending
SOURCE_CONFLICTS: AI/CURRENT_CONTEXT.md is stale 2026-08-29 versus actual main and Production release facts on 2026-11-19; GitHub/runtime wins.
GLOBAL_GATE: OWNER_DEV_UAT_PASS_PENDING + PRODUCTION_FORMAL_RELEASE_GATE
PRODUCTION_DEPLOYMENT_STATUS: NO; runtime production symlink unchanged by this package
NEXT_DEPENDENCY: Owner reviews live isolated Dev, then CI/PR and separate Production release gate
EVIDENCE_AS_OF: 2026-11-19

## ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 9.5h planning; 7–12h range
KNOWN_CURRENT_TRACK_REMAINING: UNKNOWN WITH EVIDENCE; other active ERP packages overlap on shared release surface
ARCHITECTURE_GATE_REMAINING: 0h / Global Architecture Gate PASS per document 13
MVP1_TOTAL_ESTIMATE: 122–190h historical total, not remaining
MVP1_PLANNING_BASELINE: 166h historical planning total
MVP1_MAXIMUM_ENVELOPE: 192h historical maximum
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; incomplete accepted actual ledger
POST_MVP_KNOWN_INCREMENT: 68–120h historical increment, not this fix
LONG_TERM_CUMULATIVE_RANGE: 235–402h architecture; 603–1018h automated MVP; 1059–1802h growth; 1595–2714h core; 1819–3146h expansion; alternative horizons only
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; overlapping horizons and partial actuals
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; accepted project ledger incomplete
ESTIMATE_CONFIDENCE: MEDIUM for bounded P0 fix, LOW for backend latency unknown
ESTIMATE_AS_OF: 2026-11-19
ESTIMATE_METHOD: additive non-overlapping work-item planning, excluding waiting and release approval

## ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 1–2h, planning 1.5h
CORE_EXECUTION: 3–5h, planning 4h
TEST_AND_VALIDATION: 2–3h, planning 2.5h
REMEDIATION_BUFFER: 1–2h, planning 1.5h
ESTIMATED_EFFECTIVE_HOURS: 9.5h
ESTIMATE_RANGE: 7–12h
ESTIMATE_BASIS: one bounded GPT+DC code package plus local/Dev tests; no Production mutation
OWNER_REVIEW: INCLUDED
MERGE: INCLUDED after mandatory UAT and CI gates
MIGRATION: EXCLUDED
UAT: INCLUDED
DEPLOYMENT: EXCLUDED until independent Production formal gate
