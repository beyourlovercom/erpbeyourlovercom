---
id: ERP-BACKUP-MIGRATION-M1-0-CONTRACT-FOUNDATION-001
title: ERP Backup & Migration M1.0 — Contract Foundation
status: IMPLEMENTATION_AUTHORIZED / IN_PROGRESS
branch: feature/erp-backup-migration-m1-0-contract-foundation-001
target_base: main@ef5b62d6ce1c082ecccf2ffc4e30680a2af3d731
EXECUTION_PROVIDER: US_CODEX_CLI
VERIFICATION_TIER: HIGH_RISK
implementation_authorization: OWNER_NEXT_STEP_2026-09-01
git_delivery: NOT_AUTHORIZED
merge: NOT_AUTHORIZED
migration_execution: NOT_AUTHORIZED
production_deployment: NOT_AUTHORIZED
---

# Goal

Extract the shared Backup & Migration contract required by M1.1 UI, M1.2 Backup Engine, M1.3 GitHub Release and M1.4 Restore before those packages implement in parallel.

This package is foundation only. It must not create backups, restore data, publish GitHub releases, expose secrets, or add the Backup UI.

# Authority / Frozen Decisions

Source: Owner-approved ERP Backup & Migration handoff dated 2026-09-01.

Frozen decisions:
- backup format version is `backup-format-v1` / integer version `1`;
- full backup payload contains database dump plus persistent storage;
- persistent storage scope is `storage/app/private` and `storage/app/public` only;
- backup workspace is outside both included storage roots;
- encryption algorithm is `AES-256-GCM`;
- encryption key is runtime-secret-only and never committed, persisted in ERP DB, placed in manifests, or rendered in UI;
- `.env` and production credentials are never backup payload components;
- canonical encrypted asset suffix is `.tar.zst.enc`;
- GitHub backup repository default target is private `beyourlovercom/erp-backups`;
- GitHub integration uses an application HTTP adapter/runtime token, not a production dependency on local `gh` CLI;
- restore requires checksum, manifest and compatibility validation plus Pre-Restore Snapshot before destructive execution.

# Shared Package Scope

1. Register the Backup module provider in the modular monolith.
2. Add canonical Backup enums/state values shared by downstream packages.
3. Add `config/backup.php` for non-secret contract values and runtime secret injection points.
4. Freeze manifest/public-manifest field contracts in code.
5. Add the seven approved authorization keys:
   `backup.view`, `backup.create`, `backup.download`, `backup.publish_github`, `backup.delete`, `backup.restore`, `backup.manage`.
6. Register the permissions for existing deployments and attach them to `role_preset_administrator` when that role exists.
7. Add focused tests for enums/contracts/config/authorization migration behavior.
8. No menu or route changes in M1.0; those belong to M1.1.

# Canonical Package Layout

Internal unencrypted package contents:

```text
backup-format-v1/
├── database.sql.gz
├── storage.tar.zst
├── manifest.json
└── checksums.sha256
```

Encrypted transport artifact:

```text
byl-erp-{type}-YYYYMMDD-HHMMSS.tar.zst.enc
```

Public GitHub companion assets may later include `manifest.public.json` and `checksums.sha256`; M1.0 only freezes their safe field contract.

# Manifest V1 Required Fields

Private `manifest.json` must require:
- `application`
- `backup_format_version`
- `backup_type`
- `created_at`
- `git_sha`
- `database_engine`
- `database_version`
- `latest_migration`
- `migration_count`
- `encrypted`
- `encryption_algorithm`
- `encryption_key_id`
- `storage_included`
- `database_dump_size`
- `storage_archive_size`
- `components`
- `environment_classification`
- `creator_source`

Public manifest allowlist is strictly:
`application`, `backup_type`, `created_at`, `git_sha`, `format_version`, `encrypted`.

No secret, credential, user/customer data or sensitive internal path may enter the public manifest.

# Canonical Enum Values

Backup types: `database`, `full`, `pre_restore`.

Backup states:
`queued`, `dumping`, `packaging`, `checksumming`, `encrypting`, `ready`, `publishing`, `verified`, `failed`, `deleted`.

Restore states:
`uploaded`, `downloading`, `verifying`, `decrypting`, `compatible`, `snapshotting`, `restoring`, `migrating`, `health_checking`, `completed`, `failed`, `rolled_back`.

# Parallel Dependency Declaration

BASELINE: `origin/main@ef5b62d6ce1c082ecccf2ffc4e30680a2af3d731`
DEPENDENCIES: NONE
DEPENDENCY_TYPE: NONE
DEPENDENCY_STATUS: SATISFIED
PARALLEL_SAFE: YES as the only active implementation stream until foundation merge
FOUNDATION_CHANGE_REQUIRED: YES / THIS PACKAGE

Downstream M1.1–M1.4 remain implementation-waiting until this foundation is merged, unless Owner separately authorizes stacked dependencies.

# Prohibitions

- No UI, navigation or HTTP action routes.
- No backup/restore execution.
- No GitHub API calls or repository creation.
- No plaintext backup asset creation as acceptance evidence.
- No `.env`, APP_KEY, DB password, connector secret, OAuth secret or encryption key in tracked files/tests/log output.
- No production migration execution.
- No commit/push/PR without separate Git delivery authorization.
- No merge or production deployment.

# Acceptance Criteria

1. Backup module provider is discoverable through `ModuleManifest`.
2. Canonical enums exactly match this Work Package.
3. Config defaults match the frozen contract and expose runtime secret injection points without hardcoded secrets.
4. Included persistent storage roots exclude the backup workspace itself.
5. Manifest contract and public allowlist are test-covered.
6. All seven permissions exist in `AuthorizationKeys` and `PermissionCatalog`.
7. Permission-registration migration is idempotent and administrator-safe.
8. Focused PHP tests pass; architecture/module-provider tests pass.
9. No secret-like value is introduced in the diff.

# Validation Contract

Run on US Mac in this worktree:
- focused PHPUnit tests for Backup contract and permission migration;
- `tests/Architecture/ModuleProviderTest.php`;
- `tests/Feature/AuthorizationFoundationTest.php` or the closest authorization regression suite;
- `php artisan test` if focused gates expose cross-module risk;
- secret-pattern diff review;
- `git diff --check`.

No local/UAT destructive backup or restore operation is part of M1.0.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate [COMPLETE] → ERP-INQUIRY-M1 M1.0 Contract + M1.1 Company [COMPLETE] → ERP-ORDER-FULFILLMENT-M1 [COMPLETE] → Product Master / SKU M1 [COMPLETE] → Order Create/Edit V2 [COMPLETE] → Inventory Foundation + Inventory Batch Sync M1 [COMPLETE] → MVP1 Manual Fulfillment Integration [COMPLETE] → MVP1.1 WooCommerce Inbound [COMPLETE] → MVP1.2 Tracking Outbound [COMPLETE] → MVP1.3 Sync Orchestration [COMPLETE] → KOL M1.0 Foundation [COMPLETE] → KOL M1.1 CRM [COMPLETE] → ROADMAP RECONCILIATION [repository doc historical marker] → KOL completion track → MVP2–MVP4 → Accounting / Procurement → remaining Inquiry milestone → Remote Release → Product / Media / Content / SEO / Shopify → Production Hardening
CURRENT_TRACK_PHASE_MAP: M1.0 Contract Foundation → M1.1 UI → M1.2 Backup Engine → M1.3 GitHub Release → M1.4 Restore → M1.5 Scheduled Backup → Integration UAT / DR UAT
COMPLETED_PHASES: Backup design / Owner UI direction / read-only repository preflight
CURRENT_PHASE: M1.0 Contract Foundation
CURRENT_WORK_PACKAGE: ERP-BACKUP-MIGRATION-M1-0-CONTRACT-FOUNDATION-001
PACKAGE_STATUS: IMPLEMENTATION_AUTHORIZED / IN_PROGRESS
WAITING_PHASES: M1.1 UI / M1.2 Backup Engine / M1.3 GitHub Release / M1.4 Restore / M1.5 Scheduled / Integration UAT
BASELINE: origin/main@ef5b62d6ce1c082ecccf2ffc4e30680a2af3d731
GITHUB_STATE: branch local only / no commit / no push / no PR
SOURCE_CONFLICTS: repository roadmap/current-context still contains older KOL sequencing markers; current Backup program authority is the Owner-approved 2026-09-01 handoff plus latest fetched GitHub main. No arithmetic is derived from stale phase labels.
GLOBAL_GATE: COMMON_FOUNDATION_EXTRACTION_GATE ACTIVE / M1.1–M1.4 implementation waits for M1.0 merge unless Owner authorizes stacked dependencies
PRODUCTION_DEPLOYMENT_STATUS: NO; no production mutation authorized or executed
NEXT_DEPENDENCY: complete M1.0 implementation + verification, then separate Git delivery/merge authorization before downstream rebasing
EVIDENCE_AS_OF: 2026-09-01 Asia/Singapore

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: planning value 4.5h; range 3–6h
KNOWN_CURRENT_TRACK_REMAINING: downstream M1.1–M1.5 + Integration UAT = 50–82h from Owner handoff, excluding this M1.0 package
ARCHITECTURE_GATE_REMAINING: 0h / complete per repository planning source
MVP1_TOTAL_ESTIMATE: 122–190h total estimate; not remaining
MVP1_PLANNING_BASELINE: 166h total planning value
MVP1_MAXIMUM_ENVELOPE: 192h maximum total planning envelope
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; accepted project-wide actual ledger and overlap map remain incomplete
POST_MVP_KNOWN_INCREMENT: approximately 68–120h beyond MVP1 per repository planning source
LONG_TERM_CUMULATIVE_RANGE: Architecture 235–402h; first highly automated operations MVP 603–1,018h; Product/SEO 1,059–1,802h; Full core ERP 1,595–2,714h; All expansion 1,819–3,146h; cumulative horizons are not additive
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; long-term ranges are cumulative and current accepted actual ledger is incomplete
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; no accepted Backup M1.0 Work Log exists yet
ESTIMATE_CONFIDENCE: MEDIUM-HIGH for bounded foundation scope
ESTIMATE_AS_OF: 2026-09-01
ESTIMATE_METHOD: bottom-up bounded implementation + high-risk authorization verification buffer

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.5–1h; planning 0.75h
CORE_EXECUTION: 1.5–3h; planning 2.25h
TEST_AND_VALIDATION: 0.5–1h; planning 0.75h
REMEDIATION_BUFFER: 0.5–1h; planning 0.75h
ESTIMATED_EFFECTIVE_HOURS: 4.5h planning value
ESTIMATE_RANGE: 3–6h
ESTIMATE_BASIS: frozen shared contract, one module provider/config, enum/manifest primitives, seven authorization keys, one idempotent permission-registration migration, focused tests
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
