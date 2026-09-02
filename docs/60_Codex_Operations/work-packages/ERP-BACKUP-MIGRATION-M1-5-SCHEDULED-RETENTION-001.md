---
id: ERP-BACKUP-MIGRATION-M1-5-SCHEDULED-RETENTION-001
title: ERP Backup & Migration M1.5 — Scheduled Backup & Retention
status: IMPLEMENTATION_COMPLETE / VERIFIED / READY_FOR_GIT_DELIVERY
branch: feature/erp-backup-migration-m1-5-scheduled-retention-001
base: origin/main@6cb7e27e0e68e7c7ad848e48dbfe963369eaf953
target_base: main@6cb7e27e0e68e7c7ad848e48dbfe963369eaf953
EXECUTION_PROVIDER: GPT_WEB_US_MAC_DC
VERIFICATION_TIER: HIGH_RISK
implementation_authorization: OWNER_ENTER_M1_5_2026-09-02
git_delivery: OWNER_AUTHORIZED_2026-09-02
git_delivery_authorization: OWNER_EXPLICIT_COMMIT_PUSH_CREATE_PR_2026-09-02
merge: NOT_AUTHORIZED
migration_execution: NOT_AUTHORIZED
production_scheduler_activation: NOT_AUTHORIZED
real_automatic_backup: NOT_AUTHORIZED
real_retention_delete: NOT_AUTHORIZED
production_deployment: NOT_AUTHORIZED
---

# Scope

M1.5 adds a simple automatic-backup policy to the existing Backup module. The owner can turn automatic backup on or off and choose daily, weekly, or monthly cadence. The system keeps at most five verified automatic backups.
# Frozen Product Behavior

- Retention count is fixed at `5`; there is no user-editable retention-count field.
- Automatic backup defaults to disabled with cadence `daily`.
- Cadence options are exactly `daily`, `weekly`, and `monthly`.
- Default schedule timezone is `UTC`; runtime may override it with `BACKUP_AUTOMATIC_TIMEZONE`.
- Daily runs at 02:00; weekly runs Monday at 02:00; monthly runs on day 1 at 02:00.
- Scheduler entries use `withoutOverlapping` and `onOneServer`.
- Scheduled backups are always `full` backups and are marked with metadata `origin=scheduled` plus cadence.
- The job chain is Create Backup -> Publish GitHub -> Apply Retention.

# Retention Safety

Retention counts only scheduled backups that are `Verified` and have `github_publish_status=published`. Manual backups and `pre_restore` snapshots are excluded. Failed, queued, or publishing runs cannot displace a recoverable older backup.

When a sixth verified scheduled backup exists, cleanup starts with the oldest eligible backup. GitHub Release/tag deletion must succeed and be verified before local encrypted assets are deleted and the local run is marked `Deleted`. Any uncertain remote-delete outcome stops cleanup fail-closed.
# Verification Evidence

- M1.5 targeted backend: 13/13 PASS, 73 assertions.
- Backup M1.0-M1.5 regression plus ModuleProvider: 99/99 PASS, 351 assertions.
- Backup UI interaction tests: 2/2 PASS.
- Vue TypeScript `vue-tsc --noEmit`: PASS.
- Vite production build: PASS; temporary `public/build` fixture removed after PHP rendering tests.
- Scheduler registration verified: `0 2 * * *`, `0 2 * * 1`, `0 2 1 * *`.
- Architecture: M1.5 Backup APP-001 = 0; PROV-001 = 0.
- Full architecture still reports only 14 pre-existing non-Backup APP-001 records on current main.
- `git diff --check`: PASS.
- Dependency manifests: unchanged.
- PHP lint: 27 changed PHP files, 0 failures before WP creation.
- Secret scan: 29 changed text files, 0 hits before WP creation.
- Base drift check: HEAD and `origin/main` both `6cb7e27e0e68e7c7ad848e48dbfe963369eaf953`.

# Non-Actions

No Production migration, scheduler activation, automatic backup, retention deletion, deployment, commit, push, pull request, or merge was executed in M1.5 implementation/verification.