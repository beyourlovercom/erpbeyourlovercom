---
id: ERP-BACKUP-MIGRATION-M1-4-RESTORE-001
title: ERP Backup & Migration M1.4 — Restore Foundation
status: IMPLEMENTATION_COMPLETE / VERIFIED / READY_FOR_GIT_DELIVERY
branch: feature/erp-backup-migration-m1-4-restore-001
base: origin/main@09d316fa5add5c243cfbb7edb7b4275ea6c83a8a
target_base: main@09d316fa5add5c243cfbb7edb7b4275ea6c83a8a
EXECUTION_PROVIDER: US_CODEX_CLI
VERIFICATION_TIER: HIGH_RISK
implementation_authorization: OWNER_NEXT_STEP_2026-09-01
git_delivery: OWNER_AUTHORIZED_2026-09-02
merge: NOT_AUTHORIZED
migration_execution: NOT_AUTHORIZED
real_restore: NOT_AUTHORIZED
production_deployment: NOT_AUTHORIZED
---

# Scope

This package implements a contract-driven Restore foundation. It supports local confined encrypted assets and a fakeable GitHub Release source, validates the encrypted SHA-256 and canonical package, enforces exact compatibility, requires a verified `pre_restore` snapshot, and sequences database-only/full restore through destructive-operation contracts. No UI restore controls or routes are enabled.

Restore state is returned as an application DTO; no new migration is authored because existing `backup_runs`/`metadata` is sufficient for the current foundation and no durable restore record is required to make destructive execution safe. Concrete database, storage, snapshot, migration and health adapters remain outside this turn.

Tests must use fakes/mocks. No migration, real GitHub request, database restore, storage overwrite, production backup, or deployment is authorized or performed.

# Acceptance contract

- exact states and guarded phase transitions are implemented;
- source confinement, suffix, encrypted checksum, shared AES-256-GCM inspect/decrypt, package file/checksum/manifest validation and strict compatibility are fail-closed;
- traversal/symlink package entries and unexpected files are rejected;
- snapshot readiness is proven before destructive contracts are called;
- database-only never calls storage; full requires manifest storage inclusion;
- failures after destructive start attempt rollback and distinguish rollback success/failure;
- health check is required before `completed`;
- GitHub source validates private repository/release/asset metadata, bounds download size, and does not persist token/provider response metadata.

# Project Position

PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Existing Operations → ERP-INQUIRY-M1 → Local Milestone UAT → Milestone-gated Remote Release → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Production Hardening / Expansion
CURRENT_TRACK_PHASE_MAP: KOL V1 FULL COMPLETION TRACK; this package is a separately authorized Backup/M1.4 implementation from the exact requested baseline
COMPLETED_PHASES: Architecture foundation; Backup M1.0 contract, M1.2 engine
CURRENT_PHASE: Backup/M1.4 Restore foundation
CURRENT_WORK_PACKAGE: ERP-BACKUP-MIGRATION-M1-4-RESTORE-001
PACKAGE_STATUS: IMPLEMENTATION_COMPLETE / VERIFIED / READY_FOR_GIT_DELIVERY
WAITING_PHASES: Explicit Git delivery authorization; then PR CI/merge gate, merge, real restore UAT, production deployment
BASELINE: worktree HEAD@09d316fa5add5c243cfbb7edb7b4275ea6c83a8a = origin/main@09d316fa5add5c243cfbb7edb7b4275ea6c83a8a
GITHUB_STATE: Local branch only; no delivery performed
SOURCE_CONFLICTS: RESOLVED WITH OWNER AUTHORIZATION — one Architecture baseline SHA conflict; latest main SHA retained, M1.4 baseline additions preserved
GLOBAL_GATE: PASS WITH INHERITED DEBT — 14 non-Backup APP-001 on current main; Backup APP-001 = 0
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE — no deployment performed
NEXT_DEPENDENCY: Explicit M1.4 Git Delivery authorization (commit + push + create PR)
EVIDENCE_AS_OF: 2026-09-02

# Roadmap Hours

CURRENT_PACKAGE_ESTIMATE: 8.0 hours (6.0–11.0)
KNOWN_CURRENT_TRACK_REMAINING: UNKNOWN WITH EVIDENCE
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE
ESTIMATE_CONFIDENCE: medium
ESTIMATE_AS_OF: 2026-09-01
ESTIMATE_METHOD: bounded implementation plus focused/high-risk verification estimate

# Estimated Codex Hours

PREFLIGHT_AND_SCOPE: 1.0 (0.5–1.5)
CORE_EXECUTION: 4.5 (3.5–6.5)
TEST_AND_VALIDATION: 1.5 (1.0–2.0)
REMEDIATION_BUFFER: 1.0 (1.0–1.0)
ESTIMATED_EFFECTIVE_HOURS: 8.0
ESTIMATE_RANGE: 6.0–11.0
ESTIMATE_BASIS: frozen M1.4 contract; no durable migration or real adapters executed
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED

# M1.4 Closeout Evidence — 2026-09-02

- Focused Backup/Restore/ModuleProvider gate on worktree HEAD `01d11061c7d02fc6275353deee59e69dbef62216`: `86/86 PASS`, `278 assertions`.
- Restore unit gate: `35/35 PASS`, `102 assertions`; GitHub restore source: `10/10 PASS`, `15 assertions`.
- Architecture gate on that HEAD: frozen file/hash/route/migration baselines reconciled; only `14` non-Backup `APP-001` violations remain from mainline history, including two OrderIdentityService violations introduced by PR #151; Backup `APP-001 = 0`.
- `git diff --check`: PASS. Dependency manifests (`composer.json`, `composer.lock`, `package.json`, lockfiles): unchanged.
- PHP lint: `33` changed/untracked PHP files checked, `0` failures. Secret-pattern scan: `34` changed/untracked text files checked, `0` hit files.
- Fetch on 2026-09-02 advanced `origin/main` to `09d316fa5add5c243cfbb7edb7b4275ea6c83a8a` via PR #152. Worktree is `0 ahead / 3 behind`.
- Drift overlap is exactly `tests/Architecture/ModuleArchitectureTest.php`. A temporary three-way `git merge-file` dry-run returned `rc=1` with exactly `1` conflict block. No real merge/rebase/conflict resolution was performed.
- Owner explicitly authorized main alignment + single Architecture conflict resolution + complete re-verification. Worktree aligned to `09d316fa5add5c243cfbb7edb7b4275ea6c83a8a`; conflict resolved by retaining the current-main `Orders/Show.vue` frozen SHA and preserving all M1.4 Backup baseline additions.
- Post-alignment Architecture: `8 tests / 529 assertions`, only `14` current-main non-Backup `APP-001`; Backup `APP-001 = 0`.
- Post-alignment focused gate: `86/86 PASS`, `278 assertions`; temporary Vite fixture removed.
- Post-alignment hygiene: PHP lint `33/33`, secret scan `34 files / 0 hits`, dependency manifests unchanged, `git diff --check` PASS, conflict markers NONE.
- M1.4 is now READY_FOR_GIT_DELIVERY; commit/push/create PR remains NOT_AUTHORIZED.
