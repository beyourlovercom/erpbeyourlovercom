# ERP-DOMESTIC-PENDING-REJECT-20261117

Status: R0-R3_VALIDATED / R4_OWNER_UAT_PENDING (2026-11-17)
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK
Branch: feat/domestic-pending-reject-with-tracking-20261117
Baseline: origin/main a83d4f32abd448c26f17cabfab3eee325e753f04 (latest observed prior to rebase)
Worktree: /Users/a/AI/007/worktrees/erp-domestic-pending-reject-20261117
Owner visual PASS: 2026-11-17, proposed pending-row reject + reason dialogue + staged-Tracking handling.

## Scope / authority
- Domestic pending row: append commercial-only 驳回 after 重新审核; retain other actions and compact-menu support.
- Reuse existing `/logistics/china/orders/{order}/reject` + reason, server `domestic.review` authorization, audit, Vita notification and allocation return.
- Allow reject of assigned and unshipped (`shipment_status=pending`, `shipped_at=null`) factory rows even if tracking was staged; clear only ERP staged tracking atomically, preserve previous values in audit.
- Split original fulfillment: all child segments must remain unshipped; reject is atomic across all siblings; never change shipped segments.
- Real dispatched/shipped, archived, historical logistics, unauthorised and invalid reasons are blocked.
- Never call provider cancellation APIs; stored/externally issued labels may need separate human handling.
- Keep user in `pending` view when rejecting from pending; default review route behavior remains unchanged.

## Prohibitions and evidence
- No Production code, order or database writes; no schema migration; no change to Woo, accounting, warehouse stock authority, global Grid, Workspace Tabs or other active ERP worktrees.
- Tests: Vue direct+compact button/modal invocation and permission; Laravel positive staged tracking + split, negative shipped/permission/invalid, persistence and audit.
- Verified prior to rebase: PHP domestic integration 59 PASS / 1502 assertions; focused Vitest 48 PASS / 3 files; `npm run typecheck` PASS; `npm run build` PASS (30-tab verifier); PHP lint/route listing PASS. Final PR head must be reverified.
- Full R0-R3 gates: focused Vitest/PHPUnit, typecheck, build with Workspace Tabs verifier, route/UI contract evidence.
- R4: prepare actual local UAT using 007windows; Owner must inspect live page and PASS before package closure/merge. Production release is separately Owner gated.

## PROJECT POSITION
PROGRAM_ROADMAP: Architecture -> MVP1 -> Inquiry -> Woo sync -> Orders -> Logistics -> KOL -> Inventory/Accounting -> integrations (unrelated tracks unchanged)
CURRENT_TRACK_PHASE_MAP: R0 baseline -> R1 pending UI -> R2 atomic reject -> R3 tests -> R4 007windows UAT -> Owner PASS
COMPLETED_PHASES: R0 scope/design PASS / R1 UI / R2 transactional backend / R3 focused and broad suites PASS prior to rebase
CURRENT_PHASE: R4_PREPARATION
CURRENT_WORK_PACKAGE: ERP-DOMESTIC-PENDING-REJECT-20261117
PACKAGE_STATUS: R0_R3_PASS_R4_PENDING
WAITING_PHASES: exact PR-head checks / R4 Owner live UAT / Owner merge permission / Production release
BASELINE: a83d4f32abd448c26f17cabfab3eee325e753f04
GITHUB_STATE: main verified; new isolated branch not yet pushed
SOURCE_CONFLICTS: NONE identified; staged tracking restriction intentionally changed by Owner approval
GLOBAL_GATE: Owner design PASS; mutation contract tests PASS prior to rebase; Owner live UAT pending
PRODUCTION_DEPLOYMENT_STATUS: NO; this package forbids deployment
NEXT_DEPENDENCY: verify rebased PR exact-head / 007windows Dev UAT access
EVIDENCE_AS_OF: 2026-11-17

## ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 4-6 effective hours
KNOWN_CURRENT_TRACK_REMAINING: R4 real browser UAT and signoff, estimated 0.5-1h if Dev link works
ARCHITECTURE_GATE_REMAINING: 0; reuse canonical service
MVP1_TOTAL_ESTIMATE: UNKNOWN; broader program not part of this package
MVP1_PLANNING_BASELINE: UNKNOWN; not re-estimated here
MVP1_MAXIMUM_ENVELOPE: UNKNOWN; not re-estimated here
MVP1_REMAINING: UNKNOWN; unrelated program
POST_MVP_KNOWN_INCREMENT: 4-6h for this change only
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN; no cross-program aggregation
PROGRAM_REMAINING: UNKNOWN; other tracks excluded
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN; work log not assessed
ESTIMATE_CONFIDENCE: MEDIUM
ESTIMATE_AS_OF: 2026-11-17
ESTIMATE_METHOD: source inspection and bounded test estimates

## ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.5h
CORE_EXECUTION: 2-3h
TEST_AND_VALIDATION: 1-1.5h
REMEDIATION_BUFFER: 0.5-1h
ESTIMATED_EFFECTIVE_HOURS: 4-6h
ESTIMATE_RANGE: 4-6h
ESTIMATE_BASIS: two Vue views, one Laravel service/controller, focused tests
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
UAT: INCLUDED
DEPLOYMENT: EXCLUDED
