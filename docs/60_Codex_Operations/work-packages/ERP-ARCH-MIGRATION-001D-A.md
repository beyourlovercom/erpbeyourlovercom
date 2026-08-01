---
type: byl-erp-work-package
project: BYL ERP
work_package: ERP-ARCH-MIGRATION-001D-A
status: implementation-authorized
standard_id: LARGE-PROJECT-EXEC-001
---

# ERP-ARCH-MIGRATION-001D-A — Runtime Foundation

## Work Package

```text
ID: ERP-ARCH-MIGRATION-001D-A
TITLE: Runtime Foundation
STATUS: IMPLEMENTATION AUTHORIZED / IN PROGRESS
OWNER_IMPLEMENTATION_AUTHORIZATION: YES
MIGRATION_FILE_AUTHORING: NO
MIGRATION_EXECUTION: NO
DEPENDENCY_CHANGE: NO
MERGE_AUTHORIZATION: NO
DEPLOYMENT_AUTHORIZATION: NO
BASE_SHA: 652f339c52e0fd2b820d059d55dae39c1f493ee7
BRANCH: feature/erp-arch-migration-001d-a-runtime-foundation
```

## Frozen Scope

- Redis configuration validation and Redis Cache/Queue defaults;
- typed `default` and `runtime` queue-name contract plus bounded future Job conventions;
- bounded Redis Queue retry, blocking and after-commit behaviour;
- shared redacted runtime health service;
- protected internal JSON health endpoint and CLI command;
- every-five-minute Scheduler event with overlap and single-server protection;
- isolated automated tests and bounded local observation.

## Explicit Exclusions

- Audit Foundation and Sync Outbox Foundation;
- Order, Shipment, Sync UI and other business implementation;
- external providers or API calls;
- Migration authoring or execution;
- dependency, Session schema or Session driver changes;
- deployment, production cron and worker configuration.

## Expected Files

```text
.env.example
bootstrap/app.php
config/cache.php
config/database.php
config/queue.php
routes/web.php
routes/console.php
app/Console/Commands/RuntimeHealthCommand.php
app/Http/Controllers/RuntimeHealthController.php
app/Support/Runtime/QueueJobConvention.php
app/Support/Runtime/QueueName.php
app/Support/Runtime/RedisClientCapability.php
app/Support/Runtime/RedisRuntimeProbe.php
app/Support/Runtime/RuntimeHealthService.php
tests/Unit/Runtime/RuntimeHealthServiceTest.php
tests/Feature/Runtime/RuntimeFoundationTest.php
docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001D-A.md
```

`config/database.php` is strictly required to bound Redis connection and read timeouts for the health probe. Maximum changed files: 18.

## Acceptance Criteria

- configured environments default Cache and Queue to Redis while Session remains database;
- PHPUnit remains isolated with array Cache, sync Queue and array Session;
- queue names are declared, unique and non-empty;
- health checks report stable redacted status codes for configuration, client and connections;
- endpoint authorization and 200/503 behaviour match the contract;
- CLI and HTTP use the same service;
- Scheduler registration is bounded and introduces no business schedule;
- all validation passes without live Redis being required;
- no Migration, dependency, Audit, Sync Outbox or deployment changes.

## Test Matrix

- configuration defaults, isolation and bounded queue contract;
- healthy, unavailable, unsupported and misconfigured health results;
- exception and credential redaction;
- guest, unauthorized, permitted and super-admin endpoint access;
- healthy/degraded CLI exit and output;
- Scheduler expression, event name, overlap and single-server mutex;
- `/up`, authentication, authorization and Application Shell regression.

## Failure Rules

Redis or configuration failures produce `degraded` with fixed non-secret codes. Health probes never mutate Redis. Missing local Redis capability is reported as unavailable and never triggers installation, dependency changes or a false PASS.

## Validation Commands

```text
composer validate --no-check-publish
php artisan route:list
php artisan schedule:list
php artisan test --compact
npm run test
npm run typecheck
npm run build
git diff --check
```

Migration, dependency installation, worker startup and deployment commands are prohibited.

## Final Report Contract

Report the accepted base, branch/head/PR, Redis capabilities, Cache/Queue/Session contracts, health/endpoint/CLI/Scheduler results, all test and build results, exact diff, prohibited-scope status, clean worktree and only the authorized successful or blocked verdict.

## Project Position

```text
PHASE_MAP:
001A Foundation Bootstrap             MERGED / PASS
001B Authentication / Authorization   MERGED / PASS
001C Application Shell                MERGED / PASS
→ 001D Runtime / Audit / Sync         IMPLEMENTATION IN PROGRESS
   → 001D-A Runtime Foundation        OWNER IMPLEMENTATION AUTHORIZED
   001D-B Audit Foundation            WAITING
   001D-C Sync Outbox Foundation      WAITING
001E Shared UI / Browser E2E          WAITING
001F Architecture Re-audit            WAITING
COMPLETED_PHASES: 001A, 001B, 001C
CURRENT_PHASE: 001D-A Runtime Foundation
CURRENT_WORK_PACKAGE: ERP-ARCH-MIGRATION-001D-A
PACKAGE_STATUS: OWNER IMPLEMENTATION AUTHORIZED / MERGE NOT AUTHORIZED
WAITING_PHASES: 001D-B, 001D-C, 001E, 001F
BASELINE: main@652f339c52e0fd2b820d059d55dae39c1f493ee7
GITHUB_STATE: PR #8 MERGED / CLOSED; no 001D-A implementation PR existed at authorization time
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: complete 001D-A, open one bounded PR and return it for independent review
EVIDENCE_AS_OF: 2026-08-01; merged PR #8, frozen 001D Work Package and verified runtime baseline
```

## Estimated Codex Hours

```text
PREFLIGHT_AND_SCOPE: 1.5-2.5 hours (planning value 2 hours)
CORE_EXECUTION: 6-9 hours (planning value 7.5 hours)
TEST_AND_VALIDATION: 3-4.5 hours (planning value 3.5 hours)
REMEDIATION_BUFFER: 1.5-2 hours (planning value 2 hours)
ESTIMATED_EFFECTIVE_HOURS: 15 hours
ESTIMATE_RANGE: 12-18 effective hours
ESTIMATE_BASIS: planning values 2 + 7.5 + 3.5 + 2 hours; Redis Cache/Queue defaults, typed queue contract, shared health service, protected endpoint, CLI command, scheduler registration and isolated regression testing
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```
