# DE Tianyun R5–R6 order push reuse — Work Package 2026-11-21

OWNER_AUTHORIZATION: Prioritize DE Tianyun single and selected-batch order push; replicate the already-developed MorePlus one-click sequential dispatch experience shown in Owner screenshot.
EXECUTION_PROVIDER: GPT Web + BYL official DC on US Mac mini; isolated Dev only.
BASE_BRANCH: feat/de-tianyun-xlwms-r4-sku-sync-20261120@9c0575db (Draft PR #824), stacked on R1–R3 Draft PR #820.
BRANCH: feat/de-tianyun-xlwms-r5-r6-order-push-20261121.
SCOPE: R5 single selection, R6 selected batch, one queue/workflow and per-order result list, reusing MorePlus interaction patterns and Laravel background job. Keep separate DE/Hannover provider/order mapping.
VISUAL_REFERENCE: Owner screenshot 2026-11-21, German warehouse pending tab with checkbox selections and top “推送所选订单” entry; reuse existing MorePlus table, preview, confirmation and batch progress patterns.
R5_ESTIMATE: 12h. R6_ESTIMATE: 7h. TOTAL: 19h original estimate; review after tests.
R5_GOALS: Exact DE warehouse review and pending order filtering; verified ERP item->OMS SKU, DE warehouse code Hannover, German available channel mapping, provider-order duplicate/uncertainty readback; single order from shared batch path.
R6_GOALS: Select 1–200 pending rows across pages; fingerprint-bound preview, exact selection, durable idempotent queue ledger, operator permissions, one-by-one processing, detailed per-order status and retry blockade.
CREATE_BOUNDARY: No REAL outboundOrder/create until later explicit Owner acceptance. During this Dev phase all batch jobs must be dry_run and HTTP::assertNothingSent; PROD cannot start.
US_SM_BOUNDARY: Frozen US-SM XLS/OMS manual route unchanged. US-LA MorePlus provider path unchanged.
R7_DEPENDENCY: Owner Dev UAT on real page; separate real CREATE and Production authorization. Do not mark simulation as real OMS acceptance.
TEST_PLAN: Backend HTTP route/authorization/DB readback; frontend button/selection/confirmation/poll; exact warehouse, duplicate and no-network contracts.
CURRENT_STATUS: CODE_VERIFIED_IN_ISOLATED_DEV / DRAFT_DELIVERY_PENDING; real OMS CREATE disabled, Owner real Dev UAT not yet performed.
DEPENDENCIES: R0–R3 Draft PR #820; R4 Draft PR #824; branch explicitly stacked on R4.
DEPENDENCY_TYPE: STACKED_PR.
DEPENDENCY_STATUS: R4 unmerged; merge must respect #820 → #824 → R5–R6, after each applicable Owner UAT gate.
PARALLEL_SAFE: YES, serialized GPT + DC in dedicated R5–R6 worktree, no overlapping implementation agent.
PRE_UAT_EVIDENCE: PHPUnit 170 tests/1122 assertions, Vue 85 tests, TypeScript check PASS, npm build + ERP_WORKSPACE_TABS_BUILD_PASS on US Mac; three registered DE routes; Http::assertNothingSent; no real provider POST attempted. Isolated macOS local UAT service http://127.0.0.1:8459 with separate temporary SQLite and synthetic three-order fixture; local Chrome login page opened but remote browser control was not available. Owner real-page inspection PASS remains mandatory.
UI_DIFF_AUDIT: Existing MorePlus one-click path retained; DE adds only upper-right selected-batch control, pending row single-order action, shared preview/results. Global Grid, existing XLSX actions and US-SM manual route unchanged.
COMPACT_WORKSPACE_SCOPE: Header action reused; this package does not claim full Overseas Workspace compact-layout migration.

## PROJECT POSITION
PROGRAM_ROADMAP: DE Tianyun R0–R7; R4 separate inventory backend pending UI/UAT, R5 single order, R6 selected batch, R7 Dev UAT.
CURRENT_TRACK_PHASE_MAP: R0–R3 read-only PASS, R4 backend Draft PR #824, R5-R6 approved incremental package, R7 pending.
COMPLETED_PHASES: R0–R3 read-only; R4 Dev guard/test code.
CURRENT_PHASE: R5–R6.
CURRENT_WORK_PACKAGE: DE_TIANYUN_XLWMS_R5_R6_ORDER_PUSH_WP_20261121.
PACKAGE_STATUS: IMPLEMENTED / AUTOMATED_VERIFICATION_PASS / OWNER_REAL_PAGE_UAT_PENDING.
WAITING_PHASES: R7; real create and Production release separately gated.
BASELINE: feat/de-tianyun-xlwms-r4-sku-sync-20261120@9c0575db.
GITHUB_STATE: Draft PR #826 OPEN, base R4 Draft PR #824, head feat/de-tianyun-xlwms-r5-r6-order-push-20261121; initial required GitHub checks not yet reported, Owner UAT pending.
SOURCE_CONFLICTS: No tracked edits in new worktree prior to WP.
GLOBAL_GATE: Owner-provided target screenshot approved layout; Owner real Local/UAT PASS still outstanding. Real OMS CREATE and Production release remain separately gated.
PRODUCTION_DEPLOYMENT_STATUS: NO — code only in isolated unmerged Dev branch, no real CREATE and no Production release.
NEXT_DEPENDENCY: Owner live isolated Dev page inspection/UAT PASS; verify real OMS channel-to-warehouse mapping and inventory availability before any separately authorized live CREATE phase.
EVIDENCE_AS_OF: 2026-11-21.

## ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: R5 12h + R6 7h = 19h.
KNOWN_CURRENT_TRACK_REMAINING: R4 UI/UAT outstanding, R5-R6 19h, R7 6h.
ARCHITECTURE_GATE_REMAINING: Adapt existing durable MorePlus workflow; no shared US-LA business rules.
MVP1_TOTAL_ESTIMATE: R4–R7 original 41h planning envelope.
MVP1_PLANNING_BASELINE: 41h extension.
MVP1_MAXIMUM_ENVELOPE: Re-estimate if DE OMS logisticsChannel contract cannot be verified.
MVP1_REMAINING: R5-R6 19h at package start.
POST_MVP_KNOWN_INCREMENT: Live OMS create UAT requires separate approval.
LONG_TERM_CUMULATIVE_RANGE: Unchanged pending Dev evidence.
PROGRAM_REMAINING: R5-R6/R7 and R4 UI stages, no actual hours audited.
COMPLETED_ACTUAL_EFFECTIVE_HOURS: Not independently audited.
ESTIMATE_CONFIDENCE: MEDIUM.
ESTIMATE_AS_OF: 2026-11-21.
ESTIMATE_METHOD: MorePlus frontend/queue workflow reuse, separate DE provider adapter.

## ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 2h.
CORE_EXECUTION: 11h.
TEST_AND_VALIDATION: 4h.
REMEDIATION_BUFFER: 2h.
ESTIMATED_EFFECTIVE_HOURS: 19h.
ESTIMATE_RANGE: 16–24h.
ESTIMATE_BASIS: Single 12h + selected batch 7h.
OWNER_REVIEW: INCLUDED — real isolated Dev UAT PASS pending.
MERGE: INCLUDED — gated by Owner real Dev UAT PASS and required checks.
MIGRATION: INCLUDED — new dedicated ledger migration authored, run only in disposable local UAT SQLite.
MIGRATION_FILE_AUTHORING: INCLUDED.
MIGRATION_EXECUTION: EXCLUDED — no existing canonical/Production database migration.
UAT: INCLUDED — local synthetic service prepared; Owner real browser inspection still pending; no real OMS outbound POST.
DEPLOYMENT: EXCLUDED — Production release requires separate Owner authorization.
