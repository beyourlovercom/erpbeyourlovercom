# ERP KOL Password + Coupon Default — 2026-11-23

WORK_PACKAGE_ID: ERP-KOL-PASSWORD-COUPON-DEFAULT-20261123
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK
OWNER_VISUAL_PASS: 2026-11-23 — password helper under input, exact wording approved
BRANCH: fix/erp-kol-password-coupon-default-20261123
WORKTREE: /Users/a/ai/007/worktrees/erp-kol-password-coupon-default-20261123
BASELINE: origin/main c426681bb8275749fe4d14f521b2ea10c1f31234
DEPENDENCIES: YBY-Core WordPress Connector aligned password validation, separate repo/release
DEPENDENCY_TYPE: EXTERNAL
DEPENDENCY_STATUS: must release Connector before accepting newly allowed ERP passwords on Production
PARALLEL_SAFE: NO cross-repo release; serial order required

## Owner-approved scope
- Create KOL and Edit KOL WordPress password rule: begins with ASCII English letter, length strictly greater than six characters. Do not require a digit or silently impose an ERP length ceiling.
- Show exact rule directly beneath Password fields, with validation feedback in context.
- Restore previously Owner-approved default KOL WooCommerce coupon policy: percent / 20, when no explicit environment value is supplied.
- Preserve explicit invalid/missing overrides as hard-stop; preserve duplicate email, WordPress/AffiliateWP identity and coupon Code preflights.
- Do not change Username/Affiliate URL identifier rules; separate unmerged PR #828 owns those.
- Password remains request-only, never stored, logged, surfaced or read back.

## Prohibitions
- No Production deployment, no real WordPress/ERP/Coupon writes, no existing Coupon modification or duplicate registration.
- No credentials, default override of explicit configured amount, or bypass of authorization/duplicate checks.
## Acceptance & verification
- Backend Create, Edit provision and password update all accept Abcdefg (7 alphabetic chars) and reject 1Abcdefg, Abcdef (6), and empty values.
- Browser shows `英文字母开头，长度大于 6 个字符。` immediately beneath every relevant password input.
- No redundant current regex rule requiring digits or 128-character cap remains in ERP WordPress password paths.
- When KOL_CREATE_COUPON_AMOUNT is unset, default is percent 20; explicit null/invalid configuration still blocks before writes.
- Password confirmation still must match; other KOL account and Coupon readback gates remain unchanged.
- PHP feature tests, KOL frontend tests, typecheck, production build and required GitHub CI pass on exact PR head.
- Local/UAT browser opens Create and Edit Password views for Owner inspection; Owner UAT PASS before merge.
- External YBY-Core connector follows its own Windows-local/Dev/WWW gates; no claim of external interoperability until same policy released there.

## Local validation evidence (2026-11-23)
- PHP KOL suite: `php artisan test tests/Feature/Kol --compact` — 344 tests / 1,769 assertions PASS.
- Focused PHP Create/Edit account coverage: 19 tests / 236 assertions PASS.
- Vue KOL suite: `npx vitest run resources/js/Pages/Kol` — 124 tests / 18 files PASS.
- Vue focused Create/Edit coverage: 52 tests PASS.
- `npm run typecheck`, `npm run build`, PHP syntax checks, `git diff --check`: PASS.
- Local `php artisan config:show kol`: default `coupon_discount_type=percent`, `coupon_amount=20` when amount env not set.
- Tests use mocked gateways only; no live WordPress/ERP/Coupon writes. Live Owner browser UAT and GitHub CI remain separate gates.

## Repository & execution rules
- One Work Package / one dedicated worktree / one Draft PR.
- No shared-file parallel implementation. GitHub main is canonical.
- GPT+US DC performs bounded ERP remediation; Windows Codex owns YBY-Core per its AGENTS.md.
- Exact deployed SHA and Production release require separate Owner authorization.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture → MVP1 Ops → Inquiry → Woo/Tracking/Sync → Product/Content → Order/Logistics → KOL → Inventory/Accounting → Expansion
CURRENT_TRACK_PHASE_MAP: KOL account create/edit → password+coupon default → Local/UAT → GitHub PR → Owner PASS → merge → gated Production
COMPLETED_PHASES: earlier ERP KOL module and PR #828 identifier-format branch delivered for UAT
CURRENT_PHASE: password/Coupon remediation
CURRENT_WORK_PACKAGE: ERP-KOL-PASSWORD-COUPON-DEFAULT-20261123
PACKAGE_STATUS: LOCAL_TESTS_PASS; PR_PENDING; OWNER_BROWSER_UAT_PENDING
WAITING_PHASES: GitHub CI, Owner live browser UAT, merge, gated Production release
BASELINE: c426681bb8275749fe4d14f521b2ea10c1f31234
GITHUB_STATE: new branch, PR pending
SOURCE_CONFLICTS: YBY-Core current password 6–128 + digit; separate remediation required before Production
GLOBAL_GATE: Owner visual PASS; Owner 2026-11-23 authorized merge and Production release, conditional on live browser UAT and all release safeguards
PRODUCTION_DEPLOYMENT_STATUS: NO — this package is isolated Development
NEXT_DEPENDENCY: YBY-Core connector policy alignment and Local/Dev evidence; Production Connector release before ERP enablement
EVIDENCE_AS_OF: 2026-11-23
ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 3.0 effective hours (2.0–4.5)
KNOWN_CURRENT_TRACK_REMAINING: ERP KOL password/Coupon remediation only; other KOL work outside scope
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE — no new architecture in this patch
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE — historical planning baseline not recomputed
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE — historical program calculation out of scope
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE — historical program calculation out of scope
MVP1_REMAINING: UNKNOWN WITH EVIDENCE — accepted work log not refreshed
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE — scope is a defect fix
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE — no new full-program estimate
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE — other work packages excluded
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — work log not inspected
ESTIMATE_CONFIDENCE: medium
ESTIMATE_AS_OF: 2026-11-23
ESTIMATE_METHOD: bounded controller/config/Vue/tests plus Local UAT

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.4h (0.3–0.6)
CORE_EXECUTION: 1.0h (0.6–1.4)
TEST_AND_VALIDATION: 1.2h (0.8–1.8)
REMEDIATION_BUFFER: 0.4h (0.3–0.7)
ESTIMATED_EFFECTIVE_HOURS: 3.0h
ESTIMATE_RANGE: 2.0–4.5h
ESTIMATE_BASIS: existing KOL Create/Edit code and tests; connector delivered separately
OWNER_REVIEW: EXCLUDED
MERGE: INCLUDED
MIGRATION: EXCLUDED
UAT: INCLUDED
DEPLOYMENT: EXCLUDED
