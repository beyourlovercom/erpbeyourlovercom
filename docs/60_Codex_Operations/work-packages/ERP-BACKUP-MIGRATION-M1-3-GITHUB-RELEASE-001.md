---
id: ERP-BACKUP-MIGRATION-M1-3-GITHUB-RELEASE-001
title: ERP Backup & Migration M1.3 — GitHub Release Transport
status: IMPLEMENTATION_COMPLETE / VERIFIED / READY_FOR_GIT_DELIVERY
branch: feature/erp-backup-migration-m1-3-github-release-001
base: f65f663528241e7933f0dbd4568fb041f25488f3
EXECUTION_PROVIDER: US_CODEX_CLI
VERIFICATION_TIER: HIGH_RISK
implementation_authorization: OWNER_NEXT_STEP_2026-09-01
git_delivery_authorization: OWNER_EXPLICIT_COMMIT_PUSH_CREATE_PR_2026-09-01
git_delivery: OWNER_AUTHORIZED_2026-09-01
merge: NOT_AUTHORIZED
migration_execution: NOT_AUTHORIZED
production_deployment: NOT_AUTHORIZED
real_github_publication: NOT_AUTHORIZED
---

# Scope

Add the application-level GitHub release transport contract/DTOs and publisher, Laravel HTTP infrastructure gateway, queued publication job, configuration and minimal M1.2 DTO/repository/state compatibility. The canonical encrypted `.tar.zst.enc` artifact is the only upload candidate. No UI, Vue, menu, routes, controllers, scheduler, restore, migrations, dependency manifest, or Git delivery changes are in scope.

The configured target is `beyourlovercom/erp-backups`. Readonly preflight on 2026-09-01 confirmed that this repository now exists and is PRIVATE. Repository provisioning is no longer a blocker; live publication remains separately Owner-authorized and requires runtime token injection. The gateway is tested only with `Http::fake()` and `Http::preventStrayRequests()`.

# Acceptance / prohibitions

- Require a private repository response before release or asset calls; missing/public/config/token failures fail closed.
- Use deterministic `byl-erp-backup-{run_uuid}` tags and idempotent release/asset lookup.
- Validate exactly one encrypted artifact, path confinement, suffix, non-empty size, recorded size and SHA-256 before HTTP.
- Stream the raw asset through PSR-7/Guzzle; never load the whole file or upload plaintext/supporting files.
- Persist only bounded non-secret remote metadata; transport failure rolls back `Publishing` to `Ready`, records a safe status/error code, audits sanitized data, and throws a generic exception.
- Allow `Publishing -> Ready` retry while preserving existing Ready -> Publishing/Verified behavior.

# Verification record

GitHub asset digest compatibility: when GitHub returns `digest`, it must exactly equal `sha256:{local_checksum}` for both existing and newly uploaded assets. If `digest` is absent, M1.3 falls back to exact name + size matching for compatibility with older/provider responses. The provider response body is never persisted.

Required commands: focused M1.0/M1.1/M1.2/M1.3 Backup tests plus `ModuleProviderTest`; full `ModuleArchitectureTest`; PHP lint; `git diff --check`; dependency-manifest comparison; secret scan. Final evidence: focused gate PASS, 50 tests / 170 assertions; full `ModuleArchitectureTest` 8 tests / 523 assertions with only the documented 12 pre-existing non-Backup APP-001 findings (1 failure + 1 risky), Backup APP-001 = 0 and frozen Backup file/directory checks PASS; PHP lint and diff check PASS; dependency manifests unchanged; no literal credential/private-key/token secret found; no tracked `public/build` or `vendor` files. No real GitHub request, migration, backup, upload, commit, push, PR, merge, or deployment.

REAL_GITHUB_WRITES=0
MIGRATION_EXECUTION=0
BACKUP_APP_INFRA_IMPORTS=0

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration → MVP1 Existing Operations → ERP-INQUIRY-M1 → milestone UAT/release → WooCommerce/Tracking/Sync → Product/Media → Content/SEO → Publishing → Order Workspace → Logistics → KOL → Inventory/Procurement → Accounting → additional integrations → production hardening
CURRENT_TRACK_PHASE_MAP: Backup M1.0 contract foundation COMPLETE → M1.1 UI/menu COMPLETE → M1.2 backup engine COMPLETE → M1.3 GitHub release transport CURRENT → M1.4 restore WAITING → M1.5 scheduling WAITING
COMPLETED_PHASES: M1.0, M1.1, M1.2
CURRENT_PHASE: Backup & Migration M1.3
CURRENT_WORK_PACKAGE: ERP-BACKUP-MIGRATION-M1-3-GITHUB-RELEASE-001
PACKAGE_STATUS: IMPLEMENTATION_COMPLETE / VERIFIED / READY_FOR_GIT_DELIVERY
WAITING_PHASES: M1.4 restore, M1.5 scheduling, live GitHub publication UAT pending Owner authorization/runtime token
BASELINE: origin/main@f65f663528241e7933f0dbd4568fb041f25488f3
GITHUB_STATE: target repository beyourlovercom/erp-backups EXISTS / PRIVATE per readonly preflight; no GitHub writes authorized
SOURCE_CONFLICTS: NONE WITH EVIDENCE
GLOBAL_GATE: PASS for declared scope; merge/release gates remain Owner-controlled
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE; deployment and migration execution prohibited
NEXT_DEPENDENCY: Git Delivery authorization; isolated live publication UAT remains a separate Owner-authorized gate
EVIDENCE_AS_OF: 2026-09-01

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 6.0 planning hours; range 4.5–8.0
KNOWN_CURRENT_TRACK_REMAINING: M1.4 restore and M1.5 scheduling, estimates not issued here
ARCHITECTURE_GATE_REMAINING: 0 for M1.3 scope after focused/full architecture verification
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE; not re-estimated by this package
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE; source ledger not changed
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: NONE WITH EVIDENCE
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; Work Log not supplied
ESTIMATE_CONFIDENCE: MEDIUM
ESTIMATE_AS_OF: 2026-09-01
ESTIMATE_METHOD: bounded frozen-scope implementation plus required verification

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 1.0
CORE_EXECUTION: 3.0
TEST_AND_VALIDATION: 1.5
REMEDIATION_BUFFER: 0.5
ESTIMATED_EFFECTIVE_HOURS: 6.0
ESTIMATE_RANGE: 4.5–8.0
ESTIMATE_BASIS: frozen M1.3 transport contract and existing M1.2 boundaries
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE
ACTUAL_HOURS_BREAKDOWN: UNKNOWN WITH EVIDENCE
VARIANCE: UNKNOWN WITH EVIDENCE
VARIANCE_REASON: UNKNOWN WITH EVIDENCE
