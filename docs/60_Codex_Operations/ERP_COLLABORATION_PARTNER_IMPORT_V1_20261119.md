# ERP Collaboration Partner Import V1 — Work Package
Date: 2026-11-19 | Owner: Andy | EXECUTION_PROVIDER: GPT_WEB | VERIFICATION_TIER: HIGH_RISK
Status: OWNER_DESIGN_PASS / DEV_VERIFIED / OWNER_REAL_UAT_PENDING / NO_PRODUCTION_RELEASE
Approved UI: import order type “合作订单”, select dynamic partner, current 15-column XLSX unchanged; confirmed by Owner “合理，升级。执行”.
Baseline: GitHub erpbeyourlovercom main 078de3ecdebc1b9dbbc26f29717e0f4833af55bc.
Branch: feat/erp-collaboration-partner-import-v1; worktree: US Mac mini /Users/a/ai/007/erp-collaboration-partners-20261119.
Dependencies: NONE (independent main). Parallel safe: dedicated clean checkout; one active implementation provider.
Scope: create canonical collaboration partner registry (code/name/active) and nullable orders relation; maintain admin-only partner management through import UI; add collaboration type + server validation; bind preview token and transactional import to selected partner; show and filter partners on collaboration orders and order master, preserve legacy TINGON rows as unassigned without silent relabeling.
Preserve: original import template, old website/KOL/manual_other imports, warehouse routing, KOL permissions, payment/sync, order source identity, source order numbers, all historical records.
Guardrails: no Production DB access or migration execution; no live order creation; only local/test DB. No restart of READY devices or Chrome. No remote Production deployment. Original external-order global uniqueness unchanged in V1.
Acceptance: no partner on collaboration import fails; wrong/missing/inactive partner fails; changing partner between preview and commit fails; valid partner persists atomically; unauthorized partner CRUD fails; saved list columns and warehouse routing intact; legacy TINGON rows remain visible as “待确认”.
Testing: PHP feature HTTP allowed/denied/readback, importer preview+commit/rollback, Vue component tests, typecheck, npm build with workspace tabs verifier, route check, Local UAT Owner PASS.
Verification and UAT: US Mac isolated local port. Owner must inspect real pages and PASS before merge. Production release requires a separate explicit Owner PASS.
Estimation: R0 contract/schema 3–5h; R1 registry/import 6–9h; R2 list/filter 4–6h; R3 tests/UAT 5–8h. Total 18–28h, planning 23h.
## PROJECT POSITION
PROGRAM_ROADMAP: ERP live operations → ERP2 code-first → safe data migration → staged Production release; unrelated tracks preserved
CURRENT_TRACK_PHASE_MAP: R0 schema → R1 import registry → R2 list UI → R3 verification/UAT → R4 separately authorized release
COMPLETED_PHASES: Owner visual design PASS; R0 schema, R1 dynamic registry/import, R2 collaboration list/UI, R3 automated code/tests (Owner real UAT pending)
CURRENT_PHASE: R3 — Owner real local UAT handoff
CURRENT_WORK_PACKAGE: ERP-COLLABORATION-PARTNER-IMPORT-V1
PACKAGE_STATUS: DEV_VERIFIED_AWAIT_OWNER_UAT
WAITING_PHASES: Owner real UAT PASS → PR merge review → separately approved R4 Production release
BASELINE: 078de3ecdebc1b9dbbc26f29717e0f4833af55bc
GITHUB_STATE: dedicated branch, Draft PR pending
SOURCE_CONFLICTS: old collaboration view filters TINGON warehouse instead of actual partner; resolved by separate field
GLOBAL_GATE: Owner UAT mandatory; Production release independent
PRODUCTION_DEPLOYMENT_STATUS: NO, not authorized
NEXT_DEPENDENCY: Owner inspect real local UAT and explicitly PASS; no Production changes
EVIDENCE_AS_OF: 2026-11-19; PHP 35 tests/744 assertions PASS, Vue import 7 tests PASS, typecheck/Pint/build/workspace tabs PASS; complete Vue suite pending
## ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 23h planning; range 18–28h
KNOWN_CURRENT_TRACK_REMAINING: Owner real UAT/review and optional remediation; 2–5h planning remainder, actual unmeasured
ARCHITECTURE_GATE_REMAINING: NONE, design approved
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE (not relevant to isolated work package)
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE (not re-estimated here)
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE (not re-estimated here)
MVP1_REMAINING: UNKNOWN WITH EVIDENCE (not re-estimated here)
POST_MVP_KNOWN_INCREMENT: 18–28h for this isolated package
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE (not part of WP)
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE (broader ERP scope open)
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE (no signed work log)
ESTIMATE_CONFIDENCE: MEDIUM
ESTIMATE_AS_OF: 2026-11-19
ESTIMATE_METHOD: based on current import/form/controller/schema/test audit
## ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 2–3h (planning 2.5h)
CORE_EXECUTION: 11–16h (planning 13.5h)
TEST_AND_VALIDATION: 3–6h (planning 4.5h)
REMEDIATION_BUFFER: 2–3h (planning 2.5h)
ESTIMATED_EFFECTIVE_HOURS: 23h
ESTIMATE_RANGE: 18–28h
ESTIMATE_BASIS: schema/CRUD/import/filters/feature/UI contract tests
OWNER_REVIEW: EXCLUDED
MERGE: INCLUDED subject to Owner UAT PASS
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: INCLUDED (preparation only)
DEPLOYMENT: EXCLUDED
