---
id: ERP-BACKUP-RESTORE-SNAPSHOT-SURVIVABILITY-HOTFIX-001
title: ERP Backup Restore Snapshot Survivability Hotfix
status: IMPLEMENTATION_COMPLETE / VERIFIED / READY_FOR_GIT_DELIVERY
branch: fix/erp-backup-restore-snapshot-survivability-001
base: origin/main@31e300cc1079f9d7b4448f8b67e8308ede86b1bf
EXECUTION_PROVIDER: GPT_WEB_US_MAC_DC
VERIFICATION_TIER: HIGH_RISK
git_delivery: AUTHORIZED_2026-09-02
merge: NOT_AUTHORIZED
production_restore: NOT_AUTHORIZED
---

## Root Cause
Final DR UAT proved that a database restore rewinds `backup_runs` and `backup_artifacts` to the instant captured by mysqldump. A pre-restore snapshot created after the target backup therefore loses its database artifact row after the destructive database restore.

The original rollback executor attempted to query `BackupRepository::artifactsForRun()` after that rewind. The encrypted snapshot file still existed on disk, but its database metadata no longer existed, making automatic rollback unreliable.

## Permanent Fix
- Persist verified pre-restore artifact path, size, SHA256, and key id into the in-memory `BackupRunData.metadata` before destructive restore.
- Require this evidence in `RestoreOrchestrator` before entering the destructive phase.
- Remove post-restore BackupRepository lookup from `SnapshotRestoreRollbackExecutor`.
- Re-validate physical file confinement, size and SHA256 immediately before rollback.

## Verification
- Focused Restore regression: 14/14 PASS, 36 assertions.
- Full Backup regression: 99/99 PASS, 329 assertions.
- Final DR Success Path with byte-identical runtime patch: Completed.
- Final DR automatic Rollback Path with byte-identical runtime patch: RolledBack.
- Rollback post-health: PASS; pending migrations: 0.
- Final DB/storage marker after rollback: `rollback-origin`.
- Runtime patch SHA256 values matched exactly between hotfix and Final DR worktrees.

## Final DR Evidence
Success target SHA256: `c64ab6fa52b69bb949fc6d7eab0184456c294c33a730e55ce73c94580b2a1125`.
Success pre-restore SHA256: `e79ab23638f4c5933fffdd5c390f5f920e699b1e50a7e5d3cd7a78680c6b779d`.
Rollback target SHA256: `336a6c34ffb38b98f730b658719bda37384a6a368d04c799b1ba84c73fa4ed4e`.
Rollback pre-restore SHA256: `889f9fb5622148f8f64934e28bc5518d94e5d76b78b2317e3dbef67f1877c5e0`.

## Architecture Note
Latest main already has inherited frozen Architecture baseline drift from PR #158 (new KOL module/migration file sets). This hotfix does not modify Architecture rules or baseline files and introduces no PROV-001 changes.

## Protected Gates
Git delivery requires explicit authorization. Merge requires separate explicit authorization. No Production or shared-UAT restore is authorized by this WP.
