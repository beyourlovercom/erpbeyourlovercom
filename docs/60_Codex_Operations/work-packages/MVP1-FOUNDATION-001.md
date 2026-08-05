---
type: work-package
project: BYL ERP
work_package_id: MVP1-FOUNDATION-001
title: MVP1 Foundation Shell, Dashboard and Authorization Contract
status: complete-pass-closed
updated: 2026-08-05
---

# MVP1-FOUNDATION-001 — Foundation Shell, Dashboard and Authorization Contract

## Authorization and Scope

```text
OWNER_CONTINUOUS_AUTHORIZATION_ID: BYL-ERP-001F-TO-MVP1-LOCAL-UAT-20260805
BASE_SHA: f4beeaf94e20b896de1905490a54fcb16a78d5b7
TARGET: local repository, local MySQL UAT, local Redis and GitHub governance only
MENU_IMPACT: NO
APPROVED_MENU_CHECK: PASS
REMOTE_DEV_ACCESS: NO
PRODUCTION_ACCESS: NO
HUMAN_UAT: NOT STARTED
```

## Objective

Provide the MVP1 foundation without implementing order, shipment, manual-sync or finance business truth.

## Architecture and Ownership

```text
Dashboard: Dashboard module owns real local counts and the Dashboard experience.
Authorization: Identity module owns permission identifiers and server-side permission middleware.
Shell: Shell keeps existing approved navigation, placeholder behavior and route compatibility.
Shared UI: existing authenticated layout and CSS token conventions remain the only UI foundation.
```

## Database, Runtime and Migration

```text
DATABASE_IMPACT: none; Dashboard returns zero counts before the Orders migration exists.
MIGRATION: no migration authoring or execution in this package.
RUNTIME: local Redis/Queue/Scheduler configuration remains unchanged.
ROLLBACK: revert this package commit; no database rollback required.
```

## Authorization and Audit

Add only the frozen MVP1 permission identifiers and a reusable server-side any-permission middleware. No client-only authorization is accepted. Foundation UI mutations do not exist; existing audit ownership remains unchanged.

## Routes and UI

```text
ROUTES: /dashboard remains the default entry; no approved level-1 or level-2 menu route changes.
UI: Dashboard renders pending orders, pending shipment, shipped-pending-sync and completed-today cards.
DEGRADED_STATE: before the Orders schema exists, all four metrics are zero.
```

## Focused Acceptance

- Architecture and provider tests pass.
- Dashboard component test passes with real metric props.
- Existing shell, menu, route and permission tests pass.
- `git diff --check` passes.
- No `menu/APPROVED_MENU.json` or `config/erp_shell_navigation.php` change occurs.

## Full Validation and Browser Evidence

The final MVP1 release package owns full PHPUnit, Vitest, typecheck, build, Browser E2E, MySQL, Redis, Queue, Scheduler, backup/restore and secret-scan evidence. This package records focused Foundation evidence only and does not claim human UAT.

## Out of Scope

Orders, Logistics, Manual Sync, Finance Analysis, external API writes, webhook, automatic import, tracking write-back, customer OS, product OS, inventory, procurement, accounting OS, remote deployment and human UAT.

## Closure Record

```text
WORK_PACKAGE_PR: #54 MERGED / MAIN SQUASH 8ad42cdd1a24e0395abd48b98f96b041cd251788
IMPLEMENTATION_PR: #55 MERGED / ACCEPTED HEAD c4704185df3c169f061af9c3bd622ff5745a9d87
IMPLEMENTATION_MAIN_SQUASH: de89a11901388ae6d20fb28288f2dd8d385c2451
POST_MERGE_CLOSURE: PR #56 MERGED / MAIN CLOSURE 6ebddab0ab3204044d83df0fea11e4e28b9ec3f5; closure record independently reviewed
MVP1-FOUNDATION-001: COMPLETE / PASS / CLOSED
NEXT: MVP1-ORDER-CORE-001
```

## Estimates and Actuals

```text
PLANNING_ESTIMATE: 4–8h; planning value 6h
ACTUAL_EFFECTIVE_HOURS: 1.5h; Work Log records focused Foundation implementation and validation
ACTUAL_HOURS_BREAKDOWN: 0.5h scope/boundary review; 0.5h implementation; 0.5h focused validation
VARIANCE_HOURS: 0h
VARIANCE_PERCENT: 0%
VARIANCE_REASON: implementation and focused validation matched the planning value used for this bounded subtask
```

## PROJECT POSITION

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Manual Fulfillment / Tencent Docs Replacement → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Customer / Lead Pilot → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Dev UAT and Production Hardening
CURRENT_TRACK_PHASE_MAP: 001F COMPLETE / PASS / CLOSED → MVP1 preflight COMPLETE / PASS / CLOSED → Foundation current → Order Core waiting → Fulfillment waiting → Manual Sync waiting → Finance Analysis waiting → UAT Release waiting
COMPLETED_PHASES: Architecture Migration / Global Architecture Gate; MVP1 preflight
CURRENT_PHASE: MVP1 implementation
CURRENT_WORK_PACKAGE: MVP1-ORDER-CORE-001
PACKAGE_STATUS: COMPLETE / PASS / CLOSED
WAITING_PHASES: MVP1-ORDER-CORE-001; MVP1-FULFILLMENT-001; MVP1-SYNC-MANUAL-001; MVP1-FINANCE-ORDER-ANALYSIS-001; MVP1-UAT-RELEASE-001; Dev UAT; Production hardening
BASELINE: main / 6ebddab0ab3204044d83df0fea11e4e28b9ec3f5
GITHUB_STATE: Work Package PR #54, Implementation PR #55 and Closure PR #56 MERGED; main closure 6ebddab0ab3204044d83df0fea11e4e28b9ec3f5
SOURCE_CONFLICTS: NONE WITH EVIDENCE
GLOBAL_GATE: PASS
PRODUCTION_DEPLOYMENT_STATUS: NO / no production access or deployment
NEXT_DEPENDENCY: MVP1-ORDER-CORE-001
EVIDENCE_AS_OF: 2026-08-05
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: 4–8h; planning value 6h
KNOWN_CURRENT_TRACK_REMAINING: MVP1 implementation and UAT release; 122–190h total estimate
ARCHITECTURE_GATE_REMAINING: 0h / complete
MVP1_TOTAL_ESTIMATE: 122–190h
MVP1_PLANNING_BASELINE: 166h
MVP1_MAXIMUM_ENVELOPE: 192h
MVP1_REMAINING: 122–190h before accepted MVP1 actual ledger
POST_MVP_KNOWN_INCREMENT: approximately 68–120h beyond MVP1
LONG_TERM_CUMULATIVE_RANGE: 235–402h; 603–1,018h; 1,059–1,802h; 1,595–2,714h; 1,819–3,146h
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE / long-term overlap ledger incomplete
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE / project-wide accepted ledger incomplete
ESTIMATE_CONFIDENCE: MEDIUM / bounded Foundation scope
ESTIMATE_AS_OF: 2026-08-05
ESTIMATE_METHOD: focused Foundation scope, implementation and validation classification
```

## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: 1–2h; planning value 1.5h
CORE_EXECUTION: 1–3h; planning value 2.5h
TEST_AND_VALIDATION: 1–2h; planning value 1.5h
REMEDIATION_BUFFER: 0–1h; planning value 0.5h
ESTIMATED_EFFECTIVE_HOURS: 6h
ESTIMATE_RANGE: 4–8h
ESTIMATE_BASIS: 1.5 + 2.5 + 1.5 + 0.5 = 6h
ACTUAL_EFFECTIVE_HOURS: 1.5h
ACTUAL_HOURS_BREAKDOWN: 0.5h scope/boundary review; 0.5h implementation; 0.5h focused validation
VARIANCE: 0h / 0%
VARIANCE_REASON: no variance against the bounded actual ledger entry
OWNER_REVIEW: INCLUDED
MERGE: INCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```
