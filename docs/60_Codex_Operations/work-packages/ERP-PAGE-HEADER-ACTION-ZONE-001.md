---
type: work-package
project: BYL ERP
work_package_id: ERP-PAGE-HEADER-ACTION-ZONE-001
title: Global Page Header Action Zone
status: IMPLEMENTED / VERIFIED / OWNER UAT PASS / DRAFT PR #115 OPEN
updated: 2026-08-29
execution_provider: CLAUDE_CODE
---

# ERP-PAGE-HEADER-ACTION-ZONE-001

## Objective
Standardize page-level actions into one upper-right Page Header Action Zone across existing ERP operational pages already present on current `main`, without changing business logic, routes, controllers, database schema, menu, shell, permissions, or workflow semantics.

## Owner decision
- All page-level actions belong in the page title/header upper-right action cluster.
- This applies to Create, Edit, Show/Detail, Index/List, dashboard and operational workflow pages.
- Typical page-level actions include Cancel, Back/Back to List, Create, Save/Update, Edit, Import, Export, Archive/Restore and Delete.
- Navigation/cancel actions come first; secondary business actions next; destructive actions remain visually separated/confirmation-protected; the dominant primary action is rightmost.
- Narrow screens may wrap the same top action zone, but actions do not move to a bottom action bar.
- Table-row, record-card, field-local, filter/search, pagination and modal-local actions remain in their own context.

## Baseline and source evidence
BASELINE: `origin/main@ead8b2d3fde7e9ddd72a5c3c031d310b2c1b0828` (PR #112 Stabilization merged)
SOURCE_UAT_WORKTREE: `/Users/a/AI/007/worktrees/erp-customer-master-m1-001`
SOURCE_OWNER_UAT: First Batch PASS + Second Batch PASS on 2026-08-28

## Product-page scope — existing on main (23)
1. `resources/js/Pages/Customers/Companies/Create.vue`
2. `resources/js/Pages/Customers/Companies/Edit.vue`
3. `resources/js/Pages/Customers/Companies/Show.vue`
4. `resources/js/Pages/Customers/Companies/Index.vue`
5. `resources/js/Pages/Identity/Users/Create.vue`
6. `resources/js/Pages/Identity/Users/Edit.vue`
7. `resources/js/Pages/Identity/RolesPermissions/Index.vue`
8. `resources/js/Pages/Kol/Create.vue`
9. `resources/js/Pages/Kol/Edit.vue`
10. `resources/js/Pages/Kol/Show.vue`
11. `resources/js/Pages/Kol/Index.vue`
12. `resources/js/Pages/Products/Create.vue`
13. `resources/js/Pages/Products/Edit.vue`
14. `resources/js/Pages/Products/Index.vue`
15. `resources/js/Pages/Products/Import.vue`
16. `resources/js/Pages/Orders/Create.vue`
17. `resources/js/Pages/Orders/Show.vue`
18. `resources/js/Pages/Orders/Index.vue`
19. `resources/js/Pages/Inventory/WarehouseCreate.vue`
20. `resources/js/Pages/Inventory/WarehouseShow.vue`
21. `resources/js/Pages/Finance/OrderAnalysis.vue`
22. `resources/js/Pages/Sync/RunShow.vue`
23. `resources/js/Pages/Sync/WooCommerceInbound.vue`

## Index / Workflow audit boundary
The Owner-approved second-batch audit originally identified 17 candidate Index / Workflow pages. Two are explicit no-change exceptions because their actions are not page-level:
- `resources/js/Pages/Inventory/Sync.vue`: import buttons are workflow-step-local actions.
- `resources/js/Pages/Logistics/Index.vue`: save/ship/copy buttons are per-order record-card actions.

The remaining applicable Index / Workflow pages are migrated only when they already exist on current `main`; feature-only Customer/Support pages stay with their own module PRs.

## Feature-only exclusions — do not introduce in this PR
- Customer Library Create/Edit/Show/Index
- Customer Subscribers Create/Edit/Index
- Support Calls Create
- Support Quality Issues Create/Index
- Support Tickets Create/Edit/Show/Index

These 14 pages already passed Owner UAT in the source worktree but are not present on current `main`; their approved Page Header Action Zone layout must travel with their owning feature delivery later.

## Governance / test scope
- Update `AGENTS.md` with the global HIGHEST PAGE-LEVEL ACTION PLACEMENT GATE.
- Update `docs/10_Planning_Roadmap/18_BYL_ERP_FORM_DESIGN_STANDARD_V1.md` with the global rule.
- Add/update `tests/Architecture/PageHeaderActionStandardTest.php` so only current-main applicable pages are enforced, while the two audited no-change exceptions remain documented by scope rather than false UI mutation.
- Do not modify global menu or AppShell behavior.

## Implementation constraints
- Preserve all existing route targets, permission conditions, submit handlers, request payloads, validation behavior and record semantics.
- Prefer surgical action-placement changes over full-file replacement when current `main` differs from the older UAT source.
- Reuse existing page/button classes where possible; add only minimal scoped layout CSS needed for the upper-right cluster.
- For Create/Edit forms, a header submit button may target the existing form via `form="..."`; do not duplicate submit actions at the bottom.
- Destructive actions remain confirmation-protected.
- Section/subform actions remain local when their scope is not the whole page (for example password/role/permission subforms).
- `Inventory/Sync.vue` and `Logistics/Index.vue` remain unchanged unless a concrete page-level action is discovered during exact-main inspection; any scope expansion requires GPT/Owner review.

## Acceptance criteria
1. Every in-scope page-level action is discoverable in one upper-right header action zone.
2. No migrated Create/Edit page retains a duplicate bottom page-level action bar.
3. Local row/card/field/filter/modal/workflow-step actions remain contextual.
4. Order Create/Show preserve current post-Stabilization business behavior and edit-mode semantics.
5. No feature-only Customer Library/Subscribers/Support files are introduced.
6. No controller, route, migration, menu, shell, permission or data-model change is included.
7. Global rule documentation and Architecture enforcement are present.
8. Source Owner UAT semantics are preserved on the exact current-main transfer.

## Validation
VERIFICATION_TIER = DAILY
Required on US Mac before delivery:
- `git diff --check`
- Architecture gate for Page Header Action Zone
- relevant Customer Company / Identity / KOL / Product / Order / Inventory / Finance / Sync focused tests available on current main
- `npm run typecheck`
- fresh `npm run build`
- inspect `public/build/manifest.json`
- inspect generated bundle for concrete Page Header Action Zone signatures
- confirm `public/hot` state
- confirm actual Local UAT response references the fresh bundle when a local runtime is used

No China verification is required by default. No production deployment is authorized.

## Verification evidence — exact-main delivery
- Exact baseline: `origin/main@ead8b2d3fde7e9ddd72a5c3c031d310b2c1b0828`.
- Page Header package-focused suite: **117 passed / 635 assertions**.
- Architecture Page Header gate: PASS.
- `npm run typecheck`: PASS.
- Fresh `npm run build`: PASS.
- `public/hot`: absent.
- Fresh manifest entry: `resources/js/app.ts -> assets/app-CLyrV5ZO.js`; CSS `assets/app-P-zftk6H.css`.
- Bundle signatures confirmed: `page-header-actions`, `order-create-form`, `product-create-form`, `product-edit-form`, `product-import-form`, `创建订单`, `保存订单`, `保存权限`, `导出 CSV`.
- Actual isolated runtime: `http://127.0.0.1:18112/login` returned `app-CLyrV5ZO.js` and `app-P-zftk6H.css`; listener cwd resolved to this Work Package worktree.
- Broad related suite produced 14 failures; exact detached baseline replay on unmodified `main@ead8b2d` reproduced the same 14 failures. Classification: **PRE-EXISTING CURRENT-MAIN BASELINE FAILURES / NOT CAUSED BY THIS PACKAGE**.
- `package.json` and `package-lock.json` remain unchanged after dependency provisioning.
- `git diff --check`: PASS.

## Dependencies / parallel safety
DEPENDENCIES: PR #112 MVP1 Stabilization already merged to current main.
DEPENDENCY_TYPE: current-main UI baseline only.
DEPENDENCY_STATUS: CLEAR.
PARALLEL_SAFE: YES — dedicated worktree and branch; Customer Master/Support feature worktree is read-only source evidence only.
FOUNDATION_CHANGE_REQUIRED: NO — this package standardizes action placement without changing shell/menu foundations.

# PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration [COMPLETE] -> MVP1 Existing Operations -> ERP-INQUIRY-M1 -> milestone release -> MVP1.1 WooCommerce Inbound -> MVP1.2 Tracking Outbound -> MVP1.3 Sync Orchestration -> Product/Media -> Content/SEO -> Full Order Workspace -> Logistics OS -> KOL OS -> Inventory/Procurement -> Accounting/ROI -> Integrations -> Production Hardening
CURRENT_TRACK_PHASE_MAP: Page Header Action Zone formalization [COMPLETE] -> exact-main transfer [COMPLETE] -> verification [COMPLETE] -> Draft PR #115 [COMPLETE] -> Owner merge gate [CURRENT]
COMPLETED_PHASES: Source layout implementation; First Batch Owner UAT PASS; Second Batch Owner UAT PASS; exact-main isolated transfer; package verification
CURRENT_PHASE: Owner merge gate
CURRENT_WORK_PACKAGE: ERP-PAGE-HEADER-ACTION-ZONE-001
PACKAGE_STATUS: IMPLEMENTED / VERIFIED / OWNER UAT PASS / DRAFT PR #115 OPEN
WAITING_PHASES: Owner merge authorization
BASELINE: origin/main@ead8b2d3fde7e9ddd72a5c3c031d310b2c1b0828
GITHUB_STATE: Draft PR #115 OPEN against main; delivery head 439cd36cdabb9c5fa50ba9680c87485a3a06d1c0 before this closure-only governance commit
SOURCE_CONFLICTS: AI/CURRENT_CONTEXT.md main SHA is stale; verified GitHub origin/main is authoritative for this package
GLOBAL_GATE: PASS
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE; no deployment authorized
NEXT_DEPENDENCY: Owner review and explicit merge authorization
EVIDENCE_AS_OF: 2026-08-29

# ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 1.5h planning value; 1.0-2.5h range
KNOWN_CURRENT_TRACK_REMAINING: exact-main transfer, verification and PR preparation only
ARCHITECTURE_GATE_REMAINING: 0h global gate; package-specific Architecture test remains
MVP1_TOTAL_ESTIMATE: historical value unchanged
MVP1_PLANNING_BASELINE: historical value unchanged
MVP1_MAXIMUM_ENVELOPE: historical value unchanged
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; this UI standardization does not recalculate MVP1
POST_MVP_KNOWN_INCREMENT: NONE WITH EVIDENCE
LONG_TERM_CUMULATIVE_RANGE: unchanged from canonical roadmap
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE
ESTIMATE_CONFIDENCE: HIGH
ESTIMATE_AS_OF: 2026-08-28
ESTIMATE_METHOD: bounded transfer of already Owner-UAT-approved UI placement onto current main + regression verification

# ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.25h
CORE_EXECUTION: 0.65h
TEST_AND_VALIDATION: 0.40h
REMEDIATION_BUFFER: 0.20h
ESTIMATED_EFFECTIVE_HOURS: 1.5h
ESTIMATE_RANGE: 1.0-2.5h
ESTIMATE_BASIS: existing Owner-UAT-approved UI placement transferred surgically to current main across 23 pages, plus rule/test delivery and regression verification
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: INCLUDED
DEPLOYMENT: EXCLUDED
