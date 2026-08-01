---
type: byl-erp-work-package
project: BYL ERP
work_package: ERP-ARCH-MIGRATION-001D
status: prepared
standard_id: LARGE-PROJECT-EXEC-001
---

# ERP-ARCH-MIGRATION-001D — Runtime / Audit / Sync Foundation

## Work Package

```text
ID: ERP-ARCH-MIGRATION-001D
TITLE: Runtime / Audit / Sync Foundation
STATUS: PREPARED / IMPLEMENTATION NOT STARTED
OWNER_IMPLEMENTATION_AUTHORIZATION: NO
MERGE_AUTHORIZATION: NO
MIGRATION_AUTHORIZATION: NO
DEPLOYMENT_AUTHORIZATION: NO
```

This file is an implementation-planning contract only. It does not authorize repository changes, application code, schema creation, Migration execution or deployment.

## Frozen Execution Split

Execution order is mandatory:

```text
ERP-ARCH-MIGRATION-001D-A
Runtime Foundation
Status: PREPARED / OWNER IMPLEMENTATION AUTHORIZATION NO
Estimate: 12–18 effective hours (planning value 15 hours)

→ ERP-ARCH-MIGRATION-001D-B
Audit Foundation
Status: WAITING
Estimate: 9–14 effective hours (planning value 12 hours)

→ ERP-ARCH-MIGRATION-001D-C
Sync Outbox Foundation
Status: WAITING
Estimate: 14–22 effective hours (planning value 18 hours)
```

No subpackage becomes authorized merely by this split. Each subpackage requires separate Owner implementation authorization. Migration file authoring, Migration execution, merge and deployment remain separate gates.

### ERP-ARCH-MIGRATION-001D-A — Runtime Foundation

Scope:

- Redis connection and configuration validation;
- Redis-backed cache foundation;
- Redis Queue foundation;
- named queues and job conventions;
- Laravel Scheduler foundation;
- runtime health service;
- authorized internal health endpoint or command;
- safe failure and redaction behaviour;
- isolated automated tests.

Explicit exclusions:

- Audit schema or service changes beyond compatibility inspection;
- `sync_outbox`;
- Order;
- Shipment;
- Sync UI;
- external APIs;
- deployment.

### ERP-ARCH-MIGRATION-001D-B — Audit Foundation

Scope:

- inspect and preserve the 001B `audit_records` foundation;
- additive compatibility changes only;
- one Audit Service boundary;
- actor, source and request correlation;
- before/after metadata;
- sensitive-field rejection;
- immutable audit behaviour;
- system actor contract;
- isolated automated tests.

Explicit exclusions:

- replacement or destructive rewrite of existing audit history;
- Sync Outbox;
- business workflows;
- external APIs;
- deployment.

### ERP-ARCH-MIGRATION-001D-C — Sync Outbox Foundation

Scope:

- generic provider-independent `sync_outbox` schema;
- model and service contract;
- pending, processing, completed and failed states;
- idempotency key;
- bounded attempts and backoff metadata;
- lock and stale-processing recovery contract;
- failure-safe audit linkage;
- isolated automated tests.

Explicit exclusions:

- external provider adapters;
- WooCommerce;
- Shopify;
- actual API calls;
- manual Sync UI;
- batch orchestration;
- Order and Shipment business implementation;
- deployment.

## Frozen Technical Decisions

```text
QUEUE_DRIVER: Redis
CACHE_DRIVER: Redis
SESSION_DRIVER: Preserve current configuration; no forced Redis session migration in 001D.
SCHEDULER: Laravel Scheduler foundation.
LOCAL_SCHEDULER: php artisan schedule:work may be used for isolated local validation.
PRODUCTION_CRON: Contract documentation only; no configuration or deployment in 001D.
HEALTH_CHECK: Runtime health service exposed through CLI and/or a system-permission-protected internal JSON endpoint. No public detailed health data.
AUDIT_SCHEMA: Preserve and incrementally extend the existing 001B audit foundation. No destructive replacement.
SYNC_OUTBOX: Provider-independent infrastructure table and service. It is integration intent, not business truth.
EXTERNAL_API: NO
SYNC_UI: NO
ORDER_AND_SHIPMENT: NO
MIGRATION_FILE_AUTHORING: May be separately authorized inside each implementation subpackage.
MIGRATION_EXECUTION: NO unless separately and explicitly authorized.
DEPLOYMENT: NO
```

## Purpose

Prepare the bounded runtime, audit and synchronization infrastructure required by the mandatory Laravel modular-monolith architecture without implementing order, shipment or business workflows.

## Architecture Evidence

- `docs/10_Planning_Roadmap/03_SYSTEM_ARCHITECTURE.md` requires Redis, Queue, Scheduler, audit logs and final-compatible `sync_outbox` infrastructure.
- Existing authentication and Application Shell packages establish server authorization and authenticated delivery surfaces.
- Existing `audit_records` code is a minimal authentication foundation; 001D must review compatibility rather than assume replacement.
- Manual and automated business workflows remain separate later packages.

## Bounded In Scope for Future Authorized Implementation

- Redis connection and runtime configuration validation;
- Queue foundation, named queues and worker-safe job conventions;
- Scheduler foundation with overlap, locking and environment safeguards;
- `audit_records` schema review and forward-compatible audit service contract;
- `sync_outbox` infrastructure contract without external provider execution;
- runtime health checks and configuration-failure reporting;
- Migration and rollback design, subject to separate Migration authorization;
- authorization boundaries for runtime, audit and sync operations;
- automated runtime, failure, retry and idempotency test matrix;
- deterministic operational failure behaviour without fabricated business state.

## Out of Scope

- order and order-item business implementation;
- shipment or tracking workflow;
- manual Sync UI;
- WooCommerce, Shopify or other adapters and external API calls;
- batch synchronization orchestration;
- accounting, cost or profit logic;
- inventory or procurement logic;
- 001E design system or Browser E2E;
- UAT and deployment.

## Proposed Files and Modules

Exact files must be frozen during an authorized 001D preflight. Expected areas may include:

```text
config/database.php
config/queue.php
config/cache.php
routes/console.php
app/Support/Runtime/*
app/Support/Audit/*
app/Support/Sync/*
app/Jobs/*
database/migrations/*audit_records*
database/migrations/*sync_outbox*
tests/Feature/Runtime/*
tests/Feature/Audit/*
tests/Feature/Sync/*
```

No listed file is authorized for modification by this prepared package.

## Proposed Schema Contracts

### Audit

Preserve actor, action, entity, before/after, source, request correlation, client metadata, reason and immutable creation time. Never persist passwords, confirmations, session identifiers, CSRF values, authorization headers or complete request payloads.

### Sync Outbox

Plan final-compatible fields for aggregate reference, operation, provider/connection reference, normalized payload reference, status, attempt count, availability/lock timing, last error summary, idempotency key and timestamps. Do not create order truth or provider-specific duplicate business tables.

## Migration Safety Plan

- review current schema and deployed assumptions before authoring migrations;
- make migrations additive and forward-compatible where practical;
- preserve existing authentication audit data;
- verify MySQL-compatible types and indexes in isolated tests;
- require explicit Owner Migration authorization before any execution;
- never run application migrations as part of documentation or ordinary validation.

## Rollback Plan

- define reversible schema operations before implementation;
- stop workers and scheduler before any authorized rollback affecting runtime contracts;
- retain audit history unless an explicit data-retention decision authorizes otherwise;
- verify rollback in an isolated environment before any production consideration;
- report irreversible operations as a hard gate requiring Owner decision.

## Permission Contract

- runtime administration and health details require system-level permissions;
- audit viewing remains distinct from audit writing;
- sync operation permissions use the `sync.*` namespace;
- navigation hiding never substitutes for Gate enforcement;
- jobs and scheduled commands must re-check authorization or trusted-system actor boundaries where applicable.

## Queue and Scheduler Contract

- jobs must be serializable, idempotent where retried, and bounded in attempts/timeouts;
- failures must preserve actionable non-secret diagnostics;
- scheduler entries must prevent unsafe overlap and duplicate execution;
- Redis/queue unavailability must fail visibly without silently marking work complete;
- no external synchronization job is implemented until its business package is authorized.

## Audit Contract

- one service boundary for immutable audit creation;
- structured before/after metadata with sensitive-field rejection;
- actor and request correlation when available;
- system actions distinguish system actor/source from authenticated users;
- audit write failure behaviour is explicit for security-critical operations.

## Sync-Outbox Contract

- outbox records represent intended integration work, not business truth;
- unique idempotency prevents duplicate queued intent;
- state transitions are explicit and auditable;
- retries use bounded backoff and preserve last safe failure information;
- completion requires a future authorized provider/application service, not direct model-side effects.

## Acceptance Criteria for Future Implementation

- Redis, Queue and Scheduler configuration is validated with safe failure behaviour;
- audit service persists approved metadata and rejects sensitive fields;
- sync-outbox foundation supports deterministic pending, processing, completed and failed infrastructure states;
- authorization protects runtime, audit and sync surfaces;
- migrations have reviewed up/down behaviour but are not executed without authorization;
- no business Order, Shipment, UI or external adapter behaviour is introduced;
- all automated tests, route validation, typecheck/build where affected and diff checks pass;
- no production mutation or deployment occurs.

## PHP Test Plan

- configuration contract and invalid/missing Redis settings;
- queue dispatch metadata, serialization and idempotency;
- scheduler registration, overlap and environment guards;
- audit persistence, casts, sensitive-field rejection and failure behaviour;
- sync-outbox state transitions, uniqueness and authorization;
- migration schema contracts in isolated test databases only.

## Runtime Test Plan

- Redis reachable/unreachable behaviour without requiring production services;
- queue sync/fake execution and failed-job paths;
- scheduler due/not-due and lock contention behaviour;
- health result redaction and authorized visibility;
- configuration cache compatibility.

## Failure and Retry Test Plan

- bounded retries and backoff;
- duplicate dispatch/idempotency conflicts;
- stale processing lock recovery design;
- audit failure on security-critical operations;
- Redis, database and queue transport outage behaviour;
- no false completion after exceptions.

## Validation Commands for Future Authorized Implementation

```text
composer validate --no-check-publish
php artisan route:list
php artisan test --compact
npm run test
npm run typecheck
npm run build
git diff --check
```

Migration commands are excluded unless a later package contains explicit Migration authorization.

## Hard Gates

- Owner implementation authorization must be YES before editing application files;
- Migration and deployment require separate explicit authorization;
- no business workflow, provider adapter or external call;
- no sensitive data in audit, logs, jobs or health output;
- no temporary business truth tables;
- no 001E, UAT or deployment scope;
- no claim that the Global Architecture Gate passed.

## Risks

- local/test Redis availability may differ from production topology;
- existing minimal audit schema may require additive compatibility decisions;
- queue retry semantics can duplicate effects without idempotency;
- scheduler overlap can produce duplicate work;
- premature sync-outbox coupling could leak later business scope into 001D;
- migration rollback may be constrained by retained audit history.

## Frozen Effective-Hours Estimate

```text
ERP-ARCH-MIGRATION-001D-A: 12–18h (planning value 15h)
ERP-ARCH-MIGRATION-001D-B: 9–14h (planning value 12h)
ERP-ARCH-MIGRATION-001D-C: 14–22h (planning value 18h)
Estimated effective hours: 45h
Estimate range: 35–54 effective hours
```

The combined planning value is `15 + 12 + 18 = 45` hours and the combined range is `12 + 9 + 14 = 35` through `18 + 14 + 22 = 54` hours. This estimate confers no authorization.

## Final Preparation Verdict

```text
FINAL_PREPARATION_VERDICT: 001D_SCOPE_READY_FOR_OWNER_REVIEW
```

## Project Position

```text
PHASE_MAP:
001A Foundation Bootstrap             MERGED / PASS
001B Authentication / Authorization   MERGED / PASS
001C Application Shell                MERGED / PASS
→ 001D Runtime / Audit / Sync         PREPARED / IMPLEMENTATION NOT STARTED
001E Shared UI / Browser E2E          WAITING
001F Architecture Re-audit            WAITING
COMPLETED_PHASES: 001A, 001B, 001C
CURRENT_PHASE: 001D Runtime / Audit / Sync scope freeze
CURRENT_WORK_PACKAGE: ERP-ARCH-MIGRATION-001D
PACKAGE_STATUS: SCOPE FROZEN / 001D-A PREPARED / OWNER IMPLEMENTATION AUTHORIZATION NO
WAITING_PHASES: 001E, 001F
BASELINE: main@704c64fdadc457ad87711f70a6bb6de7dc8eb587
GITHUB_STATE: 001C PR #5 and 001D preparation PR #7 MERGED; 001D implementation PR does not exist
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: Owner reviews the frozen split, then separately authorizes ERP-ARCH-MIGRATION-001D-A
EVIDENCE_AS_OF: 2026-08-01; repository governance, GitHub PR #7 and frozen 001D scope
```

## Estimated Codex Hours

```text
PREFLIGHT_AND_SCOPE: 3-5 hours (planning value 4 hours)
CORE_EXECUTION: 23-34 hours (planning value 29 hours)
TEST_AND_VALIDATION: 5-8 hours (planning value 6 hours)
REMEDIATION_BUFFER: 4-7 hours (planning value 6 hours)
ESTIMATED_EFFECTIVE_HOURS: 45 hours
ESTIMATE_RANGE: 35-54 effective hours
ESTIMATE_BASIS: planning values 4 + 29 + 6 + 6 hours; reconciled to 001D-A 15 hours, 001D-B 12 hours and 001D-C 18 hours, with combined ranges of 35-54 hours
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```
