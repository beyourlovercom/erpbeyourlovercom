---
id: ERP-BACKUP-MIGRATION-M1-6-RESTORE-OPERATIONALIZATION-001
title: ERP Backup Migration M1.6 — Restore Operationalization
status: IMPLEMENTATION_COMPLETE / VERIFIED / READY_FOR_GIT_DELIVERY
branch: feature/erp-backup-migration-m1-6-restore-operationalization-001
base: origin/main@0a9ab219ac7fa80dfcde1958350503d8206c92d8
EXECUTION_PROVIDER: GPT_WEB_US_MAC_DC
VERIFICATION_TIER: HIGH_RISK
implementation_authorization: OWNER_NEXT_STEP_2026-09-02
git_delivery: OWNER_AUTHORIZED_2026-09-02
git_delivery_authorization: OWNER_EXPLICIT_COMMIT_PUSH_CREATE_PR_2026-09-02
real_restore_uat: NOT_AUTHORIZED
shared_uat_mutation: NOT_AUTHORIZED
production_restore: NOT_AUTHORIZED
production_deploy: NOT_AUTHORIZED
---

## Objective
Turn the M1.4 Restore Foundation contracts into concrete runtime implementations while keeping restore externally unreachable until Final DR UAT is separately authorized.

## Frozen Scope
- Concrete MySQL database restore from validated `database.sql.gz`.
- Concrete storage restore from validated `storage.tar.zst` for exactly `storage/app/private` and `storage/app/public`.
- Mandatory local `pre_restore` snapshot creation and checksum verification.
- Post-restore schema update, health gate, and snapshot rollback executor.
- Provider bindings only; no restore HTTP route, UI action, scheduler, or public Artisan restore command.

## Implementations
- `MysqlDatabaseRestorer`: argv/proc_open MySQL restore; password only via `MYSQL_PWD`; gzip streamed to stdin; no shell command composition.
- `ZstdTarStorageRestorer`: decompression/list validation into isolated staging; rejects traversal, links, unsupported entries, scope drift, and symlinked target parents; swaps only the two frozen storage roots.
- `LocalPreRestoreSnapshotter`: creates `BackupType::PreRestore`, executes the existing backup engine, and verifies one encrypted artifact, workspace confinement, size, suffix, and SHA256.
- `ArtisanPostRestoreSchemaUpdater`: purges the restored connection, runs forced non-interactive migrations, then purges again.
- `RuntimeRestoreHealthChecker`: MySQL probe, migration-table/pending-migration gate, and persistent-root health checks.
- `SnapshotRestoreRollbackExecutor`: checksum → decrypt → extract → rollback-specific package validation → DB/storage restore → schema update → health check.

## Rollback Compatibility Safety
Normal restore keeps exact git SHA, DB engine, latest migration, and migration-count compatibility.
Rollback snapshot validation keeps package/checksum/format/git SHA/DB engine validation but intentionally does not read migration ledger from the already-failed/destructively modified database. The rollback package must be `backup_type=pre_restore`.

## Provider Governance
Existing PROV-001 forbids provider-facing `Migration` tokens. The original internal `RestoreMigrationRunner` contract was renamed to `PostRestoreSchemaUpdater`, with implementation `ArtisanPostRestoreSchemaUpdater`. No architecture rule was weakened.

## External Trigger Gate
- Restore HTTP routes: 0.
- Restore UI actions: 0.
- Public restore Artisan commands: 0.
- Real destructive restore remains impossible through normal product surfaces in M1.6.

## Verification Evidence
- Focused Restore M1.4 + M1.6 regression: `43/43 PASS`, `124 assertions`.
- Full Backup unit + feature regression: `98/98 PASS`, `327 assertions`.
- Initial two full-regression failures were only missing Vite manifest in the fresh worktree; a temporary latest-main `public/build` fixture was used, tests passed, and `public/build` was removed afterward.
- Real synthetic Storage round-trip on US Mac: archive → corrupt both frozen roots → restore → original private/public contents recovered; PASS after final safety hardening.
- PHP lint: `15` changed/untracked PHP files, `0` failures.
- Changed-file secret scan: `0` hits.
- `git diff --check`: PASS.
- Dependency manifests: unchanged.
- Tooling available: `/opt/homebrew/bin/mysql`, `/opt/homebrew/bin/mysqldump`, `/opt/homebrew/bin/zstd`, `/usr/bin/tar`.

## Architecture Evidence
- M1.6 frozen module inventory: aligned.
- M1.6 PROV-001: `0` violations.
- Architecture suite remaining failures are inherited main debt only: `14` APP-001 records plus the existing FE-001 frozen hash drift for `GlobalFeedbackIsland.test.ts`.
- No architecture rule was weakened or bypassed.

## Not Yet Executed
- No real database restore.
- No real rollback against a MySQL database.
- No shared-UAT mutation.
- No Production restore/deploy.
These remain Final DR UAT protected actions after M1.6 Git delivery and merge.
