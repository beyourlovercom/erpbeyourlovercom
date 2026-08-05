---
type: work-package
project: BYL ERP
work_package_id: ERP-ARCH-MIGRATION-001F-C
title: MySQL Two-Connection Concurrency Validation
status: preflight-complete-work-package-formalized-implementation-authorized
updated: 2026-08-05
---

# ERP-ARCH-MIGRATION-001F-C — MySQL Two-Connection Concurrency Validation

## Authorization and Baseline

```text
OWNER_CONTINUOUS_AUTHORIZATION_ID: ERP-ARCH-MIGRATION-001F-CONTINUOUS-20260805
BASE_BRANCH: main
BASE_SHA: a5035cbe0e8d4aed0bf1683c1081b4417f8936be
MYSQL_REFERENCE: MySQL Community 8.4.11
GLOBAL_ARCHITECTURE_GATE: FAIL
MENU_IMPACT: NO
APPROVED_MENU_CHECK: PASS
MVP1: NOT STARTED / 0%
UAT: NO
DEPLOYMENT: NO
```

## Objective

Prove the existing Sync Outbox claim state machine against two distinct concurrent MySQL connections in an isolated disposable MySQL Community 8.4.11 server. This package validates the existing implementation; it does not create business workflows, external operations, a persistent environment or an Orders/Logistics module.

## Isolation Contract

```text
SERVER_BINARY: /opt/homebrew/opt/mysql@8.4/bin/mysqld
SERVER_VERSION_REQUIRED: 8.4.11
DATADIR: fresh mktemp directory outside repository
SOCKET: fresh path inside disposable run root
NETWORK: skip-networking=1; no TCP listener
DATABASE: random disposable database name only
CONNECTIONS: two separately constructed Laravel/PDO MySQL connections
PROHIBITED_TARGETS: existing MariaDB service; repository SQLite; Dev; Staging; Production; shared database
CLEANUP: stop process, remove database, socket, logs and datadir for pass, fail, timeout and signal paths
```

## Read-only Findings

1. `SyncOutboxService::claim()` already uses a transaction, row read-for-update and a conditional state/attempt update with a generated lock token.
2. Existing SQLite evidence proves conditional mutation and sequential duplicate rejection only; it is not real two-connection MySQL evidence.
3. The supported MySQL baseline is MySQL 8.x, with MySQL Community 8.4.11 as the isolated reference; local MariaDB 12.3 is not an acceptable substitute.
4. Current historical migrations and `sync_outbox` schema remain frozen. The disposable database may run them only for validation.

## Implementation Scope

- Add a deterministic MySQL-only integration harness/test that creates two independent connections to the same disposable database and races the same pending outbox row.
- Capture non-sensitive evidence: server version, engine, charset, collation, isolation level, connection IDs and distinct connection identity.
- Assert exactly one claim succeeds; the other gets the existing concurrent/transition rejection; then validate the resulting lock, attempt, retry, fail, complete and stale-recovery contracts.
- Make setup fail closed when the server is not exactly MySQL Community 8.4.11, paths are not disposable, networking is enabled, connection identities collapse, or cleanup cannot be guaranteed.

## Frozen Contracts

```text
SYNC_OUTBOX_SERVICE: existing state machine remains canonical
ROUTES_PERMISSIONS_MENU: unchanged
MIGRATIONS: historical paths and bytes unchanged
EXTERNAL_OPERATION: none
APP_MODULES_ORDERS_LOGISTICS: not created
DEV_STAGING_PRODUCTION: not contacted
```

## Validation

```text
MYSQL_TWO_CONNECTION_HARNESS: exact 8.4.11 / isolated Unix socket / two connection IDs / concurrent claim
SYNC_FOCUSED: php artisan test tests/Feature/Sync
ARCHITECTURE: php artisan test --testsuite=Architecture
FULL_PHPUNIT: ERP_LOCAL_ADMIN_BYPASS=false php artisan test
VITEST: npm test
TYPECHECK: npm run typecheck && npm run typecheck:e2e
BUILD: npm run build
BROWSER_E2E: PLAYWRIGHT_BROWSERS_PATH=<external-versioned-cache> npm run test:e2e
COMPOSER: composer validate --strict
PINT: changed PHP files
INVARIANTS: menu baseline, migration hashes, git diff --check, git fsck --full
```

## Risks and Rollback

- MySQL process setup can accidentally target a shared service; use an absolute server binary, fresh datadir and `skip-networking` only.
- Connection tests can serialize rather than race; require separate connection IDs and a coordinated barrier.
- A real state-machine defect may surface; any code change must remain limited to the Sync claim contract and obtain regression coverage.
- Disposal failure could leave local files or a process; trap all exits and verify removal.

Rollback is a revert of the implementation squash commit. No persistent schema or data rollback exists because only an isolated disposable database is allowed.

## Acceptance Criteria

1. MySQL Community 8.4.11 is proven through the isolated server, not MariaDB or a shared environment.
2. Two distinct connections concurrently contend for one pending row and exactly one obtains it.
3. Resulting status, attempt count, unique lock token and transition protections preserve the current Sync Outbox contract.
4. Retry, fail, complete, stale recovery and audit rollback contracts remain regression-tested.
5. No route, permission, menu, migration, shared database, Dev, Staging, Production, UAT, deployment or MVP1 change occurs.
6. The package remains `GLOBAL_ARCHITECTURE_GATE: FAIL` until later Redis/Queue/Scheduler, Dev Migration and final re-audit packages close.

## Project Position

```text
CURRENT_PHASE: ERP-ARCH-MIGRATION-001F-C READ-ONLY PREFLIGHT / WORK PACKAGE
PACKAGE_STATUS: PREFLIGHT COMPLETE / WORK PACKAGE FORMALIZED / IMPLEMENTATION AUTHORIZED
PREDECESSOR: B5 COMPLETE / PASS / CLOSED through Closure PR #37 at main a5035cbe0e8d4aed0bf1683c1081b4417f8936be
NEXT_DEPENDENCY: independent C Work Package review and merge
GLOBAL_GATE: FAIL
MVP1: NOT STARTED / 0%
```
