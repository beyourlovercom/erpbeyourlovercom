---
id: ERP-BACKUP-MIGRATION-M1-2-BACKUP-ENGINE-001
title: ERP Backup & Migration M1.2 — Backup Engine
status: IMPLEMENTATION_COMPLETE / VERIFIED / READY_FOR_GIT_DELIVERY
branch: feature/erp-backup-migration-m1-2-backup-engine-001
base: 9ac9b76f3d42a7109bed1df4b76f42c547cd82ea
target_base: main@9ac9b76f3d42a7109bed1df4b76f42c547cd82ea
EXECUTION_PROVIDER: US_CODEX_CLI
VERIFICATION_TIER: HIGH_RISK
implementation_authorization: OWNER_NEXT_STEP_2026-09-01
git_delivery: OWNER_AUTHORIZED_2026-09-01
merge: NOT_AUTHORIZED
migration_execution: NOT_AUTHORIZED
production_deployment: NOT_AUTHORIZED
---

# Scope

M1.1 UI/menu merged before M1.2 architecture closeout at `9ac9b76f3d42a7109bed1df4b76f42c547cd82ea` (PR #147). This package preserves that merged baseline and governs only the new Backup engine files and migration listed by the architecture test.

This package owns backup persistence and the queued backup engine only. It does not modify UI, Vue, menu, navigation config, routes, controllers, GitHub transport, restore execution, or scheduling. `BackupServiceProvider` is changed only for the minimal `BackupRepository` → `EloquentBackupRepository` container binding required by the APP-001-compliant application boundary.

## Format and technical choices

The frozen internal format contract is `config('backup.format.name') = backup-format-v1`; the unencrypted working package contains `database.sql.gz`, `storage.tar.zst`, `manifest.json`, and `checksums.sha256`. The package is compressed as tar+zstd and then encrypted to an external asset named `byl-erp-{type}-YYYYMMDD-HHMMSS[-n].tar.zst.enc`. Database dumps use `mysqldump --single-transaction --quick --routines --triggers`; storage archives are restricted to the two M1.0 roots and are never sourced from the workspace.

The encrypted container is a streaming `BYLENC01` format: an 8-byte magic, big-endian JSON-header length, JSON header (version, AES-256-GCM, chunk size, authenticated plaintext size and chunk count, random 8-byte nonce prefix, and non-secret key ID), followed by records containing chunk index, ciphertext length, ciphertext and a 16-byte GCM tag. Each nonce is `prefix || uint32(chunk_index)` and AAD is `header || uint32(index) || uint32(length)`. Header arithmetic and sequential chunk indices are validated; zero-length input is rejected because a zero-chunk container has no authenticated finalization tag. Decrypt verifies exact record count and plaintext byte count, rejects trailing/truncated/reordered/duplicated records, and bounds chunk allocation. `openssl enc` and CBC are not used.

## Acceptance criteria and evidence

## Architecture closeout — 2026-09-01

`APP-001` closeout result: PASS for Backup/M1.2. `BackupEngineService`, `BackupArtifactCleanupService`, and the queued job now use application DTOs and `Application\Contracts\BackupRepository`; Eloquent `BackupRun`/`BackupArtifact` access is owned by `Infrastructure\EloquentBackupRepository`, bound in `BackupServiceProvider`. No Backup/M1.2 `APP-001` records remain. No whitelist, suppression, or baseline weakening was added.

The full `ModuleArchitectureTest` still reports only the 12 pre-existing non-Backup `APP-001` records in Audit, Customers, Logistics, Shell, and Support: `Audit/CustomerActivityProjection`, six imports in `Customers/CustomerImportService`, `Logistics/CustomerShipmentProjection`, two imports in `Shell/UserUiPreferenceService`, and two Support projection/service imports. These are pre-existing repository debt and were not changed by this closeout. There are zero Backup/M1.2 records and zero M1.2 directory/file/migration baseline failures.

- Independent focused Backup M1.0/M1.1/M1.2 tests plus `ModuleProviderTest`: 31 passed, 122 assertions.
- Independent full `ModuleArchitectureTest`: 8 tests / 523 assertions, with 1 failure + 1 risky test only from the 12 pre-existing non-Backup `APP-001` records; Backup/M1.2 APP-001 = 0 and M1.2 module directory/file/migration baseline failures = 0. PHP lint passed, `git diff --check` passed, dependency manifests are unchanged, and literal-secret scan found no private-key/token literals.

- migrations define `backup_runs` and `backup_artifacts` with state/type, lifecycle errors, `encrypted`, final encrypted artifact SHA-256 in `checksum`, explicit internal `package_checksum`, path, size and key ID, without executing them;
- models/services cover database/full/pre-restore creation, queued-only execution (intermediate states are not advertised as resumable), state transitions including Ready → Publishing for M1.3, sanitized failures and immutable audit lifecycle records including `backup.ready`;
- repository-boundary closeout preserves `started_at` from the first transition rather than overwriting it on later transitions; sanitized `error_code` / `error_message` are exposed through `BackupRunData` for verification without Application→Infrastructure coupling;
- adapters isolate `mysqldump`, tar/zstd, and encryption I/O;
- manifests contain only contract metadata; keys and customer data are not persisted;
- focused tests cover checksum ordering/correctness, authenticated multi-chunk encryption round trip/tamper/truncation/malformed-record rejection, zero-length rejection, symmetric workspace-overlap rejection, state idempotency/Ready transition, queued-only execution, sanitized failure cleanup and final-asset confinement;
- final verification includes focused PHPUnit, architecture/provider tests, `git diff --check`, and secret-pattern review.

## Limitations

The engine currently assumes the runtime has `mysqldump`, `tar`, and `zstd`; their paths are configurable runtime tooling values. Database metadata gracefully reports a null version only when the version query is unavailable. M1.3 transport, M1.4 restore, M1.5 scheduling, UI exposure, and migration execution remain outside this package.

No commit, push, PR, merge, deployment, or production migration is authorized or performed for this package.
