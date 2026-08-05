---
type: work-package
project: BYL ERP
work_package_id: ERP-ARCH-MIGRATION-001F-B4
title: Runtime / Audit / Sync Boundary Migration
status: implementation-complete-pass-merged-post-merge-closure-in-progress
updated: 2026-08-05
---

# ERP-ARCH-MIGRATION-001F-B4 — Runtime / Audit / Sync Boundary Migration

## Authorization and Baseline

```text
OWNER_CONTINUOUS_AUTHORIZATION_ID: ERP-ARCH-MIGRATION-001F-CONTINUOUS-20260805
AUTHORIZED_SCOPE: PR #28 final review through 001F final post-merge closure
BASE_BRANCH: main
BASE_SHA: 0af914ebf8fc9f94b18e63e008fd78739bba6811
BASE_VERIFIED_AT: 2026-08-05 Asia/Hong_Kong
STANDARD: BYL-ERP-ARCH-001 v1.1
MENU_IMPACT: NO
APPROVED_MENU_CHECK: PASS
GLOBAL_ARCHITECTURE_GATE: FAIL
MVP1: NOT STARTED / 0%
UAT: NO
STAGING_MIGRATION: NO
PRODUCTION_MIGRATION: NO
PRODUCTION_DEPLOYMENT: NO
```

## Objective

Move the current Runtime, Audit and Sync responsibilities into canonical physical module boundaries while preserving the existing runtime, audit, route, table, serialization, error and Sync Outbox contracts. The implementation must remove duplicate ownership without creating external provider integrations, business workflows, database schema changes or a second audit writer.

## Read-only Preflight Findings

```text
CURRENT_RUNTIME_OWNERS: App\Support\Runtime\*; App\Http\Controllers\RuntimeHealthController; App\Console\Commands\RuntimeHealthCommand
CURRENT_AUDIT_OWNERS: App\Support\Audit\*; App\Models\AuditRecord; App\Http\Controllers\AuditRecordController
CURRENT_SYNC_OWNERS: App\Support\Sync\*; App\Models\SyncOutbox
CURRENT_RUNTIME_BINDINGS: Runtime services are container-resolved by concrete compatibility classes; no module provider exists
CURRENT_AUDIT_BINDINGS: AuditWriter -> EloquentAuditWriter and Identity AuditRecorder -> AuditService in AppServiceProvider
CURRENT_SYNC_BINDINGS: SyncOutboxService concrete resolution; constructor depends on AuditService
CURRENT_RUNTIME_ROUTES: /system/runtime/health; route name system.runtime.health; auth + can:system.permissions.manage
CURRENT_AUDIT_ROUTES: /system/audit-records; route name system.audit-records.index; auth + can:audit.view
CURRENT_TABLES: audit_records and sync_outbox remain historical database/migrations owned by their existing contracts
CURRENT_ARCHITECTURE_BASELINE: Architecture tests PASS after B3; B3 implementation and closure are merged
CURRENT_MENU_BASELINE: APPROVED_MENU.json unchanged; no B4 menu impact
CURRENT_GLOBAL_GATE: FAIL because MySQL two-connection concurrency, live Redis/runtime and Dev Migration remain unverified
```

## Scope

Create only the canonical layers required by current Runtime, Audit and Sync behavior:

```text
app/Modules/Runtime/Application/
app/Modules/Runtime/Infrastructure/
app/Modules/Runtime/Experience/
app/Modules/Runtime/Providers/
app/Modules/Audit/Application/
app/Modules/Audit/Infrastructure/
app/Modules/Audit/Experience/
app/Modules/Audit/Providers/
app/Modules/Sync/Application/
app/Modules/Sync/Infrastructure/
app/Modules/Sync/Providers/
```

Actual layers may be reduced when a responsibility does not require a layer. No empty Domain directory is required.

### Runtime ownership

Runtime owns:

- Redis client capability and connection probe;
- Runtime health service and redacted result schema;
- Queue names, retry, timeout and backoff conventions;
- runtime health controller and command;
- runtime-owned route registration;
- Runtime module provider composition.

### Audit ownership

Audit owns:

- canonical immutable AuditRecord model;
- AuditWriter and Eloquent persistence adapter;
- AuditService/application recording contract;
- actor/source/request correlation and sensitive-field sanitization;
- immutable builder and critical write failure behavior;
- audit controller and route registration;
- Audit module provider composition.

### Sync ownership

Sync owns:

- canonical SyncOutbox model and status enum;
- SyncOutbox application service and transition contracts;
- idempotency, conditional claims, lock tokens and stale recovery;
- bounded retry/failure summaries and Audit transition calls;
- Sync module provider composition.

## Compatibility Contract

The following must remain unchanged:

- `/system/runtime/health`, `system.runtime.health`, `auth`, `can:system.permissions.manage`;
- `/system/audit-records`, `system.audit-records.index`, `auth`, `can:audit.view`;
- `audit_records` and `sync_outbox` table names and historical migration paths;
- AuditRecord casts, immutable writes, one writer binding and critical failure behavior;
- Audit actor/source values, request correlation, redaction and transition metadata;
- Runtime health JSON and CLI stable status/code schema;
- Redis capability codes and degraded failure behavior;
- Queue names, `after_commit`, retry-after, block-for, timeout and backoff contracts;
- SyncOutbox fields, casts, statuses, idempotency comparison, claim ownership, retry, fail, stale recovery and rollback-on-audit-failure behavior;
- old `App\Support\Runtime`, `App\Support\Audit`, `App\Support\Sync` and `App\Models` public symbols while dependents remain;
- no duplicate audit write, no duplicate SyncOutbox state transition and no second active implementation.

Legacy symbols are thin compatibility wrappers or aliases that point to canonical module classes. Each retained wrapper must record its canonical class and removal condition in the Work Log or wrapper registry. B5 owns removal when public callers have migrated.

## Cross-module Boundary

Runtime, Audit and Sync must not import each other’s private Infrastructure. Cross-module calls use public Application Contracts, Actions, Events or Queries.

Required public boundary:

```text
Sync -> Audit: public audit recording contract or approved Audit Application action
Identity -> Audit: existing AuditRecorder contract from B3
Runtime -> no Audit or Sync private implementation dependency
```

The implementation must preserve one binding for the audit writer and one canonical SyncOutbox state machine. No direct AuditRecord or SyncOutbox writes may bypass the owning application service.

## Provider and Route Contract

Create Runtime, Audit and Sync Providers and register them through `App\Modules\ModuleManifest::providers()` in deterministic order. Existing Identity registration must remain deterministic.

Target order:

```text
AppServiceProvider -> ModulesServiceProvider -> IdentityServiceProvider -> RuntimeServiceProvider -> AuditServiceProvider -> SyncServiceProvider
```

Providers may compose bindings, configuration and routes, but may not contain durable business rules, dynamic filesystem discovery or external provider execution.

Move Runtime and Audit routes into module route files where appropriate while preserving URL, route name, middleware and Permission Key. Sync has no current user-facing route and must not gain a business UI route in B4.

## Migration and Schema Contract

Historical migration files remain byte-for-byte unchanged in `database/migrations/**`.

```text
MIGRATION_FILE_AUTHORING: NO
MIGRATION_EXECUTION: NO
SCHEMA_REBUILD: NO
TABLE_RENAME: NO
TABLE_COPY: NO
```

The implementation may add no schema, migration, fixture database or production mutation. Existing schema contracts are verified read-only through current tests.

## Prohibited Scope

```text
APP\Modules\Orders: NOT CREATED
APP\Modules\Logistics: NOT CREATED
APP\Modules\Shell: NOT CREATED IN B4
APP\Modules\Dashboard: NOT CREATED IN B4
APP\Shared: NOT CREATED
MENU_BASELINE: NO CHANGE
REAL_EXTERNAL_PROVIDER_SYNC: NO
SYNC_UI: NO
ORDER_OR_SHIPMENT_BUSINESS: NO
MYSQL_CONCURRENCY_GATE: B4 DOES NOT CLAIM PASS
LIVE_REDIS_RUNTIME_GATE: B4 DOES NOT CLAIM PASS
DEV_MIGRATION: NO
UAT: NO
STAGING_MIGRATION: NO
PRODUCTION_MIGRATION: NO
PRODUCTION_DEPLOYMENT: NO
MVP1_BUSINESS_IMPLEMENTATION: NO
UNRELATED_REFACTORING: NO
```

## Acceptance Criteria

1. Runtime, Audit and Sync canonical classes exist under their approved `app/Modules/**` boundaries.
2. Legacy Runtime, Audit, Sync and model symbols delegate without duplicate business rules.
3. Module providers are explicit, valid and deterministic; no dynamic discovery is introduced.
4. Runtime and Audit routes preserve URL, names, middleware and Permission Keys; Sync gains no business route.
5. Audit has one public Application boundary, one writer binding and exactly-once transition semantics.
6. SyncOutbox preserves all current state, idempotency, locking, retry, failure, stale recovery and rollback contracts.
7. Historical migration paths, table names, schema contracts, menu baseline and reserved module scope are unchanged.
8. Architecture, focused Runtime/Audit/Sync tests, auth-audit regressions, full PHPUnit and frontend validation have no new unexplained failures.
9. Work Package, Work Log, Roadmap, Index, Change Log and current context record exact accepted heads and status.

## Validation

```text
PHP_LINT: all changed PHP files
COMPOSER_VALIDATE: composer validate --strict
ARCHITECTURE: php artisan test --testsuite=Architecture
RUNTIME_FOCUSED: tests/Feature/Runtime tests/Unit/Runtime
AUDIT_FOCUSED: tests/Feature/Audit tests/Feature/Auth/AuthAuditTest.php
SYNC_FOCUSED: tests/Feature/Sync
AUTH_REGRESSION: tests/Feature/Auth tests/Feature/AuthorizationFoundationTest.php tests/Feature/Console/CreateErpUserCommandTest.php
FULL_PHPUNIT: php artisan test
VITEST: npm test
APPLICATION_TYPECHECK: npm run typecheck
E2E_TYPECHECK: npm run typecheck:e2e
BUILD: npm run build
BROWSER_E2E: npm run test:e2e
MENU_BASELINE: approved menu and route/permission invariants
MIGRATION_CONTRACT: historical migration hashes and schema contract tests; no execution
DIFF_CHECK: git diff --check
FSCK: git fsck --full
```

## Risks and Rollback

- Provider boot order or duplicate route loading: preserve explicit provider order and run route inventory tests.
- Audit contract drift: retain one writer and assert exactly-once records plus failure rollback.
- Sync lock ownership drift: preserve conditional mutations and rerun all transition tests before merge.
- Runtime health leakage: retain stable redacted codes and secret-scan output paths.
- Wrapper type mismatch: keep aliases/thin wrappers until B5 removal criteria are met.

Rollback is a revert of the B4 implementation squash commit. No database rollback is required because B4 changes no schema or migration and performs no Migration execution.

## Project Position

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate -> MVP1 Manual Fulfillment / Tencent Docs Replacement -> MVP1.1 WooCommerce Inbound -> MVP1.2 Single Tracking Outbound -> MVP1.3 Sync Orchestration -> Customer / Lead Pilot -> Product / Media -> WordPress Product and Image Sync -> Content / SEO -> Posts / Pages -> GA4 / GSC / Cloudflare -> Google Docs Publishing -> Full Order Workspace -> Logistics OS -> KOL OS and Social Providers -> Inventory and Procurement -> Accounting / ROI Expansion -> Shopify and Additional Integrations -> Dev UAT and Production Hardening
CURRENT_TRACK_PHASE_MAP: 001A MERGED / PASS -> 001B MERGED / PASS -> 001C MERGED / PASS -> 001D COMPLETE / PASS -> 001E COMPLETE / PASS -> 001F-A COMPLETE / CLOSED -> 001F-B1 COMPLETE / CLOSED -> 001F-B2 COMPLETE / CLOSED -> 001F-B3 COMPLETE / PASS / CLOSED -> 001F-B4 IMPLEMENTATION COMPLETE / PASS / MERGED / POST-MERGE CLOSURE IN PROGRESS -> 001F-B5 PROPOSED / NOT STARTED -> 001F-C PROPOSED -> 001F-D PROPOSED -> 001F-E PROPOSED -> 001F-F PROPOSED -> Global Architecture Gate FAIL
COMPLETED_PHASES: 001A, 001B, 001C, 001D, 001E, 001F-A, 001F-B1, 001F-B2, 001F-B3
CURRENT_PHASE: ERP-ARCH-MIGRATION-001F-B4 POST-MERGE CLOSURE
CURRENT_WORK_PACKAGE: ERP-ARCH-MIGRATION-001F-B4
PACKAGE_STATUS: IMPLEMENTATION COMPLETE / PASS / MERGED / POST-MERGE CLOSURE IN PROGRESS
WAITING_PHASES: 001F-B5, 001F-C, 001F-D, 001F-E, 001F-F pending prior package closure
BASELINE: origin/main@0af914ebf8fc9f94b18e63e008fd78739bba6811
GITHUB_STATE: B4 Work Package PR #32 MERGED; B4 Implementation PR #33 MERGED
SOURCE_CONFLICTS: NONE WITH EVIDENCE; historical pre-B3 status text is retained as historical governance state
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: B4 post-merge closure review and merge; then B5 read-only Preflight
EVIDENCE_AS_OF: 2026-08-05
```

## Roadmap Hours

```text
CURRENT_PACKAGE_ESTIMATE: B4 6–12h; planning value 9h
KNOWN_CURRENT_TRACK_REMAINING: B4–B5 11–22h; planning value 16.5h, non-overlapping issued estimate
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE
MVP1_TOTAL_ESTIMATE: 122–190h
MVP1_PLANNING_BASELINE: 166h
MVP1_MAXIMUM_ENVELOPE: 192h
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: approximately 68–120h beyond MVP1
LONG_TERM_CUMULATIVE_RANGE: 235–402h architecture foundation; 603–1,018h first highly automated operations MVP; 1,059–1,802h product/SEO growth layer; 1,595–2,714h full core ERP; 1,819–3,146h all expansion
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE
ESTIMATE_CONFIDENCE: MEDIUM for B4 issued range; LOW / UNKNOWN for total remaining
ESTIMATE_AS_OF: 2026-08-05
ESTIMATE_METHOD: B4 preflight and implementation contract uses the frozen B4 6–12h range; no actual hours inferred from chat, commits or elapsed time
```

## Estimated Codex Hours

```text
PREFLIGHT_AND_SCOPE: 1.5h; planning value 1.5h
CORE_EXECUTION: 4.0h; planning value 4.0h
TEST_AND_VALIDATION: 2.5h; planning value 2.5h
REMEDIATION_BUFFER: 1.0h; planning value 1.0h
ESTIMATED_EFFECTIVE_HOURS: 9.0h
ESTIMATE_RANGE: 6–12h
ESTIMATE_BASIS: issued B4 planning range; component values reconcile to 9.0h and cover preflight, three boundary migrations, validation and review remediation
OWNER_REVIEW: INCLUDED
MERGE: INCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```

FINAL_STATUS: `B4_IMPLEMENTATION_COMPLETE_PASS_MERGED_POST_MERGE_CLOSURE_IN_PROGRESS`

## Implementation Completion

```text
B4_IMPLEMENTATION_PR: #33
B4_ACCEPTED_HEAD: 403152de84b85ead62d48d2a9c2135e759f007e1
B4_MAIN_SQUASH_COMMIT: ee9908c57cabbe4ac93b3628d28154601d96a4c1
FINAL_INDEPENDENT_REVIEW: PASS at 403152de84b85ead62d48d2a9c2135e759f007e1
MIGRATION: NO FILE CHANGE / NO EXECUTION
MENU_IMPACT: NO
```
