# DE Tianyun XLWMS R4 SKU/stock sync — Work Package 2026-11-20

OWNER_AUTHORIZATION: Andy confirmed existing ERP SKU matching + stock sync, single and selected batch outbound R5–R6, isolated Dev only, no real provider order CREATE and no Production release without separate UAT.
EXECUTION_PROVIDER: GPT_WEB through official BYL DC on US Mac mini.
VERIFICATION_TIER: HIGH_RISK; warehouse inventory and external credentials.
DEPENDENCY: PR #820 R1–R3 (feat/de-tianyun-xlwms-r1-r3-20261120), live signed read-only OMS PASS; source DE/Hannover.
BASE_BRANCH: origin/feat/de-tianyun-xlwms-r1-r3-20261120@34f8c922.
TARGET: R4 only: existing ERP Product master SKU mapping; provider inventory snapshot, isolated Dev checked apply and scheduled/manual operator read; US-SM behavior unaffected.
R4.1 3h: SKU Product OS identity mapping and missing coverage evidence, no automatic creation of ERP Products.
R4.2 4h: DE-specific guarded provider->ERP inventory mirror, available vs locked semantics, no missing-as-zero, atomic reconciliation.
R4.3 4h: operator preview/sync entry and 30-min scheduler, default OFF; Owner visual proposal gate before UI modifications.
R4.4 3h: audit, idempotent replay, unavailable-provider behavior, no duplicate product rows.
R4.5 2h: focused PHPUnit and isolated Dev with synthetic product identities; no Production migrations or writes.
NOT_AUTHORIZED: R5–R6 outbound create testing against real OMS, R7 Production deployment, wildcard warehouse credentials, direct Dev secret export.
ACCEPTANCE: Can fetch DE/Hannover, present matched/unmatched, no US-SM drift, show last checked/synced, no stock apply outside approved isolated Dev gate; all changes reversible in isolated SQLite.
GIT_RULES: Dedicated branch/worktree, separate stacked Draft PR; never merge before Owner UAT.
VALIDATION: Syntax/Pint for new code, targeted regression and safe Dev, no real OMS outbound create.

## PROJECT POSITION
PROGRAM_ROADMAP: DE Tianyun R0–R7; R4 existing-SKU inventory, R5 single dispatch, R6 selected batch, R7 Dev UAT and release gate.
CURRENT_TRACK_PHASE_MAP: R0–R3 verified read-only; R4 authorized Dev stock sync; R5–R6 outbound coding without real provider create; R7 Owner-gated.
COMPLETED_PHASES: R0–R3 code and live Dev read-only evidence; no real ERP Product master mapped yet.
CURRENT_PHASE: R4.
CURRENT_WORK_PACKAGE: DE_TIANYUN_XLWMS_R4_SKU_STOCK_SYNC_WP_20261120.
PACKAGE_STATUS: IN_PROGRESS.
WAITING_PHASES: R5–R7.
BASELINE: origin/feat/de-tianyun-xlwms-r1-r3-20261120@34f8c922.
GITHUB_STATE: stacked new branch feat/de-tianyun-xlwms-r4-sku-sync-20261120; parent Draft PR #820.
SOURCE_CONFLICTS: No shared edits observed as of baseline; recheck before submission.
GLOBAL_GATE: Owner visual proposal before UI; owner UAT before merge; explicit real OMS create and Production authorization separate.
PRODUCTION_DEPLOYMENT_STATUS: NOT_AUTHORIZED.
NEXT_DEPENDENCY: Backend implementation and Dev synthetic mapping; Owner visual proposal for sync controls.
EVIDENCE_AS_OF: 2026-11-20.

## ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: R4 16h.
KNOWN_CURRENT_TRACK_REMAINING: R4 16h, R5 12h, R6 7h, R7 6h = 41h planned.
ARCHITECTURE_GATE_REMAINING: Existing provider and ERP inventory infra; no new platform.
MVP1_TOTAL_ESTIMATE: R0–R3 14h original, incremental R4–R7 41h extended scope.
MVP1_PLANNING_BASELINE: 41h for this extension.
MVP1_MAXIMUM_ENVELOPE: Re-estimate if provider's stock completeness proves insufficient.
MVP1_REMAINING: 41h at package start.
POST_MVP_KNOWN_INCREMENT: None scoped.
LONG_TERM_CUMULATIVE_RANGE: TBD after Dev tests.
PROGRAM_REMAINING: 41h planned, not actual elapsed.
COMPLETED_ACTUAL_EFFECTIVE_HOURS: Not independently audited.
ESTIMATE_CONFIDENCE: MEDIUM.
ESTIMATE_AS_OF: 2026-11-20.
ESTIMATE_METHOD: Shared XLWMS + MorePlus stock workflow reuse, targeted UI and tests.

## ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 2h.
CORE_EXECUTION: 9h.
TEST_AND_VALIDATION: 3h.
REMEDIATION_BUFFER: 2h.
ESTIMATED_EFFECTIVE_HOURS: 16h.
ESTIMATE_RANGE: 14–20h.
ESTIMATE_BASIS: 3+4+4+3+2 R4 subtasks.
OWNER_REVIEW: Required.
MERGE: Not authorized.
MIGRATION: None unless justified after code review.
UAT: Isolated Dev; owner visual gate before UI.
DEPLOYMENT: Not authorized.

## R4 BACKEND IMPLEMENTATION EVIDENCE — 2026-11-20

STATE: Backend implemented; interactive UI remains DESIGN_PENDING_OWNER_VISUAL_PASS. No Production, provider order CREATE, or real inventory apply was executed.
CHANGES: DE-specific Local/Testing-only inventory apply gate; V2 and SKU-level isolation mandatory; distinct DE first-apply exact checksum; independently disabled DE 30-minute schedule; command help clarified.
SKU_POLICY: Existing canonical Product SKU or owner-verified active alias only. Unknown OMS rows quarantine; catalog-only rows are not stock zero. All stock writes use warehouse_id=DE; US-SM unaffected.
OWNER_FENCE: DE apply requires all of XLWMS_INVENTORY_APPLY_ENABLED, XLWMS_LOT_RECONCILIATION_V2_ENABLED, INVENTORY_SKU_LEVEL_GATE_ENABLED, XLWMS_DE_INVENTORY_DEV_APPLY_ENABLED and the owner-reviewed XLWMS_DE_INVENTORY_FIRST_APPLY_APPROVED_CHECKSUM. The application process must also run in Local/Testing.
SCHEDULER: XLWMS_DE_INVENTORY_SYNC_SCHEDULE_ENABLED defaults false. Enabling schedule alone produces a read-only DE snapshot every 30 minutes. Scheduled apply runs only with every separate Dev/V2/SKU gate, never Production.
TESTS: PHPUnit XLWMS feature filter 138 tests / 827 assertions; XLWMS unit filter 10 tests / 24 assertions; targeted inventory service 38 tests / 276 assertions. All PASS on isolated R4 worktree.
SYNTAX: New code and console/config PHP lint PASS; git diff --check PASS.
STYLE_BASELINE: Pint --test flags 3 pre-existing non-compliant XLWMS files on unchanged R1-R3 parent with the same 3 file-level classes of legacy style findings; R4 does not reformat unrelated legacy code.
SCHEDULE_VERIFICATION: With the single DE schedule flag set, php artisan schedule:list shows xlwms:sync-inventory --warehouse=DE --dry-run, every 30 minutes; no job was executed.

## ISOLATED DEV OPERATING SEQUENCE

1. Read-only: php artisan xlwms:sync-inventory --warehouse=DE --dry-run. Review all matched/unmatched SKU, snapshot checksum and mismatch reasons. It cannot create ERP Products.
2. For a controlled Dev-only trial, initialize a synthetic ERP Product fixture, not a copy of Production business data. Verify a precise product identity and any protected lot/reservation constraints.
3. In a separate isolated local Dev runtime, enable five gates named above; provide only its current 64-character DE snapshot checksum as the initial DE approval. Do not reuse a US-SM approval. Run php artisan xlwms:sync-inventory --warehouse=DE --apply; verify DB stock before/after, US-SM unchanged, unmatched rows quarantined.
4. For unattended Dev testing, explicitly enable XLWMS_DE_INVENTORY_SYNC_SCHEDULE_ENABLED=true only after first-apply is independently verified. Disabling the flag stops subsequent scheduled DE sync.
5. Never enable DE inventory write gates in Production; the service rejects Production even if environment flags are incorrectly set.
