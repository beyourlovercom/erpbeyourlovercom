# ERP Global Grid Column Preference Stability P1

**OWNER_APPROVED:** 2026-11-17, "好，修复 ERP Global Grid 列设置稳定性 P1 处理".
**EXECUTION_PROVIDER:** GPT_WEB. **VERIFICATION_TIER:** DAILY.
**Repository:** `beyourlovercom/erpbeyourlovercom`.
**Delivery:** one isolated branch / one PR, based on current GitHub `main`.

## Bounded problem and scope

Dede moves SKU and Quantity to the front of the overseas review Global Grid; after multi-select approval and the consequent Inertia data reload, the column arrangement can return to its default. The new Grid must keep each authenticated account's chosen column order after row refresh, review, sorting, filtering, navigation and remount. Different accounts must never read one another's browser preferences. Backend canonical authority stays the existing `user_ui_preferences(user_id, namespace)`.

Implementation scope: `resources/js/Components/DataGrid/OmsGrid.vue` and focused shared Grid regression tests. Preserve all existing UI, column permissions, required/pinned columns, Workspace Tabs, row operations, approval routes, order states and query contracts. No changes to business/order data, Laravel routes, DB schema, auth permissions, production runtime, or legacy non-Global tables.

This package repairs existing interaction/persistence behavior and does not introduce a new control, layout or visual design. The Owner's approved UI remains the design baseline; real Local/UAT acceptance is still required before formal closure/merge readiness.

## Acceptance and safety gates

1. Authenticated users A and B on the same browser retain different layouts for the same namespace; an unowned legacy cache never becomes another account's preference.
2. SKU and Quantity order survives row-only Inertia reloads and regenerated AG Grid column definitions; fixed identity and operation columns keep their required positions.
3. A column edit pending inside the 450 ms debounce is sent on component unmount, using the edit-time snapshot. Concurrent pending saves serialize without reverting newer column order.
4. The existing account preference HTTP Feature test remains green. Focused Vue test, typecheck and frontend build (including Workspace Tabs protection) PASS on the exact candidate.
5. No live review approval, shipment, tracking, order writes, device restart or Production deployment. Prepare Owner to verify the real Dev/Local screen; do not self-declare Owner PASS.

## Rollback

Revert only the P1 shared Grid component and its tests via its own PR revision. No data migration is needed. Existing server preference rows stay untouched.

## Verification evidence (pre-PR)

- Focused Vue/Vitest: 17/17 PASS, including four new account isolation, batch-refresh, unmount and serialized-save scenarios.
- `npx vue-tsc --noEmit`: PASS.
- `npm run build`: PASS, including `ERP_WORKSPACE_TABS_BUILD_PASS` (30 tabs).
- Full frontend suite: 965 PASS / 7 FAIL. The identical seven inventory-batch and warehouse UI assertion failures were reproduced on a separate untouched `94f2cff` baseline worktree; these are pre-existing, out-of-scope and must not be silently modified in the Grid PR.
- PHP `UserUiPreferenceTest` attempted on the US Mac, but the existing checkout lacks `vendor/autoload.php`; no PHP code was changed. Require GitHub CI or properly provisioned isolated US test environment evidence before claiming the backend Feature gate PASS.
- Real Dede Production approval: NOT performed. Owner Local/UAT after exact-candidate verification remains pending.

## PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration → MVP1 Existing Operations/Tencent Docs Replacement → ERP Inquiry → Remote Milestone → Woo Inbound → Tracking Outbound → Sync Orchestration → Product/Media → WordPress Sync → Content/SEO → Posts/Pages → GA4/GSC/Cloudflare → Google Docs → Full Orders → Logistics OS → KOL OS → Inventory/Procurement → Accounting/ROI → Shopify/Integrations → Production Hardening.
CURRENT_TRACK_PHASE_MAP: P1 preflight → shared Grid fix → regression tests/typecheck/build → Owner Local/UAT → gated merge → separately authorized Production deployment.
COMPLETED_PHASES: Global Grid VI V1.1 baseline and account preference table already merged; historical grid preference stability UAT recorded 2026-11-10.
CURRENT_PHASE: Shared Grid P1 repair.
CURRENT_WORK_PACKAGE: ERP-GLOBAL-GRID-COLUMN-PREFERENCE-STABILITY-P1-20261117.
PACKAGE_STATUS: IMPLEMENTATION / OWNER UAT NOT YET PASS.
WAITING_PHASES: Owner real UI review, merge gate, Production release approval.
BASELINE: GitHub main 94f2cff1b428cc1d7c2a0e154bb65f532c0f0177.
GITHUB_STATE: New dedicated branch based on main; no Production mutation.
SOURCE_CONFLICTS: Historical UAT allowed anonymous legacy browser fallback; account-isolation requirement prohibits importing a preference whose user identity cannot be established. Keep scoped legacy fallback and reject unscoped legacy values.
GLOBAL_GATE: Preserve BYL_GLOBAL_DATA_GRID_VI_V1_1 and Workspace Tabs P0, Owner UAT and production gates.
PRODUCTION_DEPLOYMENT_STATUS: NO; no Production deploy in this package.
NEXT_DEPENDENCY: Focused verification and Owner UAT.
EVIDENCE_AS_OF: 2026-11-17 GitHub main, source audit.

## ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 4 effective hours planning, 3–5h range.
KNOWN_CURRENT_TRACK_REMAINING: 3–5h scope estimate before accepted actuals; excludes Owner wait.
ARCHITECTURE_GATE_REMAINING: 0h, historical architecture gate complete.
MVP1_TOTAL_ESTIMATE: 122–190h historical total, not remaining.
MVP1_PLANNING_BASELINE: 166h historical planning value.
MVP1_MAXIMUM_ENVELOPE: 192h total envelope.
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; accepted actual ledger/overlap map incomplete.
POST_MVP_KNOWN_INCREMENT: approximately 68–120h beyond MVP1, historical estimate.
LONG_TERM_CUMULATIVE_RANGE: Architecture 235–402h; first automated MVP 603–1,018h; product/SEO 1,059–1,802h; full core ERP 1,595–2,714h; all expansion 1,819–3,146h; non-additive horizons.
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; cumulative horizons and accepted actuals cannot be safely subtracted.
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; no complete accepted program-wide Work Log ledger.
ESTIMATE_CONFIDENCE: Medium; bounded component and test surface, live UAT unverified.
ESTIMATE_AS_OF: 2026-11-17.
ESTIMATE_METHOD: Code paths and existing tests inspected, small shared-component regression.

## ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.5h planning, 0.4–0.7h range.
CORE_EXECUTION: 1.5h planning, 1.1–1.7h range.
TEST_AND_VALIDATION: 1.5h planning, 1.1–1.8h range.
REMEDIATION_BUFFER: 0.5h planning, 0.4–0.8h range.
ESTIMATED_EFFECTIVE_HOURS: 4.0h.
ESTIMATE_RANGE: 3.0–5.0h.
ESTIMATE_BASIS: Shared Vue/AG Grid state persistence and scoped tests, no schema/API/business flow changes.
OWNER_REVIEW: EXCLUDED
MERGE: INCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
