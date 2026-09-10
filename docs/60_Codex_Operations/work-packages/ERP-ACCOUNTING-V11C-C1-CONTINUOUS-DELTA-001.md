# ERP-ACCOUNTING-V11C-C1-CONTINUOUS-DELTA-001

## Status / Authority
- STATUS: IMPLEMENTATION COMPLETE / US VERIFICATION PASS / PR #286 MERGE CANDIDATE / PRODUCTION ACTIVATION NOT AUTHORIZED
- EXECUTION_PROVIDER: GPT_WEB
- VERIFICATION_TIER: HIGH_RISK
- OWNER_SCOPE: continue Accounting V1.1C and close the recurring Woo-created P2/P3/P5 delta gap without consuming Roadmap P6.
- UI_UAT: NOT REQUIRED — no Owner-visible UI is changed.

## Baseline / Parallel Preflight
- BASELINE: `origin/main@fb8ba2ef8a288f3e07e4500a67d1329688ee9486`
- BRANCH: `feature/erp-accounting-v11c-p6-continuous-delta-materialization-001`
- WORKTREE: `/Users/a/AI/007/worktrees/erp-accounting-v11c-p6-continuous-delta-materialization-001`
- DEPENDENCIES: merged Woo hourly order sync + merged Accounting V1.1B/V1.1C P1-P5, including PR #285.
- DEPENDENCY_TYPE: MERGED_PR
- DEPENDENCY_STATUS: SATISFIED on baseline.
- PARALLEL_SAFE: YES. Open PR file audit found no overlap with Finance, `routes/console.php`, or Accounting config.
- FOUNDATION_CHANGE_REQUIRED: NO.

## Problem Evidence
Production reconciliation after P5 closure showed that hourly Woo inbound keeps creating legitimate Accounting deltas. At the observed checkpoint, Product Cost had 12 apply candidates, Fixed Costs had 36 customs + 4 first-mile apply candidates, while Payment Fee had already been brought to zero. The existing P1-P5 commands are operator/backfill gates, not a continuous post-inbound loop.

Woo inbound already writes/updates `order_financials` inside `OrderService::upsertInbound()`. C1 MUST NOT refetch Woo or duplicate V1.1B financial bootstrap. It starts from canonical ERP orders after inbound persistence.

## Goal
Create one fail-closed, bounded, auditable, idempotent Accounting delta orchestrator so changed operational Woo customer orders automatically receive all currently-authorized V1.1C facts:

`Woo inbound Financials -> P2 Product Cost -> P3 Fixed Costs -> P5 Payment Fee -> Finance completeness/profit projection refresh`

This package is closure infrastructure only. It does NOT replace or rename the Owner roadmap:
- V1.1C-P6 remains Last-mile Cost.
- V1.1C-P7 remains Warehouse Cost.
- V1.1C-P8 remains KOL Commission.
- V1.1C-P9 remains Loss / Other Costs.

## Frozen Candidate Scope
Only `order_kind=customer`, excluding `historical_logistics` orders.
An order is a C1 delta candidate when at least one canonical change source changed inside the run window:
1. Order row `updated_at`;
2. Woo external reference `updated_at` for provider=`woocommerce`;
3. Order Financial row `updated_at`;
4. canonical Shipment row `updated_at`.

This deliberately catches new/modified Woo orders and later warehouse/shipment assignment so first-mile can become eligible after initial Woo ingestion.
Product-cost source-file changes remain controlled by the existing P1 import/materialization gate; C1 does not turn source imports into an implicit historical rewrite.

## Cursor / Recovery Contract
- Reuse existing SyncRun infrastructure; no new table/migration.
- Run type: `accounting_continuous_delta`.
- Provider key: `internal`; connection key: `finance`.
- Persist successful `checkpoint_at` in SyncRun context.
- Freeze `window_end=now UTC` at run start.
- Query from `checkpoint_at - 5 minutes` through `window_end` to provide idempotent overlap recovery.
- First-ever run uses a 180-minute initial lookback.
- Hard candidate cap default/max: 10000. Detect cap overflow before any materialization write; fail closed and do not advance checkpoint.
- Business-evidence blockers are expected outcomes and do not prevent checkpoint advance. Runtime exceptions/fatal processing errors do prevent checkpoint advance; already-applied rows remain safe because the next run overlaps and all materializers are idempotent.

## Orchestration Contract
For each candidate order, in this exact order:
1. `CostMaterializationService::applyProductCostGate()`;
2. `CostMaterializationService::applyFixedRules()`;
3. `CostMaterializationService::applyPaymentFeeGate()`;
4. `OrderAnalysisService::refreshCompleteness(..., preserveReconciled:false)` once at the end.

Do not duplicate business rules inside the orchestrator. Existing P2/P3/P5 provenance, FX snapshot, strong-fact protection, 3.5% fee rule, and audit semantics remain authoritative. The Product and Fixed gates may both encounter `first_mile_cost`; the second pass must be an idempotent noop, never a duplicate row.

C1 must never create or mutate `last_mile_cost`, `warehouse_cost`, `overseas_handling_cost`, `kol_commission`, `refund_replacement_loss`, or `other_variable_cost`.

## Command / Production Safety
Add `finance:materialize-continuous-delta`.
- Dry-run is default and performs zero business writes and zero checkpoint writes.
- `--apply` is allowed in local/testing.
- Production `--apply` is fail-closed unless `ERP_ACCOUNTING_CONTINUOUS_DELTA_ENABLED=true` for the runtime.
- `--limit` is bounded 1..10000.
- Summary must report window/candidate counts, per-stage applied/noop/blocked totals, blocker buckets, failures, checkpoint before/after, and whether checkpoint advanced.
- Apply mode records one SyncRun and bounded per-order SyncRunItems; exception failures are visible and auditable.

## Scheduler Contract
Add config under `config/erp_accounting.php` with default disabled:
`continuous_delta.enabled = env('ERP_ACCOUNTING_CONTINUOUS_DELTA_ENABLED', false)`.

When enabled only:
- schedule `finance:materialize-continuous-delta --apply --limit=10000 --no-interaction`;
- cadence `hourlyAt(10)` = `10 * * * *`, intentionally after Woo inbound at `0 * * * *`;
- name `finance.accounting.continuous-delta.hourly`;
- `withoutOverlapping(50)`;
- `onOneServer()`.

Merging/deploying code must NOT enable the flag. Production scheduler activation is a separate explicit Owner gate.

## Expected File Scope
- this Work Package file;
- Finance Application service and candidate-store contract;
- Finance Infrastructure candidate-store implementation;
- Finance console command;
- Finance provider binding/command registration;
- `config/erp_accounting.php`;
- `routes/console.php`;
- focused Finance/scheduler tests.
No migration, Vue, route/web UI, menu, permission, dependency-manifest, Woo transport, or Production environment file change.

## Acceptance / Verification
Required US merge-candidate verification:
- focused C1 tests: dry-run zero writes; initial lookback; persisted checkpoint; 5-minute overlap; hard-cap fail-before-write; expected business blockers nonfatal; runtime failure does not advance checkpoint; idempotent rerun; shipment change re-enters scope; strong existing facts remain protected; P6-P9 cost types untouched;
- Production guard test: apply blocked when flag false;
- scheduler disabled/enabled assertions: exactly one event, `10 * * * *`, overlap=50, one-server, exact command;
- existing `WooCommerceAutomaticOrderSyncTest` remains green and Woo cadence remains `0 * * * *`;
- full Finance regression green;
- PHP lint + `git diff --check` green;
- Architecture regression shows no new violation versus baseline;
- `php artisan schedule:list` / command registration prove expected runtime wiring.

## Implementation / Verification Evidence
- GPT Web + US Remote Desktop Commander implemented one bounded Finance orchestrator, candidate-store contract/implementation, console command, provider binding, config gate, scheduler declaration, and focused tests. No migration, UI/menu, permission, dependency manifest, Woo transport, or Production environment file changed.
- Candidate discovery is limited to customer orders excluding `historical_logistics`, using the frozen Order / Woo external-reference / Order Financial / Shipment `updated_at` window contract. First run is 180 minutes; later runs use persisted `checkpoint_at - 5 minutes`; `window_end` is frozen at run start.
- Hard-cap overflow is detected with `limit + 1` before SyncRun creation or cost materialization. Production apply remains disabled unless `ERP_ACCOUNTING_CONTINUOUS_DELTA_ENABLED=true`.
- Per-order apply order is exactly P2 Product Cost -> P3 Fixed Costs -> P5 Payment Fee -> final completeness refresh. P6-P9 cost types are untouched and strong existing facts remain protected by existing materializer disposition rules.
- A merge-candidate review found one self-trigger risk: unconditional final completeness saves could refresh `order_financials.updated_at` even when projection values were unchanged, causing a prior C1 order to re-enter every hourly window. `OrderAnalysisService::refreshCompleteness()` now saves only when `completeness_status` or `missing_facts` is dirty; the service is still called exactly once at the end. A focused regression proves an overlap recovery may re-run once but does not self-touch forever.
- Focused C1 PHPUnit: 11 tests / 60 assertions PASS.
- Full Finance + Finance unit + Woo automatic order sync regression: 79 tests / 630 assertions PASS.
- Architecture/Menu exact-baseline comparison: `origin/main@fb8ba2ef...` = 6 inherited failures / 13 tests / 1336 assertions; C1 branch = the same 6 failures; NEW_FAILURES = 0 / REMOVED_FAILURES = 0. C1 architecture regression delta = 0.
- Runtime wiring: command registered; default-disabled schedule contains Woo only at `0 * * * *`; enabling C1 adds exactly `finance:materialize-continuous-delta --apply --limit=10000 --no-interaction` at `10 * * * *`. Focused scheduler test also proves overlap=50 and one-server.
- PHP lint PASS for every touched PHP file; `git diff --check` PASS; forbidden-scope check found no migration/frontend/menu/env/dependency-manifest changes.
- Final baseline movement check: `origin/main` remained exactly `fb8ba2ef8a288f3e07e4500a67d1329688ee9486` throughout merge-candidate verification.
- GitHub Actions run `34463527309` reported `mysql8-runtime` and `sqlite-regression` failure before runner allocation: both jobs have `runner_id=0`, empty `runner_name`, and `steps=[]`, completing in about 1–2 seconds. No CI test command started. This is the same known infrastructure signature previously evidenced on Accounting PRs #283/#285; it is not a C1 test failure.
- Production was not accessed for C1 execution, no C1 environment flag was changed, no scheduler was activated, and no Accounting data write was performed by this package.

## Delivery / Hard Stops
Routine implementation, test, commit, push, Draft PR, checks, remediation and green merge are authorized by `BYL_ERP_CONTINUOUS_OWNER_AUTHORIZATION_V1`.
- PROVIDER_HANDOFF: US Codex CLI was attempted on the dedicated US worktree but exited before editing because its account usage limit was exhausted. `git status` was clean after the failed invocation. The package is serialized to GPT Web + US Remote Desktop Commander; no parallel implementation provider remains active.
STOP before any Production deploy, Production env/config mutation, scheduler activation, or Production Accounting write. No Production write is authorized by this Work Package.

## Work Log
- 0.35h — exact-main/parallel preflight, repository governance read, frozen C1 contract, Draft PR container, failed-before-edit Codex provider attempt and serialized handoff to GPT Web.
- 0.75h — candidate-store/orchestrator/command/config/scheduler implementation plus bounded no-op completeness-save remediation.
- 0.55h — focused tests, Finance/Woo regression, Vite test-harness recovery, exact-baseline Architecture/Menu comparison, command/scheduler/lint/diff/file-scope verification.
- 0.15h — final evidence, delivery preparation and merge-candidate review.
- ACTUAL_EFFECTIVE_HOURS: 1.80h. Waiting/idle time and Codex usage-limit unavailability are excluded; provider handoff/diagnosis effort is included in preflight.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate [COMPLETE] → MVP1 Existing Operations / Tencent Docs Replacement → ERP-INQUIRY-M1 B2B Inquiry Foundation → ERP-INQUIRY-M1 Local Milestone UAT → Milestone-gated Remote Release → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Production Hardening / Expansion
CURRENT_TRACK_PHASE_MAP: V1.1B Revenue Evidence [COMPLETE] → V1.1C P1 Product Cost Source [COMPLETE] → P2 Product Cost Materialization [BASELINE COMPLETE / LIVE DELTA EXISTS] → P3 Fixed Cost Rules [BASELINE COMPLETE / LIVE DELTA EXISTS] → P4 Transaction FX Snapshot [COMPLETE] → P5 Payment Fee [CLOSED / PRODUCTION ACCEPTED] → C1 Continuous Delta Materialization [CURRENT] → V1.1C Closure Gate → P6 Last-mile → P7 Warehouse Cost → P8 KOL Commission → P9 Loss/Other → V1.1 Final Profit Readiness
COMPLETED_PHASES: V1.1B; V1.1C P1; P4; P5; historical/baseline P2-P3 Production materialization
CURRENT_PHASE: Accounting V1.1C continuous delta closure infrastructure
CURRENT_WORK_PACKAGE: ERP-ACCOUNTING-V11C-C1-CONTINUOUS-DELTA-001
PACKAGE_STATUS: IMPLEMENTATION COMPLETE / US VERIFICATION PASS / PR #286 MERGE CANDIDATE / CI INFRASTRUCTURE EXCEPTION EVIDENCED / PRODUCTION ACTIVATION NOT AUTHORIZED
WAITING_PHASES: GitHub CI/review/merge; explicit Production deploy; pre-activation Production reconciliation; scheduler activation; Production post-activation reconciliation; V1.1C closure
BASELINE: origin/main@fb8ba2ef8a288f3e07e4500a67d1329688ee9486
GITHUB_STATE: PR #286 on the dedicated C1 branch; code head 9d1cb0165ce8b7b0b6dca459c60faea8b2ca7f7a passed US verification; Actions run 34463527309 failed only with runner_id=0 / steps=[] infrastructure signature; origin/main remains fb8ba2ef8a288f3e07e4500a67d1329688ee9486
SOURCE_CONFLICTS: AI/CURRENT_CONTEXT.md is historically stale for current product-track position; latest GitHub main + Owner-confirmed Accounting roadmap + current Production reconciliation govern this package. No open PR file overlap found for expected Finance/scheduler paths.
GLOBAL_GATE: C1 architecture regression delta 0; focused/full regression PASS; GitHub CI infrastructure exception evidenced (no runner/test steps); Production deploy/activation remains separate Owner gate
PRODUCTION_DEPLOYMENT_STATUS: NO — C1 code not deployed or activated
NEXT_DEPENDENCY: mark PR #286 Ready and complete continuously authorized merge under the evidenced CI infrastructure exception if GitHub remains mergeable; then stop for explicit Production deploy/activation Owner gate
EVIDENCE_AS_OF: 2026-09-10

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 2.5h planning value; 1.5–4.0h range
KNOWN_CURRENT_TRACK_REMAINING: C1 implementation/verification/Git delivery plus separately gated Production deploy/activation/reconciliation; later P6-P9 remain distinct roadmap packages
ARCHITECTURE_GATE_REMAINING: historical global architecture debt remains separate; C1 requires no-new-violation only
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE — current Accounting package does not recompute historical MVP1 total
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE — not recalculated by this package
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE — not recalculated by this package
MVP1_REMAINING: UNKNOWN WITH EVIDENCE — no accepted cross-program Work Log subtraction loaded
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE — not recomputed by this package
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE — not recomputed by this package
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE — later roadmap contains unfrozen packages
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — no accepted cross-program Work Log ledger loaded
ESTIMATE_CONFIDENCE: HIGH
ESTIMATE_AS_OF: 2026-09-10
ESTIMATE_METHOD: bounded backend orchestrator + existing SyncRun/cost gates + scheduler/config wiring + high-risk regression

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.4h
CORE_EXECUTION: 1.2h
TEST_AND_VALIDATION: 0.6h
REMEDIATION_BUFFER: 0.3h
ESTIMATED_EFFECTIVE_HOURS: 2.5h
ESTIMATE_RANGE: 1.5–4.0h
ESTIMATE_BASIS: frozen no-migration Finance orchestration reusing existing P2/P3/P5 services, existing SyncRun infrastructure, and one scheduler/config gate
OWNER_REVIEW: EXCLUDED
MERGE: INCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
ACTUAL_EFFECTIVE_HOURS: 1.80h
ACTUAL_HOURS_BREAKDOWN: preflight/scope/provider-handoff 0.35h; core implementation/remediation 0.75h; test/validation 0.55h; final evidence/delivery prep 0.15h
VARIANCE: -0.70h / -28.0% versus 2.50h planning value
VARIANCE_REASON: Existing P2/P3/P5 materializers, SyncRun infrastructure and Woo scheduler contracts were reusable without migration/UI/dependency work; one self-touch idempotency risk was found and remediated within the planned buffer.
