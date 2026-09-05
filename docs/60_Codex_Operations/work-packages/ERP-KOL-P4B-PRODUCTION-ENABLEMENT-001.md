---
type: work-package
project: BYL ERP
work_package_id: ERP-KOL-P4B-PRODUCTION-ENABLEMENT-001
status: IMPLEMENTING
execution_provider: GPT_WEB
verification_tier: HIGH_RISK
baseline: origin/main@ea490c74e9bd919c5072c408f971c62873e32d5d
production_deployment_authorized: NO
production_real_data_apply_authorized: NO
---

# KOL P4B — Production Enablement

## Goal
Enable the already-merged P4B exact Affiliate binding engine to be invoked in Production only through an explicit console-only authorization path, while keeping the existing Production Web apply path fail-closed.

## Current Production evidence
- Canonical KOLs: 961.
- P4B read-only projection for applied P4 batch #2: 854 `ready_for_binding`, 105 `no_affiliate`, 2 `blocked` (`multiple_affiliates`), 0 `already_bound`.
- The 854 ready Affiliates currently cover 2,416 ERP-local Referral rows.
- P4B Production apply is currently hard-locked by code; no binding batch or item has been applied in Production.

## Scope
1. Preserve the existing Web/local-testing `apply()` behavior and Production block.
2. Add a separate `applyProductionAuthorized()` service path that is valid only when `app()->environment('production')` is true.
3. Add one console command that requires exact binding batch ID, plan checksum, ready count, active authorized actor ID, and an explicit confirmation phrase.
4. The console path must delegate to the same existing transactional P4B apply implementation and therefore to `AffiliateBindingService::bind()` only.
5. Record the execution surface in the existing batch-level audit evidence.
6. Register the command through `KolServiceProvider` only when running in console.
7. Add high-risk tests proving Web Production apply remains locked, the console production path is unavailable outside Production, confirmation mismatch fails closed, and exact Production-authorized execution preserves existing P4B invariants.

## Frozen safety contract
- No new identity engine and no fuzzy/name/address/social inference.
- No AffiliateWP/WordPress/WooCommerce Provider mutation.
- No `convert()` and no new KOL creation.
- No direct writes to `kol_affiliate_accounts.kol_id`; existing `AffiliateBindingService::bind()` remains sole authority.
- No direct Referral rewrite outside existing `ReferralResolutionService` path.
- No schema migration.
- No UI/menu/route changes.
- No persistent Production enable flag.
- The existing Production Web action remains disabled by `bindingEnabled()`.
- Production console execution requires exact plan identity and a literal confirmation phrase; stale/current-state rechecks remain mandatory.

## Production execution contract
The new console command is a release-time operation, not an ordinary operator workflow. Required inputs:
- binding batch ID;
- exact 64-char plan checksum;
- exact ready count;
- active ERP actor ID with existing Administrator/KOL authority;
- `--confirm=APPLY_EXACT_KOL_AFFILIATE_BINDINGS`.

The command must fail without mutation if any input is missing/malformed, the environment is not Production, the actor is missing/inactive/unauthorized, or the stored/current plan has drifted.

## Acceptance criteria
- Existing `apply()` still returns 403 in Production even if the local/testing config flag is true.
- `applyProductionAuthorized()` returns 403 outside Production.
- Production-authorized path uses the exact same checksum/ready-count/current-state checks as P4B apply.
- Successful production-authorized execution binds only ready rows, locally re-resolves Referrals, preserves Provider-fact checksums, and records `execution_surface=production_console` in batch apply audit evidence.
- Console command refuses incorrect confirmation before any mutation.
- Console command refuses inactive/missing actors and malformed exact confirmation inputs.
- Existing P4B focused tests remain green.
- KOL + Identity regression, typecheck/build/diff-check remain green as applicable.

## Latest-main reconciliation — 2026-09-05
- `origin/main` advanced from `8b848e4422029332438b292f412e42f932cf6e2a` to `ea490c74e9bd919c5072c408f971c62873e32d5d` through Purchase OS closure PR #183.
- Overlap with all five P4B Production Enablement files: none.
- Recovery ref: `recovery/erp-kol-p4b-production-enablement-pre-rebase-20260905-1338`.
- Branch rebased cleanly to exact current main before final verification.
- Clean current-main Architecture test independently reproduces the same three baseline failures (`frozen module/support directories`, `layer scan file set`, `MIG-001 migration file set`), proving they are pre-existing main baseline drift rather than this P4B patch.

## Delivery gate
Routine implementation/commit/push/PR/CI/merge are continuously authorized inside this bounded Work Package. Production deployment and real-data P4B apply remain separate explicit Owner gates.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate [COMPLETE] → MVP1 Existing Operations / Tencent Docs Replacement → Inquiry → Woo Inbound → Tracking Outbound → Sync Orchestration → Product / Media → WordPress Sync → Content / SEO → Full Order Workspace → Logistics OS → KOL OS → Inventory / Procurement → Accounting / ROI → Additional Integrations → Production Hardening
CURRENT_TRACK_PHASE_MAP: P1 Reconciliation COMPLETE → P2 Import Preview COMPLETE → P3 Owner Mapping COMPLETE → P4 Canonical Import PRODUCTION COMPLETE (961) → P4B Engineering COMPLETE → P4B Production Enablement CURRENT → P4B Production Apply WAITING
COMPLETED_PHASES: P1, P2, P3, P4 engineering and Production import, P4B engineering/UAT
CURRENT_PHASE: P4B Production Enablement
CURRENT_WORK_PACKAGE: ERP-KOL-P4B-PRODUCTION-ENABLEMENT-001
PACKAGE_STATUS: IMPLEMENTING
WAITING_PHASES: Production deployment gate; real-data P4B apply gate; post-apply reconciliation
BASELINE: origin/main@ea490c74e9bd919c5072c408f971c62873e32d5d
GITHUB_STATE: dedicated branch/worktree rebased cleanly to origin/main@ea490c74; PR not yet opened
SOURCE_CONFLICTS: NONE WITH EVIDENCE; current P4B work package and Production runtime behavior agree that Production apply is hard-locked
GLOBAL_GATE: Production deploy and real-data mutation require explicit Owner authorization
PRODUCTION_DEPLOYMENT_STATUS: NO for this Work Package; live ERP remains prior bounded M5 release
NEXT_DEPENDENCY: merge-tested Production enablement patch, then explicit Production deploy/apply authorization
EVIDENCE_AS_OF: 2026-09-05

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 0.8h planning value; 0.5-1.2h range
KNOWN_CURRENT_TRACK_REMAINING: Production deploy/apply and post-apply reconciliation; effort not arithmetically combined here
ARCHITECTURE_GATE_REMAINING: 0h for this bounded patch
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE; not recomputed by this package
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE; not recomputed by this package
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE; not recomputed by this package
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; not recomputed by this package
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE; not recomputed by this package
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE; not recomputed by this package
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; not recomputed by this package
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; no accepted Work Log ledger consulted by this package
ESTIMATE_CONFIDENCE: HIGH for bounded code patch
ESTIMATE_AS_OF: 2026-09-05
ESTIMATE_METHOD: bounded service/console/test change based on existing P4B architecture

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.1-0.2h / planning 0.15h
CORE_EXECUTION: 0.2-0.5h / planning 0.35h
TEST_AND_VALIDATION: 0.15-0.3h / planning 0.2h
REMEDIATION_BUFFER: 0.05-0.2h / planning 0.1h
ESTIMATED_EFFECTIVE_HOURS: 0.8h
ESTIMATE_RANGE: 0.5-1.2h
ESTIMATE_BASIS: one service refactor, one console command, provider registration, focused high-risk tests, no UI/schema changes
OWNER_REVIEW: EXCLUDED
MERGE: INCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
