# ERP Order Operational Archive — 2026-11-04

EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK
BASELINE: db5ec404 on feature/erp-order-operational-archive-20261104
DEPENDENCIES: NONE
DEPENDENCY_TYPE: NONE
PARALLEL_SAFE: NO (single dedicated worktree)

## Scope

Add a separate operational archive state. orders.archived_at remains the legacy trash/preserved-evidence field and is not reused. Operational archive fields are nullable and reversible: operational_archived_at and operational_archived_by.

The model exposes scopeEligibleForOperations() and isOperationallyArchived(). Normal order listing/export and the covered allocation/status queries exclude operationally archived roots and derived children without adding a global scope.

## Routes and behavior

- GET /orders/archive
- POST /orders/{order}/archive
- POST /orders/archive/{order}/restore

Archive requires orders.edit and KOL scope; restore requires system.permissions.manage and order visibility. Archive/restore use transactions, row locks and server audit records. Derived children cannot be archived directly; their root is the archive authority. Existing trash routes are unchanged.

Order Detail adds the compact 归档订单 button after the existing marker buttons with explicit confirmation. The dedicated list supports admin restore. Archived detail remains readable but the touched order mutation paths return 409.

## Verification evidence — 2026-11-04 (US Mac isolated worktree)

- Git diff check: PASS.
- PHP syntax on all modified/new PHP files: PASS.
- PHPUnit tests/Feature/Orders: **75 tests, 923 assertions, PASS** (includes 4 new archive tests / 68 assertions).
- Frontend TypeScript typecheck: PASS.
- Vite production build: PASS (pre-existing asset-size warning).
- PHPUnit tests/Feature/Logistics: 547 tests, 26 failures, 1 skipped; compared against the untouched same-SHA origin/main baseline with 547 tests, 38 failures. Failure names are a proper subset of baseline failures: **0 newly failing tests**, 12 previously failing tests no longer failing. Existing failures remain and are not falsely reported as PASS.
- New archive tests use SQLite in-memory DB, not customer or production databases.
- No production migration, deployment, live warehouse provider call, external site or real order mutation was performed.

## Operational coverage and release gate

Implemented exclusion from normal order list/export; wait-stock query; domestic factory views/counts/exports; overseas warehouse views/counts/exports; all main logistics queues/status counters; automatic allocation and warehouse batch selection. Direct guards cover order edit/markers/workflow/trash; shipment service mutations; allocation and domestic/overseas review; China inline tracking; US-3SM manual export; ShipOut dispatch/preflight/tracking apply; XLWMS web-draft and reshipment; reallocation and replacement fulfillment.

**Limitations:** exhaustive audit of historical-only import/maintenance paths and in-flight external warehouse calls was not completed. Third-party operations already sent before archive cannot be cancelled retroactively. Production migration and actual role-based browser UAT are pending Owner release gate. Archived orders remain readable within their detail/financial/logistic history, but cannot enter ordinary work queues. Do not release if the operator requires a demonstrably complete guarantee against already in-flight provider calls.

## Project Position

PROGRAM_ROADMAP: Architecture Migration → MVP1 Existing Operations → ERP-INQUIRY-M1 → WooCommerce Inbound → Tracking Outbound → Sync Orchestration → Product/Media → Content/SEO → Google Publishing → Full Order Workspace → Logistics OS → KOL OS → Inventory/Procurement → Accounting/ROI → Shopify/Additional Integrations → Production Hardening
CURRENT_TRACK_PHASE_MAP: Order operational archive implementation and local static verification; Owner/controller review pending
COMPLETED_PHASES: Preflight; model/migration/controller/routes/UI implementation; focused archive tests; Orders regression; baseline-compared Logistics regression; PHP and frontend static/build verification
CURRENT_PHASE: Browser UAT verified; waiting Owner visual PASS
CURRENT_WORK_PACKAGE: ERP-ORDER-OPERATIONAL-ARCHIVE-20261104
PACKAGE_STATUS: UAT_BROWSER_PASS_OWNER_REVIEW_REQUIRED
WAITING_PHASES: Owner visual PASS; production release approval; production migration execution
BASELINE: db5ec404
GITHUB_STATE: Dedicated branch; GitHub PR delivery pending
SOURCE_CONFLICTS: NONE WITH EVIDENCE
GLOBAL_GATE: HIGH_RISK migration and authorization change; no migration execution
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE — no deployment action taken
NEXT_DEPENDENCY: Owner visual UAT and controlled production migration/release; investigate existing Logistics baseline failures separately
EVIDENCE_AS_OF: 2026-11-04

## Roadmap Hours

CURRENT_PACKAGE_ESTIMATE: 4.0 hours planning value; 3–6 hour range
KNOWN_CURRENT_TRACK_REMAINING: Focused tests, exhaustive mutation-path audit, review
ARCHITECTURE_GATE_REMAINING: NONE WITH EVIDENCE
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: NONE
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — no accepted Work Log ledger provided
ESTIMATE_CONFIDENCE: MEDIUM
ESTIMATE_AS_OF: 2026-11-04
ESTIMATE_METHOD: bounded implementation plus static verification; excludes unrun test/build work

## Estimated Codex Hours

PREFLIGHT_AND_SCOPE: 0.5
CORE_EXECUTION: 2.5
TEST_AND_VALIDATION: 0.5
REMEDIATION_BUFFER: 0.5
ESTIMATED_EFFECTIVE_HOURS: 4.0
ESTIMATE_RANGE: 3–6 hours
ESTIMATE_BASIS: bounded frozen archive contract; broad logistics audit remains excluded
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED

## UAT — 2026-11-04 / Synthetic browser session

Environment: independent UAT SQLite database and loopback-only Laravel server on US Mac, http://127.0.0.1:18865. Five synthetic orders prefixed 007-UAT-1104; all real Woo/ERP production orders and third-party API calls were untouched.

- Live browser round-trip: 15/15 checks PASS; archive confirmation, archive-list redirect, normal orders exclusion, wait-stock exclusion, MorePlus overseas-list exclusion, admin restore, no browser JavaScript errors, desktop/mobile render and no page-level mobile overflow.
- Second round after fixing UAT visual issues: 15/15 PASS again; changed display from raw status code to Chinese, operator user ID to display name, and horizontal-scrolling mobile list to cards with visible restore buttons.
- Derived fulfillment browser UAT: parent archived -> root only in archive list, derived child excluded from normal overseas queue -> parent restored; PASS.
- China factory browser UAT: pending review row visible -> archived and hidden from China review -> restored and visible again; PASS.
- Persisted audit records confirmed in local SQLite: archive and restore both recorded, with original order records restored unchanged after the test.
- Isolated migration was run only against the new UAT SQLite file. No remote provider dispatch, Production DB migration or Production web deployment.
- Screenshots preserved on US Mac: /tmp/byl-erp-archive-uat-flags-desktop.png, /tmp/byl-erp-archive-uat-flags-mobile.png, /tmp/byl-erp-archive-uat-mobile-list.png, /tmp/byl-erp-archive-uat-desktop-list.png.

Review gate remains: Owner visual PASS and a separately authorized controlled Production release/migration. The private localhost UAT address is not a public URL; an externally accessible preview has not been deployed.

## Mainline reconciliation — 2026-11-04

- While Draft PR #615 was in UAT, origin/main advanced to b685d03f (PR #616, preserving Woo Processing orders in pending allocation; preceded by PR #613).
- Merge conflict limited to app/Modules/Logistics/Application/LogisticsStatusSummary.php. The final pending allocation query composes PendingAllocationEntryPolicy::constrain(Order::query()->eligibleForOperations()) so it preserves current admission semantics AND excludes operationally archived orders.
- Same-SHA baseline comparison against untouched b685d03f: Logistics tests 550 / 37 failures on main versus 550 / 25 failures on this branch, **0 newly failing test names**, 12 baseline failures no longer reproducing.
- Order feature tests 75 / 75 PASS, 925 assertions; TypeScript typecheck PASS; Vite production build PASS.
- Real browser after mainline sync: archive/list/restore 15/15 PASS, derived-child inheritance PASS, China factory queue exclusion/restore PASS, JavaScript page errors 0.
- Merge into Production/main and live migration remain NOT authorized. This is an isolated branch reconciliation only.
