---
type: byl-erp-work-package
project: BYL ERP
work_package: ERP-ARCH-MIGRATION-001D-C
status: merged-pass
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.1
---

# ERP-ARCH-MIGRATION-001D-C — Sync Outbox Foundation

## Work Package

```text
ID: ERP-ARCH-MIGRATION-001D-C
TITLE: Sync Outbox Foundation
STATUS: MERGED / PASS
IMPLEMENTATION_AUTHORIZATION: YES
IMPLEMENTATION_BASE_SHA: e2247fcaaae690c94ef07d45c8807ce749de1b46
BRANCH: feature/erp-arch-migration-001d-c-sync-outbox-foundation
PR: #13 / MERGED
ACCEPTED_HEAD: e4bc57b7a3f0edbe84ccfde63d02d9135a4875e3
MAIN_SQUASH_SHA: 548d53f1eae2ec14eea7261d3a101c1f2fda0dcc
MIGRATION_FILE_AUTHORING: YES / COMPLETE
MIGRATION_EXECUTION: YES / ISOLATED TEST ENVIRONMENT COMPLETE / PERSISTENT ENVIRONMENTS NO
MERGE_STATUS: COMPLETE
DEPLOYMENT_AUTHORIZATION: NO
```

## Frozen Scope

- provider-independent `sync_outbox` infrastructure;
- explicit pending, processing, completed and failed states;
- unique idempotency key contract;
- bounded attempts and deterministic backoff metadata;
- available, locked and stale-processing recovery semantics;
- failure-safe Audit correlation;
- Model and Service contracts;
- isolated automated tests.

## Explicit Exclusions

- WooCommerce, Shopify or any external provider/API;
- Sync UI or batch orchestration;
- Order, Shipment or other business truth models/tables;
- ERP-ARCH-MIGRATION-001E;
- UAT or deployment.

## Migration Gate

If the current schema does not completely satisfy the frozen scope, execution stops before any Migration file is created. The exact schema evidence and proposed delta must be reported for separate Owner Migration File Authoring authorization. Migration execution remains separately prohibited.

## Schema Audit Result

Repository Migration inventory contains only Laravel users/session, cache, queue jobs, roles/permissions and `audit_records` schemas. There is no `sync_outbox` Migration, Model, Service or Job. The local SQLite schema likewise contains no sync/outbox table. Laravel `jobs` is a queue transport table and cannot represent persistent integration intent, logical idempotency, the approved state machine or stale lock recovery.

```text
REUSABLE_SYNC_OUTBOX_TABLE: NO
SCHEMA_RESULT: REQUIRED TABLE ABSENT
MIGRATION_REQUIRED: YES
MIGRATION_FILE_CREATED_OR_MODIFIED: YES / ONE NEW FILE
MIGRATION_EXECUTION: NOT RUN
```

## Migration File Authoring Evidence

```text
OWNER_MIGRATION_FILE_AUTHORING_AUTHORIZATION: YES
AUTHORING_BASE_HEAD: 96a42991bff7596b96d9c56a82ddd92f57b1f19d
MIGRATION_FILE: database/migrations/2026_08_01_000000_create_sync_outbox_table.php
MIGRATION_FILES_CREATED: 1
MIGRATION_FILES_MODIFIED: 0
SCHEMA_TEST: tests/Feature/Sync/SyncOutboxSchemaContractTest.php
MIGRATION_EXECUTION: NO / NOT RUN
PERSISTENT_DATABASE_CHANGE: NO
```

The authored Migration implements the frozen delta exactly. The Schema contract test is intentionally not executed under this package because `RefreshDatabase` would execute Migration files. Runtime Model, Service and state-machine implementation remain unstarted pending independent review of this authoring Head.

```text
PHP_SYNTAX_MIGRATION: PASS
PHP_SYNTAX_SCHEMA_TEST: PASS
PINT_AUTHORED_PHP_FILES: PASS
DIFF_CHECK: PASS
SCHEMA_TEST_EXECUTION: NOT RUN / MIGRATION EXECUTION PROHIBITED
MIGRATION_EXECUTION: NOT RUN
```

## Proposed Schema Delta

Create one provider-independent table named `sync_outbox`:

| Column | MySQL type | Nullable | Default | Contract |
|---|---|---:|---|---|
| `id` | `BIGINT UNSIGNED` | NO | auto increment | primary key |
| `idempotency_key` | `VARCHAR(191)` | NO | none | globally unique logical-intent key |
| `operation` | `VARCHAR(100)` | NO | none | stable provider-independent operation key |
| `aggregate_type` | `VARCHAR(64)` | YES | `NULL` | future domain aggregate type; no foreign key |
| `aggregate_id` | `VARCHAR(127)` | YES | `NULL` | future aggregate identifier; no business truth |
| `provider_key` | `VARCHAR(64)` | YES | `NULL` | future adapter key; no adapter implemented here |
| `connection_key` | `VARCHAR(127)` | YES | `NULL` | opaque future connection reference; no credentials |
| `payload` | `JSON` | YES | `NULL` | normalized safe integration intent; secrets prohibited by Service |
| `status` | `VARCHAR(20)` | NO | `pending` | Service-enforced: pending / processing / completed / failed |
| `attempts` | `SMALLINT UNSIGNED` | NO | `0` | incremented atomically on claim |
| `max_attempts` | `SMALLINT UNSIGNED` | NO | `3` | bounded by Service contract |
| `available_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | first/retry eligibility time |
| `locked_at` | `TIMESTAMP` | YES | `NULL` | processing lock acquisition time |
| `lock_token` | `CHAR(36)` | YES | `NULL` | UUID lock owner token |
| `last_attempted_at` | `TIMESTAMP` | YES | `NULL` | most recent claim time |
| `completed_at` | `TIMESTAMP` | YES | `NULL` | terminal completion time |
| `failed_at` | `TIMESTAMP` | YES | `NULL` | terminal exhaustion/failure time |
| `last_error_code` | `VARCHAR(100)` | YES | `NULL` | stable non-secret error key |
| `last_error_message` | `VARCHAR(500)` | YES | `NULL` | bounded sanitized diagnostic summary |
| `created_at` | `TIMESTAMP` | YES | `NULL` | Laravel created timestamp |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | Laravel updated timestamp |

### Indexes and Constraints

```text
PRIMARY KEY (id)
UNIQUE KEY sync_outbox_idempotency_key_unique (idempotency_key)
INDEX sync_outbox_claim_index (status, available_at)
INDEX sync_outbox_stale_index (status, locked_at)
INDEX sync_outbox_aggregate_index (aggregate_type, aggregate_id)
INDEX sync_outbox_provider_connection_index (provider_key, connection_key)
INDEX sync_outbox_lock_token_index (lock_token)
```

Idempotency is global and exact: two enqueue requests with the same normalized `idempotency_key` resolve to the existing intent rather than create a second row. No partial indexes, generated columns or database-specific check constraints are proposed; allowed status and attempt bounds are enforced by the Model/Service contract and tests.

Both composite string indexes use a conservative total of 191 characters:

```text
(aggregate_type 64 + aggregate_id 127) × 4 utf8mb4 bytes = 764 bytes maximum
(provider_key 64 + connection_key 127) × 4 utf8mb4 bytes = 764 bytes maximum
```

### Status, Lock and Retry Contract

```text
pending -> processing -> completed
pending -> processing -> pending (retry with future available_at)
pending -> processing -> failed (attempts exhausted or terminal safe failure)
processing(stale) -> pending (attempts remaining)
processing(stale) -> failed (attempts exhausted)
```

Claiming uses a database transaction and row lock, requires `status=pending`, `available_at<=now` and `attempts<max_attempts`, then atomically sets processing state, UUID `lock_token`, `locked_at`, `last_attempted_at` and increments attempts. Retry clears lock fields and calculates bounded backoff. Completion/failure requires the matching lock token and clears lock fields. Stale recovery compares `locked_at` with an application-defined cutoff and never marks work completed.

### Audit Association

No `audit_record_id` column or cross-table foreign key is proposed. Every state transition calls the existing required `AuditService` inside the same database transaction with:

```text
entity_type = App\Models\SyncOutbox
entity_id = sync_outbox.id
action = sync.outbox.<transition>
source = trusted system source dedicated to sync outbox
before / after = sanitized state metadata only
reason = stable transition reason key
```

If required Audit creation fails, the enclosing state transaction rolls back. This preserves failure safety without making mutable outbox state own immutable audit history.

## Proposed Migration Plan

`up()` creates only `sync_outbox` with the exact columns, defaults, unique constraint and indexes above. It does not create provider, Order, Shipment or business tables and does not seed rows.

`down()` drops only `sync_outbox`. An authorized rollback must first stop consumers and preserve any required pending/failed intent externally; immutable `audit_records` history remains untouched and may retain logical entity references after rollback.

## MySQL Compatibility

- authors Laravel Blueprint types intended for MySQL 8 and MySQL 5.7 where native JSON is available;
- the unique idempotency key is `VARCHAR(191)` and each composite string index totals 191 characters / 764 bytes under `utf8mb4`;
- uses no partial indexes, generated columns, check constraints, `SKIP LOCKED` dependency or provider-specific SQL;
- the SQLite Schema test verifies the Laravel schema contract through public Schema APIs only;
- SQLite does not reliably preserve/report MySQL `VARCHAR` lengths, unsigned integer physical attributes, native JSON storage, MySQL timestamp defaults or physical index byte construction;
- MySQL 8.4.11 physical column types, defaults, auto-increment behavior and index creation are VERIFIED in one disposable isolated environment;
- MySQL 5.7 remains UNVERIFIED and is not claimed;
- claim/recovery correctness relies on ordinary transactions and `SELECT ... FOR UPDATE` semantics exposed by Laravel.

The validation proves the frozen contract on MySQL 8.4.11 only. It does not prove MySQL 5.7 compatibility or authorize any persistent-environment Migration execution.

## Migration File Remediation Evidence

```text
PREVIOUS_HEAD: 61041c6e3214cac7bd320f07e0da53d80f140aef
REVIEW_RESULT: CHANGES REQUIRED
COMPOSITE_INDEX_LENGTH_REMEDIATION: COMPLETE / 191 characters and 764 utf8mb4 bytes per composite key
SCHEMA_CONTRACT_REMEDIATION: COMPLETE / authored assertions expanded
SQLITE_SCHEMA_SCOPE: Laravel public Schema API contract only
MYSQL_PHYSICAL_SCHEMA_VALIDATION: PENDING SEPARATE MIGRATION EXECUTION AUTHORIZATION
SCHEMA_TEST_EXECUTION: NOT RUN
MIGRATION_EXECUTION: NOT RUN
PHP_SYNTAX: PASS
PINT_AUTHORIZED_FILES: PASS
DIFF_CHECK: PASS
```

## SQLite Schema Test Remediation

```text
SQLITE_VERSION: 3.53.3
FAILED_ASSERTION: attempts.default expected string 0; SQLite public Schema API returned quoted string '0'
ROOT_CAUSE: Test normalization did not remove SQLite's outer whitespace, quotes and parentheses from numeric default values
REMEDIATION: attempts.default and max_attempts.default use one bounded outer-character normalization before exact comparison with strings 0 and 3
UNCHANGED_CONTRACTS: fields, types, nullable rules, primary/auto-increment and all unique/ordinary index assertions remain unchanged
MYSQL_VALIDATION: NOT CLAIMED / NOT RUN IN THIS REMEDIATION
MIGRATION_FILE: UNCHANGED
PHP_SYNTAX: PASS
PINT: PASS
SQLITE_SCHEMA_TEST: PASS / 3 tests / 82 assertions
DIFF_CHECK: PASS
```

## Isolated MySQL Migration Validation

```text
AUTHORIZED_HEAD: 84d1806c45174d7734529447db88e37afebf1d4f
MYSQL_VERSION: MySQL Community 8.4.11
MYSQL_HOST: localhost / disposable Unix socket only
MYSQL_PORT: DISABLED / skip-networking=1
MYSQL_DATABASE: random disposable byl_001dc_rerun_* database
MYSQL_CHARACTER_SET: utf8mb4
MYSQL_COLLATION: utf8mb4_unicode_ci
PRE_VALIDATION_TABLES: 0
ISOLATION_EVIDENCE: fresh mktemp datadir; fresh random database; no TCP; no shared service/config/data
FIRST_UP: PASS
FIRST_UP_WARNING_COUNT: 0 / same Laravel PDO connection / direct diagnostic query
PHYSICAL_SCHEMA_VALIDATION: PASS / all 21 columns, types, lengths, defaults, nullable, primary and auto-increment contracts
TABLE_ENGINE: InnoDB
ROW_FORMAT: Dynamic
TABLE_COLLATION: utf8mb4_unicode_ci
INDEX_VALIDATION: PASS / primary, unique and all five ordinary indexes with exact order and no prefix truncation
INDEX_LENGTH_RESULT: each composite string key is 191 utf8mb4 characters / 764 maximum bytes / SUB_PART NULL
DOWN: PASS / sync_outbox absent / no other table existed or changed
DOWN_WARNING_COUNT: 0
SECOND_UP: PASS
SECOND_UP_WARNING_COUNT: 0
SCHEMA_SNAPSHOT_COMPARISON: IDENTICAL
SQLITE_SCHEMA_TEST: PASS / 3 tests / 82 assertions
FULL_PHP_TEST_SUITE: PASS / 83 tests / 682 assertions
AUTHORIZED_FILES_PINT: PASS
REPOSITORY_WIDE_PINT: FAIL / 14 pre-existing baseline files
PRE_EXISTING_FAILURES: unchanged 001B/001C formatting debt including historical migrations, models, controllers, tests and routes
NEW_PINT_FAILURES: NONE
DIFF_CHECK: PASS
TEMP_ENVIRONMENT_CLEANED: YES / database, datadir, socket, log, verifier, server binary and process removed
DEV_STAGING_PRODUCTION_SHARED_DATABASE_CONTACT: NONE
```

## Rollback and Historical Data Impact

The forward Migration creates an empty additive table, so existing users, audit history, queue jobs and application data are unchanged. The rollback is structurally reversible but destructive to outbox intent rows; it must not run against populated environments without an explicit retention/export decision. No existing historical data needs transformation or backfill.

## Expected Implementation Files After Separate Authorization

```text
database/migrations/<timestamp>_create_sync_outbox_table.php
app/Models/SyncOutbox.php
app/Support/Sync/SyncOutboxStatus.php
app/Support/Sync/SyncOutboxService.php
app/Support/Sync/SyncOutboxWriteException.php
app/Support/Audit/AuditSource.php
tests/Feature/Sync/SyncOutboxFoundationTest.php
docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001D-C.md
docs/60_Codex_Operations/logs/<date>_ERP-ARCH-MIGRATION-001D-C.md
```

Exact application file count may shrink if a separate exception class is unnecessary. No UI, provider adapter, business Model, dependency or deployment file is proposed.

## Required Contracts

- outbox rows express integration intent, never business truth;
- state transitions are explicit, deterministic and auditable;
- idempotency prevents duplicate logical intent;
- attempts and backoff are bounded;
- processing locks have ownership and stale-recovery semantics;
- failure summaries exclude secrets and complete payloads;
- audit failure behavior never falsely marks work complete;
- no provider-specific behavior enters the foundation.

## Acceptance Criteria

- the actual current schema is inspected before implementation;
- schema fields, types, defaults, nullability and indexes are frozen;
- Model and Service enforce the approved state machine and idempotency;
- stale processing can safely return to an eligible state;
- failure and completion transitions are Audit-correlated;
- isolated tests cover state, locking, retry, idempotency and Audit failure;
- no prohibited provider, UI, business, Migration execution or deployment scope appears.

## Model Status Service Implementation Evidence

```text
IMPLEMENTATION_BASE_HEAD: a3732b3b478fc5c7730cd2f6fcd4d6f0c2e781a4
STATUS_ENUM: PASS / pending, processing, completed, failed only
MODEL_CONTRACT: PASS / sync_outbox mapping, exact casts, immutable datetimes and read-only Service boundary
SERVICE_CONTRACT: PASS / enqueue, claim, complete, retry, fail and bounded recoverStale
IDEMPOTENCY: PASS / canonical intent reuse, conflict rejection and database unique defense
PAYLOAD_TYPE_BOUNDARY: PASS / every level accepts arrays, strings, integers, finite floats, booleans and null only; objects, callables, resources, Traversable values and non-finite floats are rejected before canonicalization
LOCK_CONTRACT: PASS / UUID ownership token, conditional mutation and repeated claim rejection
BACKOFF: PASS / deterministic 5, 15, 30, 30-second capped sequence
ERROR_SAFETY: PASS / high-risk credential structures and lock tokens replace the complete summary; stack frames and controls are removed; persisted summaries remain bounded to 500 characters
AUDIT_SOURCE: sync:outbox / trusted system actor
AUDIT_ATOMICITY: PASS / every state write and required Audit share one database transaction; injected Audit failure rolls back enqueue, claim, complete, retry, fail and stale recovery
STALE_RECOVERY: PASS / bounded batch, pending or failed only, never completed
SQLITE_CONCURRENCY_EVIDENCE: conditional update and sequential duplicate claim rejection only
REAL_MYSQL_TWO_CONNECTION_CLAIM_CONCURRENCY: UNVERIFIED / requires a separate Owner gate if demanded
MIGRATION_MODIFICATION: NONE
MODEL_SERVICE_SCOPE_EXPANSION: NONE
```

```text
SYNC_FOCUSED_TESTS: PASS / Schema + Foundation 30 tests / 406 assertions
AUDIT_AUTH_PROVISIONING_REGRESSION: PASS / 30 tests / 179 assertions
FULL_PHP_TEST_SUITE: PASS / 110 tests / 1006 assertions
FRONTEND_TESTS: PASS / 5 files / 11 tests
COMPOSER: PASS
ROUTES: PASS / 59 routes
TYPECHECK: PASS
BUILD: PASS
AUTHORIZED_FILES_PINT: PASS
REPOSITORY_WIDE_PINT: FAIL / same 14 pre-existing baseline files
NEW_PINT_FAILURES: NONE
DIFF_CHECK: PASS
```

## Independent Review Remediation Evidence

```text
REMEDIATION_BASE_HEAD: c47e7a1ac0a8d5e67e42991ee9c331c191ff6ee5
REVIEW_RESULT: CHANGES REQUIRED
PAYLOAD_RECURSIVE_TYPE_FREEZE: COMPLETE / validation precedes canonicalization and sensitive-key inspection
OBJECT_SERIALIZATION_BYPASS: REJECTED / stdClass, Stringable, Closure, resource, Traversable, Generator, DateTime and Enum coverage
NON_FINITE_FLOATS: REJECTED / NAN, INF and -INF coverage
LEGAL_DEEP_PAYLOAD: PASS
ERROR_SUMMARY_REDACTION: COMPLETE / Authorization, Bearer, password, cookies, API keys, secrets, tokens, private keys, URL userinfo, DSN passwords, lock tokens, stack frames and controls covered
RETRY_AUDIT_FAILURE_ROLLBACK: PASS / status, attempts, lock, availability, error and terminal fields unchanged; no failed Audit persisted
FAIL_AUDIT_FAILURE_ROLLBACK: PASS / processing lock, error and terminal fields unchanged; no failed Audit persisted
MIGRATION_MODEL_STATUS_AUDIT_FOUNDATION_MODIFICATION: NONE
REAL_MYSQL_TWO_CONNECTION_CLAIM_CONCURRENCY: UNVERIFIED
```

## Validation Plan

```text
composer validate --no-check-publish
php artisan route:list
php artisan test --compact
npm run test
npm run typecheck
npm run build
vendor/bin/pint --test <changed PHP files>
git diff --check
```

Further Migration execution is excluded unless separately re-authorized for another bounded environment or purpose.

## Implementation Verdict

```text
FINAL_VERDICT: ERP_ARCH_MIGRATION_001D_C_MERGED_PASS
```

## Frozen Estimate

```text
Preflight, scope and schema audit: 2–3h; planning value 2.5h
Core schema/application implementation: 7–11h; planning value 9h
Automated tests and validation: 3–4h; planning value 3.5h
Remediation buffer: 2–4h; planning value 3h
Estimated effective hours: 18h
Estimate range: 14–22h
```

The planning value reconciles to `2.5 + 9 + 3.5 + 3 = 18` hours. The range reconciles to `2 + 7 + 3 + 2 = 14` through `3 + 11 + 4 + 4 = 22` hours.

## PROJECT POSITION

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Manual Fulfillment / Tencent Docs Replacement → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Customer / Lead Pilot → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Dev UAT and Production Hardening
CURRENT_TRACK_PHASE_MAP: 001A MERGED / PASS → 001B MERGED / PASS → 001C MERGED / PASS → 001D-A MERGED / PASS → 001D-B MERGED / PASS → 001D-C MERGED / PASS → 001D COMPLETE / PASS → 001E NEXT / PREPARED / IMPLEMENTATION NOT STARTED → 001F WAITING
COMPLETED_PHASES: 001A, 001B, 001C, 001D-A, 001D-B, 001D-C, 001D
CURRENT_PHASE: 001E Shared UI / Browser E2E preparation
CURRENT_WORK_PACKAGE: ERP-ARCH-MIGRATION-001D-C
PACKAGE_STATUS: MERGED / PASS; MySQL 8.4.11 isolated schema validation retained
WAITING_PHASES: 001E implementation, 001F
BASELINE: main@548d53f1eae2ec14eea7261d3a101c1f2fda0dcc
GITHUB_STATE: PR #13 MERGED; accepted Head e4bc57b7a3f0edbe84ccfde63d02d9135a4875e3; squash SHA 548d53f1eae2ec14eea7261d3a101c1f2fda0dcc
SOURCE_CONFLICTS: NONE WITH EVIDENCE; accepted and merged facts agree. Real-MySQL two-connection concurrency and MySQL 5.7 remain explicitly unverified.
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: Independent review of remediated 001E preparation; separate Owner authorization for 001E-A Preflight only
EVIDENCE_AS_OF: 2026-08-01; GitHub PR #13, origin/main and accepted implementation evidence
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: 18h planning value; 14–22h issued 001D-C range
KNOWN_CURRENT_TRACK_REMAINING: 40–62h parent 001E planning range; child implementation estimates require Preflight confirmation; 001F excluded
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE; child Preflights and 001F estimate are incomplete
MVP1_TOTAL_ESTIMATE: 122–190h total estimate
MVP1_PLANNING_BASELINE: 166h total planning value
MVP1_MAXIMUM_ENVELOPE: 192h maximum total envelope
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; accepted actual-hours ledger and overlap map are incomplete
POST_MVP_KNOWN_INCREMENT: approximately 68–120h incremental beyond MVP1
LONG_TERM_CUMULATIVE_RANGE: 235–402h through 1,819–3,146h; cumulative non-additive horizons
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; cumulative horizons and project-wide actual ledger are incomplete
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; 001D-A lacks an accepted actual-hours value, so the parent and project-wide ledger remain incomplete
ESTIMATE_CONFIDENCE: HIGH for issued 001D-C estimate; LOW / UNKNOWN for Architecture Gate and program remaining
ESTIMATE_AS_OF: 2026-08-01
ESTIMATE_METHOD: frozen parent-package component estimate; no unsafe subtraction or cumulative aggregation
```

## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: 2–3h; planning value 2.5h
CORE_EXECUTION: 7–11h; planning value 9h
TEST_AND_VALIDATION: 3–4h; planning value 3.5h
REMEDIATION_BUFFER: 2–4h; planning value 3h
ESTIMATED_EFFECTIVE_HOURS: 18h
ESTIMATE_RANGE: 14–22h
ESTIMATE_BASIS: planning values 2.5 + 9 + 3.5 + 3 = 18h; lower bounds total 14h; upper bounds total 22h
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: INCLUDED — FILE AUTHORING AND ISOLATED VALIDATION ONLY
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: INCLUDED — ISOLATED TEST ENVIRONMENT ONLY
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```
