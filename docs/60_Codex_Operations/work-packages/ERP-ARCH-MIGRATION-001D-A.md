---
type: byl-erp-work-package
project: BYL ERP
work_package: ERP-ARCH-MIGRATION-001D-A
status: merged
standard_id: LARGE-PROJECT-EXEC-001
issued_standard_version: 1.0
document_governance_version: 1.1
---

# ERP-ARCH-MIGRATION-001D-A — Runtime Foundation

## Work Package

```text
ID: ERP-ARCH-MIGRATION-001D-A
TITLE: Runtime Foundation
STATUS: MERGED / PASS
OWNER_IMPLEMENTATION_AUTHORIZATION: COMPLETE
MIGRATION_FILE_AUTHORING: NO
MIGRATION_EXECUTION: NO
DEPENDENCY_CHANGE: NO
MERGE_AUTHORIZATION: COMPLETE
DEPLOYMENT_AUTHORIZATION: NO
BASE_SHA: 652f339c52e0fd2b820d059d55dae39c1f493ee7
BRANCH: feature/erp-arch-migration-001d-a-runtime-foundation
PR: #9
ACCEPTED_HEAD: 3d8f92b701370db41bed0df707183207f6924dd5
MAIN_SQUASH_COMMIT: 50803c3fcd51e9f8b3485f4fb57565154a284bf7
MERGED_AT: 2026-08-01T05:02:42Z
```

## Completion

```text
Redis Cache default: ESTABLISHED
Redis Queue default: ESTABLISHED
Session driver: DATABASE / UNCHANGED
Queue names: default, runtime
Redis connect timeout: 2.0
Redis read timeout: 6.0
Redis queue block_for: 5
Job timeout: 60
Redis queue retry_after: 90
Protected Runtime Health endpoint: COMPLETE
Runtime Health CLI command: COMPLETE
Five-minute Scheduler event: COMPLETE
Redacted degraded behaviour: PASS
LIVE_REDIS_PROBE: UNAVAILABLE / NOT CLAIMED
Migration file: NONE
Migration execution: NOT RUN
Dependency change: NONE
Audit Foundation: NOT IMPLEMENTED
Sync Outbox: NOT IMPLEMENTED
External API: NONE
Deployment: NO
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

## PROJECT POSITION

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Manual Fulfillment / Tencent Docs Replacement → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Customer / Lead Pilot → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Dev UAT and Production Hardening
CURRENT_TRACK_PHASE_MAP: 001A MERGED / PASS → 001B MERGED / PASS → 001C MERGED / PASS → 001D-A MERGED / PASS → 001D-B CURRENT / PREPARED / IMPLEMENTATION NOT STARTED → 001D-C WAITING → 001E WAITING → 001F WAITING
COMPLETED_PHASES: 001A, 001B, 001C, 001D-A
CURRENT_PHASE: 001D-B Audit Foundation preparation
CURRENT_WORK_PACKAGE: ERP-ARCH-MIGRATION-001D-A
PACKAGE_STATUS: MERGED / PASS
WAITING_PHASES: 001D-C, 001E, 001F
BASELINE: main@50803c3fcd51e9f8b3485f4fb57565154a284bf7
GITHUB_STATE: PR #9 MERGED / CLOSED; accepted Head 3d8f92b701370db41bed0df707183207f6924dd5
SOURCE_CONFLICTS: GitHub confirms 001D-A merged; main transition documents remain stale until PR #10 merges; this rebased PR contains the pending 001D-B preparation transition
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: Owner reviews the prepared 001D-B package and separately authorizes implementation if accepted
EVIDENCE_AS_OF: 2026-08-01; merged PR #9, accepted local runtime validation, origin/main and PR #10 transition evidence
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: 15h planning value; 12–18h range; historical 001D-A total estimate
KNOWN_CURRENT_TRACK_REMAINING: 23–36h; planning value 30h; 001D-B + 001D-C only
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE; 001E and 001F lack frozen estimates
MVP1_TOTAL_ESTIMATE: 122–190h total estimate
MVP1_PLANNING_BASELINE: 166h total planning value
MVP1_MAXIMUM_ENVELOPE: 192h maximum total envelope
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; accepted actual-hours ledger and overlap map are incomplete
POST_MVP_KNOWN_INCREMENT: approximately 68–120h incremental beyond MVP1
LONG_TERM_CUMULATIVE_RANGE: 235–402h through 1,819–3,146h; cumulative non-additive horizons
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; cumulative horizons and project-wide actual ledger are incomplete
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; accepted project-wide Work Log ledger is incomplete
ESTIMATE_CONFIDENCE: HIGH for quoted totals; MEDIUM for 001D-B/C sum; LOW / UNKNOWN for remaining totals
ESTIMATE_AS_OF: 2026-08-01
ESTIMATE_METHOD: repository classification; no unsafe subtraction; no cumulative or overlapping aggregation
```

## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: 1.5-2.5 hours (planning value 2 hours)
CORE_EXECUTION: 6-9 hours (planning value 7.5 hours)
TEST_AND_VALIDATION: 3-4.5 hours (planning value 3.5 hours)
REMEDIATION_BUFFER: 1.5-2 hours (planning value 2 hours)
ESTIMATED_EFFECTIVE_HOURS: 15 hours
ESTIMATE_RANGE: 12-18 effective hours
ESTIMATE_BASIS: planning values 2 + 7.5 + 3.5 + 2 hours; Redis Cache/Queue defaults, typed queue contract, shared health service, protected endpoint, CLI command, scheduler registration and isolated regression testing
ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; the accepted 001D-A Work Log does not record productive actual hours
ACTUAL_HOURS_BREAKDOWN: UNKNOWN WITH EVIDENCE; no accepted component-level actual-hours record exists
VARIANCE: UNKNOWN WITH EVIDENCE; actual hours are unavailable, so hours and percentage cannot be calculated safely
VARIANCE_REASON: Historical merged package remains valid under its issued standard; v1.1 does not permit inference from PR elapsed time, commits, tokens or chat memory
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```
