# ERP-KOL-MANUAL-IDENTIFIER-NO-FORMAT-20261122

WORK_PACKAGE_ID: ERP-KOL-MANUAL-IDENTIFIER-NO-FORMAT-20261122
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK
AUTHORITY: Owner instruction, 2026-11-22; overrides bounded identifier format accepted 2026-11-20.
BASELINE: origin/main c426681bb8275749fe4d14f521b2ea10c1f31234
BRANCH: fix/erp-kol-manual-identifiers-no-format-20261122
DEPENDENCIES: NONE
PARALLEL_SAFE: YES (dedicated worktree, one implementation provider)

## Scope and acceptance

- ERP KOL create and existing-KOL provisioning must not reject Username, Affiliate Name or Affiliate URL for character set, case, leading/trailing underscores, or length.
- Do not silently normalize manually entered identifiers; preserve exact values at the controller, frontend, and provisioning service boundary.
- An Affiliate URL identical to Username must use the default username referral, not a custom slug request.
- A different Affiliate URL is handed to the provider unchanged and only reported successful after verified provider readback.
- Expand ERP Affiliate URL / Affiliate Name mirror storage to text, with guarded non-truncating rollback.
- Keep authorization, password policy, provider email/Coupon collision checks, idempotency, and external provider truth intact.
- No real WordPress, AffiliateWP, Coupon, Production database or OMS writes.
## Validation and delivery

- PHP syntax: PASS, modified controller/service/bootstrap/test and migration.
- PHPUnit: 17 PASS, 196 assertions (KOL create + existing account edit).
- Vitest: 124 PASS in 18 KOL test files.
- Vue typecheck: PASS.
- Vite production bundle / workspace-tabs verifier: PASS.
- Dev UAT: waiting for live page inspection and Owner PASS.
- Production deployment: NOT AUTHORIZED.
- Estimated effective effort: 1.5–3.5 h including local UAT, not Production release.
- External systems may still reject unsupported identifiers; ERP records that actual provider result, never claims a fabricated success.

PROJECT POSITION
PROGRAM_ROADMAP: ERP foundation → MVP1 manual operations → KOL → orders/logistics → inventory/warehouse → accounting → advanced automation.
CURRENT_TRACK_PHASE_MAP: KOL account contract repair → isolated code verification → Owner Dev UAT → controlled main delivery → separate Production release.
COMPLETED_PHASES: Baseline and root cause identified; isolated implementation and focused automated regression passed.
CURRENT_PHASE: KOL identifier policy bugfix / Dev handoff.
CURRENT_WORK_PACKAGE: ERP-KOL-MANUAL-IDENTIFIER-NO-FORMAT-20261122
PACKAGE_STATUS: IMPLEMENTED / DEV AUTOMATED TEST PASS / OWNER UAT PENDING
WAITING_PHASES: Owner live UAT, CI and merge eligibility, explicit Production release.
BASELINE: origin/main c426681bb8275749fe4d14f521b2ea10c1f31234
GITHUB_STATE: Work package branch present locally; PR/push status tracked separately.
SOURCE_CONFLICTS: 2026-11-20 unified a-z0-9_ formatting superseded by Owner 2026-11-22 no-ERP-format restriction; provider constraints remain external.
GLOBAL_GATE: OWNER_UAT_REQUIRED; PRODUCTION_RELEASE_SEPARATE
PRODUCTION_DEPLOYMENT_STATUS: NO (isolated worktree only)
NEXT_DEPENDENCY: GitHub delivery, live Owner UAT.
EVIDENCE_AS_OF: 2026-11-22 UTC+08:00
ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 1.5–3.5 effective h, Dev and Owner UAT prep only.
KNOWN_CURRENT_TRACK_REMAINING: Owner UAT and Production release excluded from completed work.
ARCHITECTURE_GATE_REMAINING: NONE for bounded repair.
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE (historic program baseline in separate roadmap documents).
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE (not recalculated in this bounded bugfix).
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE (not in scoped review).
MVP1_REMAINING: UNKNOWN WITH EVIDENCE (full-program estimate not reopened).
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE (not in scoped review).
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE (not in scoped review).
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE (not in scoped review).
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE (Work Log ledger not accessed).
ESTIMATE_CONFIDENCE: MEDIUM
ESTIMATE_AS_OF: 2026-11-22
ESTIMATE_METHOD: focused existing-code diff plus migration and local regression.

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.3
CORE_EXECUTION: 0.7
TEST_AND_VALIDATION: 0.8
REMEDIATION_BUFFER: 0.5
ESTIMATED_EFFECTIVE_HOURS: 2.3
ESTIMATE_RANGE: 1.5–3.5
ESTIMATE_BASIS: seven tracked files, one schema widening, focused tests, live UAT prep.
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: INCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: EXCLUDED for Production
UAT: INCLUDED
DEPLOYMENT: EXCLUDED
