---
type: work-package
project: BYL ERP
work_package_id: MVP1-FULFILLMENT-001
title: Bounded Shipment and Logistics Manual Workflow
status: complete-pass-closed
updated: 2026-08-05
---

# MVP1-FULFILLMENT-001 — Bounded Shipment and Logistics Manual Workflow

## Authorization and Scope

```text
OWNER_CONTINUOUS_AUTHORIZATION_ID: BYL-ERP-001F-TO-MVP1-LOCAL-UAT-20260805
STANDARD_ID: LARGE-PROJECT-EXEC-001
STANDARD_VERSION: 1.1
BASE_SHA: fb8a4f97ad743f7dbce611b05394986ffea1a7e5
TARGET: local repository, local MySQL UAT, local Redis and GitHub governance only
MENU_IMPACT: NO
APPROVED_MENU_CHECK: PASS
REMOTE_DEV_ACCESS: NO
PRODUCTION_ACCESS: NO
SSH: NO
HUMAN_UAT: NOT STARTED
```

## Objective

Deliver the smallest reliable manual fulfillment boundary for orders already owned by `app/Modules/Orders/**`. Logistics owns the shipment record and shipment transition; it does not create a second order truth or expand into a full Logistics OS.

## Architecture and Ownership

```text
ORDER_TRUTH: app/Modules/Orders/**
SHIPMENT_TRUTH: app/Modules/Logistics/Infrastructure/Models/Shipment.php
SHIPMENT_APPLICATION: app/Modules/Logistics/Application/ShipmentService.php
SHIPMENT_EXPERIENCE: LogisticsController and module routes
MANUAL_SYNC_HANDOFF: public ManualSyncService::ensurePending contract
AUDIT: Audit module records shipment.marked_shipped with actor and timestamp
```

The implementation may consume the Orders public model relationship and the Manual Sync application service. It must not import private module infrastructure, external Provider code, or real website APIs.

## Frozen Business Contract

```text
PENDING_QUEUE: orders with order_status = pending_shipment
SHIPMENT_FIELDS: shipment_status; carrier; tracking_number; shipped_at; logistics_note; handled_by
SHIPMENT_STATUS: pending; shipped
TRANSITION: pending_shipment order -> shipped order and shipment in one transaction
MANUAL_SYNC_HANDOFF: one order-scoped pending record after shipment; repeated shipment requests do not create duplicates
COPY_PAYLOADS: recipient; full shipment; carrier/tracking
PERMISSIONS: logistics.view; logistics.operate
AUDIT_ACTION: shipment.marked_shipped
```

The user-facing level-1 menu remains `物流`; route names, URLs, permission keys and approved menu JSON are unchanged. `MENU_IMPACT: NO`.

## Database and Migration

```text
DATABASE_IMPACT: shipments
MIGRATION: database/migrations/2026_08_05_000001_create_shipments_table.php
CONSTRAINT: unique shipments.order_id; foreign keys to orders and users
ROLLBACK: remove shipments only after dependent package data is absent
RE-RUN: fresh migration and rollback/re-apply must be deterministic on disposable local SQLite and MySQL 8.4.x
BACKUP: local UAT backup remains a release-gate artifact; no remote or production database access
```

## Authorization and Audit

```text
READ: logistics.view
SHIP: logistics.operate
VALIDATION: server-side carrier, tracking, shipped_at and note validation
AUDIT: actor, timestamp, before and after shipment state
```

Forbidden mutations return the repository-standard authorization response. Client controls are supplementary only.

## Routes and UI

```text
GET  /logistics                         shell.logistics
GET  /logistics/pending                 logistics.pending
GET  /logistics/orders/{order}/payload  logistics.payload
POST /logistics/orders/{order}/ship     logistics.ship
POST /logistics/orders/{order}/reopen   logistics.reopen
```

The desktop-first UI supports the pending queue, recipient/full/tracking copy actions, carrier/tracking entry, shipped time, note and mark-shipped action. No production mock data is introduced.

## Acceptance Criteria

- A permitted logistics operator sees only pending-shipment orders in the bounded queue.
- Recipient, full shipment and tracking payloads are server-generated from the Order snapshot and Shipment record.
- Invalid carrier, tracking or shipped time input is rejected server-side.
- Mark shipped atomically updates the order and shipment, records `handled_by`, writes an audit event and creates exactly one pending Manual Sync record.
- Repeated shipment transition is idempotent at the order-scoped shipment and Manual Sync records and does not create duplicate queue rows.
- Restricted users cannot view or mutate Logistics without the corresponding permission.
- Fresh migration, rollback and re-apply pass on local disposable SQLite and MySQL 8.4.x.
- Focused, Architecture, full regression and Browser E2E evidence is recorded before closure; this package never claims human UAT.

## Validation Contract

```text
FOCUSED: php artisan test tests/Feature/Mvp1/Mvp1WorkflowTest.php; php artisan test --testsuite=Architecture
REGRESSION: composer validate --strict; php artisan test; npm test; npm run typecheck; npm run typecheck:e2e; npm run build; npm run test:e2e
STATIC: git diff --check; git fsck --full; secret scan
DATABASE: fresh migrate; rollback/re-apply; shipment schema, unique order and foreign-key assertions on local MySQL 8.4.x
RUNTIME: local Redis/Queue/Scheduler evidence is consolidated by MVP1-UAT-RELEASE-001
BOUNDARY: no SSH, Remote Dev, Production, external API write, real email or human UAT
```

## Governance

```text
WORK_PACKAGE_BRANCH: codex/mvp1-fulfillment-001-work-package
WORK_PACKAGE_PR: #62 MERGED / MAIN f7c131433f3ed042b7f0a2f9dab1928742f9b347
IMPLEMENTATION_EVIDENCE: inherited bounded Logistics implementation from PR #59 / main 5ce98ad34b4adac0e931e348bfce0961e3d4bfd6
IMPLEMENTATION_PR: #63 MERGED / ACCEPTED HEAD 22ded98dc9cd056ac4456aadd62d819de7a47290 / MAIN 65345c03bfed3c8b30dec56dec3444ab8b175b20
CLOSURE_PR: #64 MERGED / MAIN CLOSURE 518de20b91c401354ecca66f20a17dc95f5e1cf8
MERGE: authorized by continuous Owner authorization
```

## Out of Scope

Full Logistics OS, warehouse management, inventory, procurement, carrier APIs, tracking write-back, automatic shipment import, batch external sync, Customer/Lead OS, Product/Media OS, Accounting OS, remote deployment, production access, real external API writes and human UAT.

## Estimates

```text
PLANNING_ESTIMATE: 8–14h; planning value 11h
ESTIMATE_BASIS: 2.5h scope and inherited implementation audit; 5h bounded remediation and UI/test closure; 2.5h validation; 1h governance buffer
ACTUAL_EFFECTIVE_HOURS: 3.5h
ACTUAL_HOURS_BREAKDOWN: 0.5h package audit; 1.5h remediation; 1.0h validation; 0.5h governance
VARIANCE: -7.5h / -68.18%
VARIANCE_REASON: PR #59 already contained the bounded implementation; this package required targeted hardening, regression coverage and governance.
```

## PROJECT POSITION

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Manual Fulfillment / Tencent Docs Replacement → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Customer / Lead Pilot → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Dev UAT and Production Hardening
CURRENT_TRACK_PHASE_MAP: 001F COMPLETE / PASS / CLOSED → MVP1 preflight COMPLETE / PASS / CLOSED → Foundation COMPLETE / PASS / CLOSED → Order Core COMPLETE / PASS / CLOSED → Fulfillment current → Manual Sync waiting → Finance Analysis waiting → UAT Release waiting
COMPLETED_PHASES: Architecture Migration / Global Architecture Gate; MVP1 preflight; MVP1-FOUNDATION-001; MVP1-ORDER-CORE-001
CURRENT_PHASE: MVP1 implementation
CURRENT_WORK_PACKAGE: MVP1-FULFILLMENT-001
PACKAGE_STATUS: COMPLETE / PASS / CLOSED
WAITING_PHASES: MVP1-SYNC-MANUAL-001; MVP1-FINANCE-ORDER-ANALYSIS-001; MVP1-UAT-RELEASE-001; Dev UAT; Production hardening
BASELINE: main / 65345c03bfed3c8b30dec56dec3444ab8b175b20
GITHUB_STATE: Fulfillment Work Package PR #62, Implementation PR #63 and Closure PR #64 MERGED; main closure 518de20b91c401354ecca66f20a17dc95f5e1cf8
SOURCE_CONFLICTS: NONE WITH EVIDENCE
GLOBAL_GATE: PASS
PRODUCTION_DEPLOYMENT_STATUS: NO / no production access or deployment
NEXT_DEPENDENCY: MVP1-SYNC-MANUAL-001 Work Package
EVIDENCE_AS_OF: 2026-08-05
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: 8–14h; planning value 11h
KNOWN_CURRENT_TRACK_REMAINING: MVP1-FULFILLMENT-001; MVP1-SYNC-MANUAL-001; MVP1-FINANCE-ORDER-ANALYSIS-001; MVP1-UAT-RELEASE-001
ARCHITECTURE_GATE_REMAINING: 0h / complete
MVP1_TOTAL_ESTIMATE: 122–190h
MVP1_PLANNING_BASELINE: 166h
MVP1_MAXIMUM_ENVELOPE: 192h
MVP1_REMAINING: UNKNOWN WITH EVIDENCE / accepted actual ledger and package overlap map incomplete
POST_MVP_KNOWN_INCREMENT: approximately 68–120h beyond MVP1
LONG_TERM_CUMULATIVE_RANGE: 235–402h; 603–1,018h; 1,059–1,802h; 1,595–2,714h; 1,819–3,146h
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE / long-term overlap ledger incomplete
COMPLETED_ACTUAL_EFFECTIVE_HOURS: 14.0h accepted across Foundation, Order Core and Fulfillment; project-wide ledger incomplete
ESTIMATE_CONFIDENCE: MEDIUM / inherited implementation reduces new code scope but package contract and final evidence remain bounded
ESTIMATE_AS_OF: 2026-08-05
ESTIMATE_METHOD: frozen MVP1 contract, current architecture boundary and inherited PR #59 implementation evidence
```

## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: 2–3h; planning value 2.5h
CORE_EXECUTION: 4–7h; planning value 5h
TEST_AND_VALIDATION: 1.5–3h; planning value 2.5h
REMEDIATION_BUFFER: 0.5–1h; planning value 1h
ESTIMATED_EFFECTIVE_HOURS: 11h
ESTIMATE_RANGE: 8–14h
ESTIMATE_BASIS: 2.5 + 5 + 2.5 + 1 = 11h; includes authorized review, implementation remediation and local migration validation; excludes human UAT and deployment
OWNER_REVIEW: INCLUDED
MERGE: INCLUDED
MIGRATION: INCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: INCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```
