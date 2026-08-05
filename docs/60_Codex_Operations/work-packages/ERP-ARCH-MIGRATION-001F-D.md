---
type: work-package
project: BYL ERP
work_package_id: ERP-ARCH-MIGRATION-001F-D
title: Redis / Queue / Scheduler Runtime Validation
status: preflight-complete-work-package-formalized-implementation-authorized
updated: 2026-08-05
---

# ERP-ARCH-MIGRATION-001F-D — Redis / Queue / Scheduler Runtime Validation

## Authorization and Baseline

```text
OWNER_CONTINUOUS_AUTHORIZATION_ID: ERP-ARCH-MIGRATION-001F-CONTINUOUS-20260805
BASE_SHA: 63d1d2d3923d531b032d092eeb87d792f82eabaa
GLOBAL_ARCHITECTURE_GATE: FAIL
MENU_IMPACT: NO
APPROVED_MENU_CHECK: PASS
MVP1: NOT STARTED / 0%
```

## Objective

Validate the existing Redis runtime health, real Redis queue worker lifecycle and existing bounded scheduler event using a disposable repository-external Redis instance. No real email, external provider, business workflow, persistent environment or database target is allowed.

## Isolation Contract

```text
REDIS_BINARY: /opt/homebrew/opt/redis/bin/redis-server
REDIS_SERVER: fresh external mktemp directory, random loopback port, protected mode, persistence disabled
REDIS_PREFIX: random byl_001fd_* prefix
DATABASE: phpunit in-memory SQLite only
PROHIBITED_TARGETS: shared Redis, Dev, Staging, Production, external API, real mail
CLEANUP: stop server and worker; delete external run directory on every exit
```

## Read-only Findings

1. Runtime health already checks Redis default/cache reachability and validates Redis queue conventions.
2. The repository has no Redis binary or service configured; Redis 8.10 is now installed locally but not started as a service.
3. The only scheduler event is the bounded `runtime:health --quiet --no-interaction` every-five-minutes event with overlap protection and one-server contract.
4. No deterministic queued job exists. D may add one test-only Runtime job and harness to prove worker reserve, execute, acknowledge, retry and shutdown without business effects.

## Scope and Acceptance Criteria

- Start only a disposable external Redis instance and demonstrate cache set/get/delete plus redacted health result.
- Add the minimum deterministic Runtime-only job/harness required to prove dispatch, reserve, execute, acknowledge, retry/failure, timeout convention and worker cleanup.
- Invoke the actual Laravel scheduler against the existing event and prove due discovery/execution without adding business schedule entries.
- Preserve routes, menu, permissions, migrations, database schema, module ownership and all production configuration defaults.
- Keep Global Architecture Gate `FAIL` until E Dev Migration and F final re-audit complete.

## Validation

```text
RUNTIME_HARNESS: isolated Redis, cache, health, worker and scheduler lifecycle
FOCUSED: tests/Feature/Runtime tests/Unit/Runtime
FULL_PHPUNIT: ERP_LOCAL_ADMIN_BYPASS=false php artisan test
VITEST_TYPECHECK_BUILD_BROWSER_E2E_COMPOSER_PINT_DIFF_FSCK: required
MIGRATION: NO
REAL_DATABASE: NO
UAT: NO
DEPLOYMENT: NO
```

## Project Position

```text
CURRENT_PHASE: ERP-ARCH-MIGRATION-001F-D READ-ONLY PREFLIGHT / WORK PACKAGE
PACKAGE_STATUS: PREFLIGHT COMPLETE / WORK PACKAGE FORMALIZED / IMPLEMENTATION AUTHORIZED
PREDECESSOR: C COMPLETE / PASS / CLOSED through Closure PR #40 at main 63d1d2d3923d531b032d092eeb87d792f82eabaa
NEXT_DEPENDENCY: independent D Work Package review and merge
GLOBAL_GATE: FAIL
MVP1: NOT STARTED / 0%
```
