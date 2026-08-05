---
type: work-package
project: BYL ERP
work_package_id: MVP1-ORDER-CORE-001
title: Order Core, Items, Address Snapshot and External References
status: complete-pass-closed
updated: 2026-08-05
---

# MVP1-ORDER-CORE-001 — Order Core, Items, Address Snapshot and External References

## Authorization and Scope

```text
OWNER_CONTINUOUS_AUTHORIZATION_ID: BYL-ERP-001F-TO-MVP1-LOCAL-UAT-20260805
STANDARD_ID: LARGE-PROJECT-EXEC-001
STANDARD_VERSION: 1.1
BASE_SHA: 561ba46e8a308ffa28c9b06d7485cedde5850c9e
TARGET: local repository, local MySQL UAT, local Redis and GitHub governance only
MENU_IMPACT: NO
APPROVED_MENU_CHECK: PASS
REMOTE_DEV_ACCESS: NO
PRODUCTION_ACCESS: NO
SSH: NO
HUMAN_UAT: NOT STARTED
```

## Objective

Establish the single MVP1 order truth for manually entered `website`, `kol` and `manual_other` orders. The package owns order identifiers, recipient/address snapshots, items, controlled source/status semantics, owner assignment, external references, server-side validation and the order list/detail/correction workflow.

## Architecture and Ownership

```text
ORDER_TRUTH: app/Modules/Orders/**
ORDER_APPLICATION: OrderService owns creation, correction, source/status validation and mutation transactions
ORDER_EXPERIENCE: OrderController and module routes own HTTP authorization, Inertia props and CSV response
ORDER_INFRASTRUCTURE: Order, OrderAddress, OrderItem and OrderExternalReference own persistence
RECIPIENT: immutable-at-creation order snapshot; no Customer OS is introduced
AUDIT: Audit module records order.created and order.updated with actor and timestamp
```

No private cross-module imports are added. Fulfillment, manual Sync and Finance consume the Orders contract and do not create a second order truth.

## Frozen Business Contract

```text
SOURCE_TYPES: website; kol; manual_other
INITIAL_ORDER_STATUS: pending_shipment
ORDER_STATUSES: pending_shipment; shipped; cancelled
IDENTIFIER: unique internal_order_number generated server-side
EXTERNAL_ORDER_NUMBER: nullable, stored as an external reference
ADDRESS: recipient_name; phone; country; province_state; city; address_line_1; address_line_2 nullable; postal_code nullable
ITEMS: product_summary; sku nullable; quantity; unit_price; line_revenue
OWNER: nullable User reference, server-authorized
```

The package does not import WooCommerce, call external APIs, create customers, or implement product/catalog truth.

## Database and Migration

Create the Orders tables in a single forward-compatible migration with foreign keys, unique identifiers, indexes for list filters and safe down behavior. The migration must pass fresh apply, rollback and re-apply on local disposable MySQL 8.4.x and the repository's SQLite test database where supported.

```text
DATABASE_IMPACT: orders; order_addresses; order_items; order_external_references
MIGRATION: database/migrations/2026_08_05_000000_create_orders_tables.php
ROLLBACK: migrate:rollback removes only the four tables owned by this migration after dependent package tables are absent
RE-RUN: fresh migration and rollback/re-apply must be deterministic
BACKUP: no production or remote database access; local UAT backup remains a later UAT Release gate
```

## Authorization and Audit

```text
READ: orders.view
CREATE: orders.create
CORRECT: orders.edit
VALIDATION: server-side FormRequest/controller validation; client validation is supplementary only
AUDIT_CREATE: order.created
AUDIT_UPDATE: order.updated with actor and timestamp
```

Forbidden requests return the repository-standard authorization response. No client-only permission gate is accepted.

## Routes and UI

```text
GET  /customers/orders              orders.index
GET  /customers/orders/create       orders.create
POST /customers/orders              orders.store
GET  /customers/orders/{order}      orders.show
PATCH /customers/orders/{order}     orders.update
GET  /customers/orders/export       orders.export
GET  /kol/orders                    legacy-compatible filtered order list
GET  /kol/orders/{order}            legacy-compatible order detail
```

The approved level-1 and level-2 menu baseline is unchanged. The UI is Chinese and desktop-first, reuses the accepted shell/layout, supports list search/filter, details, items, address, source, owner, status, notes and correction, and contains no production mock data.

## Acceptance Criteria

- A permitted operator creates one valid order with one or more items and a unique internal order number.
- Invalid source, missing recipient/address/item fields, non-positive quantity or invalid monetary input is rejected server-side.
- `website`, `kol` and `manual_other` are persisted using the frozen source dictionary.
- Recipient/address data is stored as an order snapshot and correction updates it atomically with item corrections where supplied.
- Order list search/filter and CSV export use server-side filters and do not leak unauthorized fields.
- Create and correction mutations record actor/timestamp audit events.
- Restricted operators can read only when granted `orders.view` and cannot create/correct without the mutation permission.
- Fresh migration, rollback and re-apply are deterministic on local disposable databases.
- Focused, architecture, full regression and Browser E2E evidence is recorded by the implementation and UAT packages; this Work Package does not claim human UAT.

## Validation Contract

```text
FOCUSED: php artisan test tests/Feature/Mvp1/Mvp1WorkflowTest.php; php artisan test --testsuite=Architecture
REGRESSION: composer validate --strict; php artisan test; npm test; npm run typecheck; npm run typecheck:e2e; npm run build; npm run test:e2e
STATIC: git diff --check; git fsck --full; secret scan
DATABASE: fresh migrate; rollback/re-apply; schema/index/FK assertions on local MySQL 8.4.x
RUNTIME: no remote access; Redis/Queue/Scheduler evidence belongs to final UAT Release gate
```

## Work Package Governance

```text
WORK_PACKAGE_BRANCH: codex/mvp1-order-core-001-work-package
WORK_PACKAGE_PR: existing package record on main; no separate package PR was needed after restored baseline audit
IMPLEMENTATION_PR: #59 MERGED / ACCEPTED HEAD 3956f206e63dc77142f0d353f2ad3cede3816a19 / MAIN SQUASH 5ce98ad34b4adac0e931e348bfce0961e3d4bfd6
CLOSURE_PR: #60 MERGED / MAIN CLOSURE fb58cb14c3aa2c998abc1a4d4b2aab2e3634db26
MERGE: authorized by continuous Owner authorization
IMPLEMENTATION_BRANCH: codex/mvp1-order-core-001
```

## Out of Scope

Automatic WooCommerce import, webhooks, tracking write-back, batch external sync, Customer/Lead OS, Product/Media OS, Inventory, Procurement, full Accounting, Google Docs, remote deployment, production access, real external API writes and human UAT.

## Closure Evidence

```text
IMPLEMENTATION_STATUS: COMPLETE / PASS / MERGED
FOCUSED_WORKFLOW: 4 tests / 56 assertions / PASS
ARCHITECTURE: 16 tests / 201 assertions / PASS / 1 pre-existing risky test
FULL_PHPUNIT: 156 tests / 1567 assertions / PASS / 2 intentional isolated-runtime skips / 1 pre-existing risky test
VITEST: 71 tests / PASS
TYPECHECK: PASS
TYPECHECK_E2E: PASS
BUILD: PASS
BROWSER_E2E: 16/16 PASS / disposable SQLite / external run root / cleanup PASS
MYSQL: local MySQL 8.4.11 / byl_erp_local_uat / fresh migration, seed and schema PASS
UAT_DATASET: 7 orders / 4 shipments / 3 manual syncs / 1 financial / 10 cost entries
BACKUP: PASS / .codex/backups/byl-erp-local-uat-20260805-final.sql / SHA-256 e4e998f7f8b2fa1b5ee9510b5c79bc6cef98aa0617579f8bffa8e00282445593
RESTORE_REHEARSAL: PASS / independent local MySQL restore database / removed after verification
REDIS: PASS / local Redis 8.10 / 127.0.0.1:16379
MENU_IMPACT: NO
APPROVED_MENU_CHECK: PASS
REMOTE_DEV_ACCESS: NO
PRODUCTION_ACCESS: NO
SSH: NO
HUMAN_UAT: NOT STARTED
MVP1-ORDER-CORE-001: COMPLETE / PASS / CLOSED
NEXT: MVP1-FULFILLMENT-001
```

## Estimates and Actuals

```text
PLANNING_ESTIMATE: 10–16h; planning value 13h
ACTUAL_EFFECTIVE_HOURS: 4.5h
ACTUAL_HOURS_BREAKDOWN: 1.0h recovery/audit; 1.5h implementation/remediation; 1.0h focused/full validation; 0.5h local MySQL/UAT runtime; 0.5h PR governance and closure
VARIANCE: -8.5h / -65.38%
VARIANCE_REASON: restored implementation covered the bounded MVP1 business flow; remediation was limited to Provider registration, route ownership, model relationships and fixed architecture evidence.
```

## PROJECT POSITION

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Manual Fulfillment / Tencent Docs Replacement → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Customer / Lead Pilot → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Dev UAT and Production Hardening
CURRENT_TRACK_PHASE_MAP: 001F COMPLETE / PASS / CLOSED → MVP1 preflight COMPLETE / PASS / CLOSED → Foundation COMPLETE / PASS / CLOSED → Order Core COMPLETE / PASS / CLOSED → → Fulfillment current → Manual Sync waiting → Finance Analysis waiting → UAT Release waiting
COMPLETED_PHASES: Architecture Migration / Global Architecture Gate; MVP1 preflight; MVP1-FOUNDATION-001; MVP1-ORDER-CORE-001
CURRENT_PHASE: MVP1 implementation
CURRENT_WORK_PACKAGE: MVP1-FULFILLMENT-001
PACKAGE_STATUS: ORDER CORE COMPLETE / PASS / CLOSED; FULFILLMENT ACTIVE / IMPLEMENTATION
WAITING_PHASES: MVP1-SYNC-MANUAL-001; MVP1-FINANCE-ORDER-ANALYSIS-001; MVP1-UAT-RELEASE-001; Dev UAT; Production hardening
BASELINE: main / 5ce98ad34b4adac0e931e348bfce0961e3d4bfd6
GITHUB_STATE: Implementation PR #59 and Closure PR #60 MERGED; accepted implementation Head 3956f206e63dc77142f0d353f2ad3cede3816a19; implementation main squash 5ce98ad34b4adac0e931e348bfce0961e3d4bfd6; closure main squash fb58cb14c3aa2c998abc1a4d4b2aab2e3634db26
SOURCE_CONFLICTS: NONE WITH EVIDENCE
GLOBAL_GATE: PASS
PRODUCTION_DEPLOYMENT_STATUS: NO / no production access or deployment
NEXT_DEPENDENCY: MVP1-FULFILLMENT-001 Work Package and implementation
EVIDENCE_AS_OF: 2026-08-05
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: 10–16h; planning value 13h
KNOWN_CURRENT_TRACK_REMAINING: MVP1-FULFILLMENT-001; MVP1-SYNC-MANUAL-001; MVP1-FINANCE-ORDER-ANALYSIS-001; MVP1-UAT-RELEASE-001; 122–190h total MVP1 estimate before accepted actual ledger
ARCHITECTURE_GATE_REMAINING: 0h / complete
MVP1_TOTAL_ESTIMATE: 122–190h
MVP1_PLANNING_BASELINE: 166h
MVP1_MAXIMUM_ENVELOPE: 192h
MVP1_REMAINING: UNKNOWN WITH EVIDENCE / accepted actual ledger and package overlap map incomplete
POST_MVP_KNOWN_INCREMENT: approximately 68–120h beyond MVP1
LONG_TERM_CUMULATIVE_RANGE: 235–402h; 603–1,018h; 1,059–1,802h; 1,595–2,714h; 1,819–3,146h
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE / long-term overlap ledger incomplete
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE / project-wide accepted ledger incomplete
ESTIMATE_CONFIDENCE: MEDIUM / bounded Order Core scope with restored implementation evidence
ESTIMATE_AS_OF: 2026-08-05
ESTIMATE_METHOD: frozen MVP1 contract, existing module architecture and focused implementation scope
```

## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: 2–3h; planning value 2.5h
CORE_EXECUTION: 5–8h; planning value 6h
TEST_AND_VALIDATION: 2–3h; planning value 2.5h
REMEDIATION_BUFFER: 1–2h; planning value 2h
ESTIMATED_EFFECTIVE_HOURS: 13h
ESTIMATE_RANGE: 10–16h
ESTIMATE_BASIS: 2.5 + 6 + 2.5 + 2 = 13h; includes authorized PR governance and migration authoring, excludes human UAT and deployment
ACTUAL_EFFECTIVE_HOURS: 4.5h for Order Core
ACTUAL_HOURS_BREAKDOWN: 1.0h recovery/audit; 1.5h implementation/remediation; 1.0h validation; 0.5h local runtime; 0.5h governance
VARIANCE: -8.5h / -65.38% against Order Core issued 13h planning value
VARIANCE_REASON: restored implementation evidence reduced new implementation scope; remediation and governance remained bounded
OWNER_REVIEW: INCLUDED
MERGE: INCLUDED
MIGRATION: INCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: INCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```
