---
type: work-package
project: BYL ERP
work_package_id: MVP1-SYNC-MANUAL-001
title: Manual Website Sync Queue and Payload Workflow
status: active-work-package
updated: 2026-08-05
---

# MVP1-SYNC-MANUAL-001 — Manual Website Sync Queue and Payload Workflow

## Authorization and Scope

```text
OWNER_CONTINUOUS_AUTHORIZATION_ID: BYL-ERP-001F-TO-MVP1-LOCAL-UAT-20260805
STANDARD_ID: LARGE-PROJECT-EXEC-001
STANDARD_VERSION: 1.1
BASE_SHA: 4d6b36c42e7dda05cb4a6e396a84afdb45b4ab3b
TARGET: local repository, local MySQL UAT, local Redis and GitHub governance only
MENU_IMPACT: NO
APPROVED_MENU_CHECK: PASS
REMOTE_DEV_ACCESS: NO
PRODUCTION_ACCESS: NO
SSH: NO
HUMAN_UAT: NOT STARTED
```

## Objective

Provide the manual website-update queue that receives shipped orders, exposes an approved copy payload, records the operator result and supports correction without calling any external Provider.

## Architecture and Ownership

```text
ORDER_TRUTH: app/Modules/Orders/**
SHIPMENT_INPUT: app/Modules/Logistics/**
MANUAL_SYNC_TRUTH: app/Modules/Sync/Infrastructure/Models/ManualSync.php
MANUAL_SYNC_APPLICATION: app/Modules/Sync/Application/ManualSyncService.php
MANUAL_SYNC_EXPERIENCE: ManualSyncController and module routes
AUDIT: Audit module records manual_sync.completed, manual_sync.reopened and manual_sync.returned
EXTERNAL_PROVIDER: none in MVP1; operator manually updates the website
```

Manual Sync consumes public Order and Shipment data. It does not create another order truth, provider adapter, webhook, queue write-back or external API credential.

## Frozen Business Contract

```text
USER_FACING_NAME: 同步
ROUTE: /sync
STATUS_DICTIONARY: not_required; pending; completed; needs_confirmation; returned; failed
QUEUE_ENTRY: shipped order creates or updates exactly one order-scoped pending record
COMPLETE: actor, timestamp and optional note are required in the audit record
CORRECTION: completed may reopen to pending; pending may return for correction
PAYLOAD: order number, source, date, recipient snapshot, items, carrier, tracking and shipped time
PERMISSIONS: sync.view; sync.operate
EXTERNAL_WRITES: prohibited
```

The approved level-1 menu remains `同步`; route, URL, permission and menu JSON remain unchanged. `MENU_IMPACT: NO`.

## Database and Migration

```text
DATABASE_IMPACT: manual_syncs
MIGRATION: database/migrations/2026_08_05_000002_create_manual_syncs_table.php
CONSTRAINT: unique manual_syncs.order_id; foreign keys to orders and users
ROLLBACK: remove manual_syncs only after dependent UAT data is absent
RE-RUN: fresh migration and rollback/re-apply must be deterministic on disposable local SQLite and MySQL 8.4.x
BACKUP: local UAT backup is validated by MVP1-UAT-RELEASE-001; no remote or production database access
```

## Authorization and Audit

```text
READ: sync.view
OPERATE: sync.operate
VALIDATION: server-side sync note validation and shipped-order boundary
AUDIT: actor, timestamp, before and after state, safe reason metadata
```

Forbidden requests return the repository-standard authorization response. Client buttons are not an authorization boundary.

## Routes and UI

```text
GET  /sync                              shell.sync
GET  /sync/orders/{order}/payload       sync.payload
POST /sync/orders/{order}/complete      sync.complete
POST /sync/orders/{order}/reopen        sync.reopen
POST /sync/orders/{order}/return        sync.return
```

The desktop-first UI supports search, status filter, payload copy, complete, reopen, return/correction and operator notes. Status filters expose the frozen dictionary without implying automatic external synchronization.

## Acceptance Criteria

- A shipped order enters exactly one pending Manual Sync record.
- Queue search and status filters use server-side data and do not expose unauthorized fields.
- Copy payload is generated from the current Order recipient/items and Shipment data and does not perform network writes to the website.
- Complete records actor, timestamp and note and is idempotent for repeated identical requests.
- Reopen and return/correction preserve the single row, clear completion actor/time and record audit events.
- Non-shipped orders cannot be completed as if the website had been updated.
- Restricted users cannot view or operate the queue without `sync.view` or `sync.operate`.
- Fresh migration, rollback and re-apply pass on local disposable SQLite and MySQL 8.4.x.
- Focused, Architecture, full regression and Browser E2E evidence is recorded before closure; this package never claims human UAT.

## Validation Contract

```text
FOCUSED: php artisan test tests/Feature/Mvp1/Mvp1WorkflowTest.php; php artisan test --testsuite=Architecture
REGRESSION: composer validate --strict; php artisan test; npm test; npm run typecheck; npm run typecheck:e2e; npm run build; npm run test:e2e
STATIC: git diff --check; git fsck --full; secret scan
DATABASE: fresh migrate; rollback/re-apply; manual_syncs schema, unique order and foreign-key assertions on local MySQL 8.4.x
RUNTIME: local Redis/Queue/Scheduler evidence is consolidated by MVP1-UAT-RELEASE-001
BOUNDARY: no SSH, Remote Dev, Production, external API write, real email or human UAT
```

## Governance

```text
WORK_PACKAGE_BRANCH: codex/mvp1-sync-manual-001-work-package
WORK_PACKAGE_PR: pending
IMPLEMENTATION_EVIDENCE: inherited Manual Sync implementation from PR #59 and hardening PR #63
IMPLEMENTATION_PR: pending package-specific remediation review
CLOSURE_PR: pending
MERGE: authorized by continuous Owner authorization
```

## Out of Scope

WooCommerce/Shopify/WordPress API calls, webhook, automatic tracking write-back, batch sync, provider credentials, retry orchestration, Customer/Lead OS, Product/Media OS, full Accounting OS, remote deployment, production access, real external API writes and human UAT.

## Estimates

```text
PLANNING_ESTIMATE: 7–12h; planning value 9h
ESTIMATE_BASIS: 1.5h scope audit; 4h bounded service/UI remediation; 2h tests and runtime validation; 1.5h governance buffer
ACTUAL_EFFECTIVE_HOURS: pending accepted Work Log ledger
```

## PROJECT POSITION

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Manual Fulfillment / Tencent Docs Replacement → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Customer / Lead Pilot → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Dev UAT and Production Hardening
CURRENT_TRACK_PHASE_MAP: 001F COMPLETE / PASS / CLOSED → MVP1 preflight COMPLETE / PASS / CLOSED → Foundation COMPLETE / PASS / CLOSED → Order Core COMPLETE / PASS / CLOSED → Fulfillment COMPLETE / PASS / CLOSED → Manual Sync current → Finance Analysis waiting → UAT Release waiting
COMPLETED_PHASES: Architecture Migration / Global Architecture Gate; MVP1 preflight; MVP1-FOUNDATION-001; MVP1-ORDER-CORE-001; MVP1-FULFILLMENT-001
CURRENT_PHASE: MVP1 implementation
CURRENT_WORK_PACKAGE: MVP1-SYNC-MANUAL-001
PACKAGE_STATUS: ACTIVE / WORK PACKAGE
WAITING_PHASES: MVP1-FINANCE-ORDER-ANALYSIS-001; MVP1-UAT-RELEASE-001; Dev UAT; Production hardening
BASELINE: main / 4d6b36c42e7dda05cb4a6e396a84afdb45b4ab3b
GITHUB_STATE: Fulfillment Post-Merge Closure PR #65 MERGED; main 4d6b36c42e7dda05cb4a6e396a84afdb45b4ab3b; Manual Sync Work Package PR pending
SOURCE_CONFLICTS: NONE WITH EVIDENCE
GLOBAL_GATE: PASS
PRODUCTION_DEPLOYMENT_STATUS: NO / no production access or deployment
NEXT_DEPENDENCY: MVP1-SYNC-MANUAL-001 implementation review
EVIDENCE_AS_OF: 2026-08-05
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: 7–12h; planning value 9h
KNOWN_CURRENT_TRACK_REMAINING: MVP1-SYNC-MANUAL-001; MVP1-FINANCE-ORDER-ANALYSIS-001; MVP1-UAT-RELEASE-001
ARCHITECTURE_GATE_REMAINING: 0h / complete
MVP1_TOTAL_ESTIMATE: 122–190h
MVP1_PLANNING_BASELINE: 166h
MVP1_MAXIMUM_ENVELOPE: 192h
MVP1_REMAINING: UNKNOWN WITH EVIDENCE / accepted actual ledger and package overlap map incomplete
POST_MVP_KNOWN_INCREMENT: approximately 68–120h beyond MVP1
LONG_TERM_CUMULATIVE_RANGE: 235–402h; 603–1,018h; 1,059–1,802h; 1,595–2,714h; 1,819–3,146h
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE / long-term overlap ledger incomplete
COMPLETED_ACTUAL_EFFECTIVE_HOURS: 17.5h accepted across Foundation, Order Core, Fulfillment and current Manual Sync preparation; project-wide ledger incomplete
ESTIMATE_CONFIDENCE: MEDIUM / inherited implementation reduces new code scope but status boundary and UAT evidence remain in scope
ESTIMATE_AS_OF: 2026-08-05
ESTIMATE_METHOD: frozen MVP1 sync contract, current architecture boundary and inherited PR #59/#63 evidence
```

## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: 1.5–2.5h; planning value 1.5h
CORE_EXECUTION: 3–5h; planning value 4h
TEST_AND_VALIDATION: 1.5–2.5h; planning value 2h
REMEDIATION_BUFFER: 1–2h; planning value 1.5h
ESTIMATED_EFFECTIVE_HOURS: 9h
ESTIMATE_RANGE: 7–12h
ESTIMATE_BASIS: 1.5 + 4 + 2 + 1.5 = 9h; includes authorized review, implementation remediation, migration validation and governance; excludes human UAT and deployment
OWNER_REVIEW: INCLUDED
MERGE: INCLUDED
MIGRATION: INCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: INCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```
