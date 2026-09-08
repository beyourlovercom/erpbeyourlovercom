# ERP-PRODUCTION-STABILITY-V1-P4-001

STATUS: VERIFIED / DELIVERY
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK
BASELINE: `origin/main@efd0924ae2a2c0b6c01d838f4b8c151214d90104`
BRANCH: `fix/erp-production-stability-v1-p4-001`
WORKTREE: `/Users/a/AI/007/worktrees/erp-production-stability-v1-p4-001`
DEPENDENCIES: PR #252, #257, #261, #265/#266, #269, #273 merged; P0-P3 Production UAT accepted.
DEPENDENCY_TYPE: MERGED_PR
DEPENDENCY_STATUS: SATISFIED
PARALLEL_SAFE: YES; package owns only stability regression gates/tests/workflow/docs.

## Scope

Close Production Stability V1 by converting the failures found in P0-P3 into automated regression gates without changing ERP product behavior.

1. Add a repository-level MySQL dialect compatibility guard for known PostgreSQL-only SQL constructs that would break Production MySQL.
2. Add an isolated MySQL 8 runtime gate that runs all migrations and executes the confirmed-loss query that caused the original Logistics 500.
3. Add an authenticated `/logistics/pending` HTTP query-budget regression test using 50 operational orders so row-based N+1 regressions fail deterministically.
4. Add a dedicated GitHub Actions MySQL 8 stability workflow covering the static guard, SQLite query-budget test, and MySQL runtime gate.
5. Record final closure evidence and preserve Production deployment as a separate Owner gate.

## Prohibitions

- No Production deployment or Production service restart in this package before explicit Owner release authorization.
- No Production data mutation, migration execution, credential change, or external-service mutation.
- No product/UI behavior change, schema change, permission change, or menu change.
- Do not weaken Production observability budgets to make tests pass.
- Do not claim GitHub CI PASS when Actions are not executed or are infrastructure-blocked.
## Acceptance Criteria

- Static MySQL compatibility test passes on current `app/` and `database/migrations/` sources and fails on a representative forbidden SQL fixture.
- MySQL 8 isolated gate proves `migrate:fresh` succeeds and `ConfirmedLossProjection::applyConfirmedLossScope()` executes against MySQL without syntax error.
- MySQL runtime gate asserts server major version 8 and does not touch the Production database.
- Authenticated `/logistics/pending` with 50 pending orders returns 200 and stays within the canonical Production query budget of 100 SQL statements.
- Existing P3 shell-navigation query budget remains green.
- Focused PHP suite, Pint, `git diff --check`, and workflow syntax validation pass locally on the merge-candidate revision.
- GitHub workflow state is reported exactly; billing/spending-limit infrastructure failures are not reported as code CI failures or PASS.

## Validation Plan

- `php artisan test tests/Architecture/MySqlProductionCompatibilityTest.php`
- `php artisan test tests/Feature/ProductionStability/LogisticsPendingQueryBudgetTest.php tests/Feature/AppShellNavigationTest.php`
- `tests/Feature/ProductionStability/run-mysql8-production-compatibility.sh`
- `vendor/bin/pint --test`
- `git diff --check`
- parse `.github/workflows/erp-production-stability-v1.yml` and review Actions check results after push.

## Closure Gate

P4 may merge automatically under `BYL_ERP_CONTINUOUS_OWNER_AUTHORIZATION_V1` once required non-Production checks are satisfied. There is no UI Owner-UAT gate because this package is backend/test/CI-only. Any Production deployment remains a separate explicit Owner gate.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Existing Operations / Tencent Docs Replacement → ERP-INQUIRY-M1 B2B Inquiry Foundation → ERP-INQUIRY-M1 Local Milestone UAT → Milestone-gated Remote Release → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Production Hardening / Expansion
CURRENT_TRACK_PHASE_MAP: Production Stability V1 P0 [COMPLETE] → P1 [COMPLETE] → P2 [COMPLETE] → P3 [COMPLETE] → P4 Regression Prevention [CURRENT]
COMPLETED_PHASES: P0 MySQL Logistics 500 recovery; P1 Logistics Performance V2; P2 Production Observability Closure; P3 Global 5xx / shell performance sweep.
CURRENT_PHASE: P4 Regression Prevention / Stability Closure
CURRENT_WORK_PACKAGE: ERP-PRODUCTION-STABILITY-V1-P4-001
PACKAGE_STATUS: VERIFIED / DELIVERY
WAITING_PHASES: Production Stability V1 final closure after P4 merge; optional Production release only with explicit Owner authorization.
BASELINE: origin/main@efd0924ae2a2c0b6c01d838f4b8c151214d90104
GITHUB_STATE: main includes merged PR #273, #274 and #275 at final verification; delivery PR not yet opened.
SOURCE_CONFLICTS: AI/CURRENT_CONTEXT.md is stale relative to GitHub main; GitHub/runtime evidence governs this Production Stability package.
GLOBAL_GATE: P4 regression-prevention implementation and verification; Production release separately gated.
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE — package activation performed only on isolated US development worktree.
NEXT_DEPENDENCY: Implement and verify static MySQL, MySQL 8 runtime, and query-budget gates.
EVIDENCE_AS_OF: 2026-09-08T16:07+08:00

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 3.0 planning hours / 2.0–4.0 range
KNOWN_CURRENT_TRACK_REMAINING: 2.0–4.0 hours for P4 only; P0-P3 complete.
ARCHITECTURE_GATE_REMAINING: 0 hours for P0-P3; P4 regression closure remains.
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE — this stability package is not the canonical MVP1 estimate ledger.
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE — document 13 estimate ledger not being rewritten by this package.
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE — outside this package scope.
MVP1_REMAINING: UNKNOWN WITH EVIDENCE — no accepted overlap-safe actual ledger calculation in this package.
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE — outside this package scope.
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE — outside this package scope.
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE — broader roadmap continues after Production Stability V1.
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — no accepted Work Log ledger evaluated here.
ESTIMATE_CONFIDENCE: HIGH for bounded P4 package
ESTIMATE_AS_OF: 2026-09-08T16:07+08:00
ESTIMATE_METHOD: bounded test/CI implementation based on measured P0-P3 regressions and existing MySQL 8 harness.

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.5 planning hours / 0.25–0.5 range
CORE_EXECUTION: 1.25 planning hours / 0.75–1.5 range
TEST_AND_VALIDATION: 0.75 planning hours / 0.75–1.25 range
REMEDIATION_BUFFER: 0.5 planning hours / 0.25–0.75 range
ESTIMATED_EFFECTIVE_HOURS: 3.0
ESTIMATE_RANGE: 2.0–4.0
ESTIMATE_BASIS: Four bounded regression-prevention artifacts plus US-local SQLite/MySQL verification.
OWNER_REVIEW: EXCLUDED
MERGE: INCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED

## Final Local Verification Evidence

- Reconciled immediately before final verification to `origin/main@efd0924ae2a2c0b6c01d838f4b8c151214d90104` (PR #275 merged after package activation); no owned-path overlap.
- Static MySQL dialect gate: PASS — 7 tests / 7 assertions; current `app/` + migrations contain no known forbidden PostgreSQL-only constructs.
- Authenticated `/logistics/pending` full-page regression: PASS — 50 pending orders, HTTP 200/Inertia, 14 assertions, query count within canonical 100-query Production budget.
- P3 shell permission regression: PASS — bounded query-count test, 3 assertions.
- Isolated MySQL 8 runtime gate: PASS on MySQL 8.4.11 — full `migrate:fresh` plus `ConfirmedLossProjection` critical query, 10 assertions; temporary database removed after run.
- Pint on the three new PHP tests: PASS. Workflow YAML parse: PASS. `git diff --check`: PASS.
- Recurring test-environment `file_get_contents(...)` warnings remain warnings only; no test failures.
- No full-suite PASS claim and no frontend-build claim; neither is required by this backend/test/CI-only package.
- Production deployment remains NOT AUTHORIZED / NOT PERFORMED for P4.
