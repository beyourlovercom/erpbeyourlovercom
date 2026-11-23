# DE Tianyun XLWMS R1–R3 Work Package — 2026-11-20

OWNER_AUTHORIZATION: Andy, explicit “继续 R1–R3” following first-batch 14h R0–R3.
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK
EXECUTION_SCOPE: Independent branch/worktree from origin/main; Dev and mock-only code validation.
TARGET: Existing ERP warehouse DE (德国天运仓), OMS whCode Hannover, existing XLWMS shared adapter.
R0 EVIDENCE: Authorized US Mac mini Keychain-based signed read-only calls accepted by OMS; 34 DE stock SKU rows, 4 channels, 965 historical outbound records, no writes.
R1 (3h): Warehouse-scoped, encrypted XLWMS connection check/provisioning; backward compatible US-SM; no plaintext credentials in repo or output; local-TTY-only secret setup remains mandatory.
R2 (6h): Explicit DE inventory dry-run, warehouse-scoped snapshot and SKU identity reconciliation; no DE inventory quantity apply; US-SM behavior preserved.
R3 (3h): DE provider logistics-channel read-only lookup, strict Hannover scoping, candidate mappings to ERP carrier/service catalog, unmatched statuses; no auto-mapping/dispatch.
OUT_OF_SCOPE: R4–R7, UI redesign, carrier creation, outboundOrder/create, stock apply for DE, production migration/deployment, credential transport, full historical order ingestion.
SAFE_VALIDATION: Targeted PHPUnit with Http fakes, CLI --check, syntax/style tests, signed live read-only probes only with existing authorized Keychain; no Production state changes.
ACCEPTANCE: DE=Hannover, US-SM separate; stock and channels fail closed on mismatched warehouse, secret never logged; no real outbound write, no DE apply; test evidence and review gate.
FAIL_CLOSED: Invalid warehouse codes rejected, missing DB connections reported; no bypass of trusted local TTY requirement for provisioning.
GIT_RULES: One package/branch/PR; baseline origin/main 699acc61c8fce0fbb5221172f9fa574e90521393; no edits to concurrent worktrees; Owner review before merge/release.

## PROJECT POSITION
PROGRAM_ROADMAP: DE Tianyun XLWMS R0→R7, total planning estimate 38h.
CURRENT_TRACK_PHASE_MAP: R0 proof; R1 connection; R2 inventory; R3 channel mapping; R4 outbound; R5 status; R6 UAT; R7 launch.
COMPLETED_PHASES: R0 PASS, read-only live OMS evidence.
CURRENT_PHASE: R1–R3 code/testing passed; ERP Dev binding/UAT pending.
CURRENT_WORK_PACKAGE: DE_TIANYUN_XLWMS_R1_R3_20261120.
PACKAGE_STATUS: CODE_TESTS_PASS; 46 tests, 295 assertions; Dev encrypted provisioning pending, no production authorized.
WAITING_PHASES: R4–R7.
BASELINE: origin/main@699acc61c8fce0fbb5221172f9fa574e90521393.
GITHUB_STATE: feat/de-tianyun-xlwms-r1-r3-20261120, separate worktree.
SOURCE_CONFLICTS: None observed at baseline; check before PR.
GLOBAL_GATE: Owner review/UAT; DE inventory writes off; no live dispatch.
PRODUCTION_DEPLOYMENT_STATUS: NOT_AUTHORIZED.
NEXT_DEPENDENCY: Owner review, trusted local TTY credential binding in Dev, read-only Dev snapshot and channel review.
EVIDENCE_AS_OF: 2026-11-20.

## ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 12h (R1 3, R2 6, R3 3).
KNOWN_CURRENT_TRACK_REMAINING: 12h planned; actual pending.
ARCHITECTURE_GATE_REMAINING: N/A, shared XLWMS adapter exists.
MVP1_TOTAL_ESTIMATE: 38h R0–R7.
MVP1_PLANNING_BASELINE: 38h.
MVP1_MAXIMUM_ENVELOPE: TBD after R3 review.
MVP1_REMAINING: 36h after R0, 24h after R1–R3 completion.
POST_MVP_KNOWN_INCREMENT: None scoped.
LONG_TERM_CUMULATIVE_RANGE: 38h planning baseline, refine after R3.
PROGRAM_REMAINING: 36h at package start.
COMPLETED_ACTUAL_EFFECTIVE_HOURS: R0 completed; R1–R3 implementation code verified, audited wall/effective hours not available.
ESTIMATE_CONFIDENCE: MEDIUM.
ESTIMATE_AS_OF: 2026-11-20.
ESTIMATE_METHOD: Existing XLWMS code reuse, interface and integration-test work breakdown.

## ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 1h.
CORE_EXECUTION: 8h.
TEST_AND_VALIDATION: 2h.
REMEDIATION_BUFFER: 1h.
ESTIMATED_EFFECTIVE_HOURS: 12h.
ESTIMATE_RANGE: 10–16h depending on provider schema reconciliation.
ESTIMATE_BASIS: R1=3h; R2=6h; R3=3h.
OWNER_REVIEW: REQUIRED prior to merge.
MERGE: NOT AUTHORIZED YET.
MIGRATION: None expected.
UAT: REQUIRED before live enabling.
DEPLOYMENT: NOT AUTHORIZED.
