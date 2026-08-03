---
type: byl-erp-work-package
project: BYL ERP
work_package: ERP-ARCH-MIGRATION-001D
status: complete-pass
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.1
---

# ERP-ARCH-MIGRATION-001D — Runtime / Audit / Sync Foundation

## Work Package

```text
ID: ERP-ARCH-MIGRATION-001D
TITLE: Runtime / Audit / Sync Foundation
STATUS: COMPLETE / PASS
OWNER_IMPLEMENTATION_AUTHORIZATION: SUBPACKAGE-SPECIFIC
MERGE_STATUS: ALL THREE SUBPACKAGES MERGED
MIGRATION_STATUS: ISOLATED 001D-C VALIDATION ONLY / DEV STAGING PRODUCTION NOT RUN
DEPLOYMENT_AUTHORIZATION: NO
```

This file is the closure record for the three independently authorized and reviewed 001D subpackages. It grants no new implementation, Migration, UAT or deployment authorization.

## Frozen Execution Split

Execution order is mandatory:

```text
ERP-ARCH-MIGRATION-001D-A
Runtime Foundation
Status: MERGED / PASS
PR: #9
Accepted Head: 3d8f92b701370db41bed0df707183207f6924dd5
Main SHA: 50803c3fcd51e9f8b3485f4fb57565154a284bf7
Estimate: 12–18 effective hours (planning value 15 hours)

→ ERP-ARCH-MIGRATION-001D-B
Audit Foundation
Status: MERGED / PASS
PR: #12
Accepted Head: 953d1b07d48f975a5f1098069927ae9a680ebc7d
Main SHA: e2247fcaaae690c94ef07d45c8807ce749de1b46
Estimate: 9–14 effective hours (planning value 12 hours)

→ ERP-ARCH-MIGRATION-001D-C
Sync Outbox Foundation
Status: MERGED / PASS
PR: #13
Accepted Head: e4bc57b7a3f0edbe84ccfde63d02d9135a4875e3
Main SHA: 548d53f1eae2ec14eea7261d3a101c1f2fda0dcc
Estimate: 14–22 effective hours (planning value 18 hours)

ACCEPTED_EVIDENCE_LIMITS:
REAL_MYSQL_TWO_CONNECTION_CLAIM_CONCURRENCY: UNVERIFIED
MYSQL_5.7: UNVERIFIED
DEV / STAGING / PRODUCTION MIGRATION: NOT RUN
```

Each subpackage was separately authorized, independently reviewed and merged. The evidence limits above remain active and are not converted to PASS by parent closure.

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

## Closure Acceptance Evidence

- 001D-A Runtime Foundation is MERGED / PASS through PR #9;
- 001D-B Audit Foundation is MERGED / PASS through PR #12;
- 001D-C Sync Outbox Foundation is MERGED / PASS through PR #13;
- no business Order, Shipment, Sync UI or external adapter behaviour was introduced;
- no Dev, Staging or Production Migration, UAT or deployment occurred;
- Global Architecture Gate remains FAIL until 001E and 001F complete their separate gates.

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

## Final Closure Verdict

```text
FINAL_VERDICT: ERP_ARCH_MIGRATION_001D_COMPLETE_PASS
```

## PROJECT POSITION

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Manual Fulfillment / Tencent Docs Replacement → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Customer / Lead Pilot → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Dev UAT and Production Hardening
CURRENT_TRACK_PHASE_MAP: 001A MERGED / PASS → 001B MERGED / PASS → 001C MERGED / PASS → 001D-A MERGED / PASS → 001D-B MERGED / PASS → 001D-C MERGED / PASS → 001D COMPLETE / PASS → 001E NEXT / PREPARED / IMPLEMENTATION NOT STARTED → 001F WAITING
COMPLETED_PHASES: 001A, 001B, 001C, 001D-A, 001D-B, 001D-C, 001D
CURRENT_PHASE: 001E Shared UI / Browser E2E preparation
CURRENT_WORK_PACKAGE: ERP-ARCH-MIGRATION-001D
PACKAGE_STATUS: COMPLETE / PASS; all three subpackages merged
WAITING_PHASES: 001E implementation, 001F
BASELINE: main@548d53f1eae2ec14eea7261d3a101c1f2fda0dcc
GITHUB_STATE: PR #9, PR #12 and PR #13 MERGED; this closure branch has no PR
SOURCE_CONFLICTS: NONE WITH EVIDENCE; GitHub merge facts and authorized main agree. Evidence limitations remain explicitly unverified.
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: Independent review of remediated 001E preparation; separate Owner authorization for 001E-A Preflight only
EVIDENCE_AS_OF: 2026-08-01; GitHub PR #9/#12/#13, origin/main and accepted subpackage Work Logs
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: 45h planning value; 35–54h range; total 001D track estimate
KNOWN_CURRENT_TRACK_REMAINING: 40–62h parent 001E planning range; child implementation estimates require Preflight confirmation; 001F excluded
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE; 001E parent planning exists but child Preflights and 001F estimate are incomplete
MVP1_TOTAL_ESTIMATE: 122–190h total estimate
MVP1_PLANNING_BASELINE: 166h total planning value
MVP1_MAXIMUM_ENVELOPE: 192h maximum total envelope
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; accepted actual-hours ledger and overlap map are incomplete
POST_MVP_KNOWN_INCREMENT: approximately 68–120h incremental beyond MVP1
LONG_TERM_CUMULATIVE_RANGE: 235–402h through 1,819–3,146h; cumulative non-additive horizons
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; cumulative horizons and project-wide actual ledger are incomplete
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; 001D-A has no accepted actual-hours value, while 001D-B records 3h and 001D-C records 4.8h
ESTIMATE_CONFIDENCE: HIGH for issued 001D; LOW / MEDIUM for parent 001E planning before child Preflights; LOW / UNKNOWN for Architecture Gate and program remaining
ESTIMATE_AS_OF: 2026-08-01
ESTIMATE_METHOD: repository classification; no unsafe subtraction; no cumulative or overlapping aggregation
```

## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: 3-5 hours (planning value 4 hours)
CORE_EXECUTION: 23-34 hours (planning value 29 hours)
TEST_AND_VALIDATION: 5-8 hours (planning value 6 hours)
REMEDIATION_BUFFER: 4-7 hours (planning value 6 hours)
ESTIMATED_EFFECTIVE_HOURS: 45 hours
ESTIMATE_RANGE: 35-54 effective hours
ESTIMATE_BASIS: planning values 4 + 29 + 6 + 6 hours; reconciled to 001D-A 15 hours, 001D-B 12 hours and 001D-C 18 hours, with combined ranges of 35-54 hours
ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; 001D-A actual was not recorded, preventing a complete parent total
ACTUAL_HOURS_BREAKDOWN: 001D-A UNKNOWN WITH EVIDENCE; 001D-B 3h; 001D-C 4.8h
VARIANCE: UNKNOWN WITH EVIDENCE; incomplete accepted actual ledger prevents safe arithmetic against 45h
VARIANCE_REASON: Historical 001D-A Work Log explicitly records no productive actual-hours value
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```
