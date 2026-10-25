# ERP-INVENTORY-INLINE-EDIT-HOVER-V1-001

## Contract and authority

- GitHub Issue: #505, `Inventory OS | 仓库库存表格原位编辑 V1 — hover-only 铅笔显示`.
- Issue scope is active Owner-approved after the final visual design pass. No second design approval is required before implementation; live Local/UAT remains an Owner gate.
- Worktree: `/Users/a/AI/007/worktrees/erp-inventory-inline-edit-hover-v1`.
- Branch baseline: `feature/erp-inventory-inline-edit-hover-v1` at `60ccafc2` before implementation.
- Execution provider: `EXECUTION_PROVIDER: US_CODEX_CLI`.
- Verification classification: `VERIFICATION_TIER = HIGH_RISK`.
- Delivery: one separate Draft PR only; do not merge `main`, deploy Production, or mutate Production data.

## Scope

Implement click-to-edit cells in `Inventory/WarehouseShow.vue` for SKU, product name, quantity on hand, quantity reserved, location, inbound date, standard QoH, and expected restock quantity. Each source cell owns its own hover/focus-visible pencil; no row-wide hover affordance and no permanent save button. Enter saves; Escape and blur cancel. Keep the existing clearance action and Excel batch import.

Computed cells (available quantity, stock status, 90-day shipments, replenishment recommendation) remain canonical read-only projections. Their click affordance opens an inline explanation/source inspector and never writes a fabricated value.

## Mandatory backend modules

- Warehouse controller and inventory routes: enforce `inventory.operate`, warehouse slug plus inventory-record scope, field allowlist, validation, expected timestamp and JSON/Inertia error behavior.
- `InventoryService` and `InventoryRepository`: single-field updates only, transaction, consistent lock order, `lockForUpdate`, optimistic stale detection, non-negative integer invariants, reserved-quantity protection, and existing audit / `InventoryAdjustment` paths.
- Products: SKU/name are global Product OS fields; enforce `products.operate`, call `ProductService::updateProduct`, preserve its SKU/alias/external-reference checks, and check product optimistic freshness before update.
- Warehouse payload: expose edit permissions and timestamps needed by the UI; do not expose a product edit control when the actor lacks product permission.
- Tests: bounded PHPUnit feature coverage for permissions, CRUD, isolation, stale/concurrency, reservation/negative rules, alias conflicts, and projection recalculation; targeted Vue/Vitest or DOM/CSS assertions for cell-local hover/focus affordance and keyboard behavior.

## UI contract

- SKU and product name render as two independent spans/cells within the identity cell and can be edited independently.
- Pencil visibility is scoped to the hovered/focused cell only, default hidden, and absent when the actor lacks the relevant permission.
- Edit state is local to one cell. Prevent duplicate submits; preserve the original value on failure; show a clear error; refresh the latest warehouse payload after success.
- Remove the existing invalid permanent row-level `保存` action. Keep `加入清仓` as its row action.
- Computed-column inspector explains its canonical source: shipment evidence for shipped quantity, inventory formula for availability/status, and replenishment policy/manual supplement configuration for recommendation.
- Desktop-only UAT; no mobile UAT is in scope.

## Safety and prohibitions

- Never send a stale full-row snapshot for a cell edit.
- Never update a warehouse inventory record without both warehouse and record scope.
- Never permit `quantity_reserved > quantity_on_hand`, negative integer quantities, or a reserved decrease below active order reservation occupancy.
- Never update `Product` directly from the controller; use `ProductService::updateProduct` after the cross-warehouse confirmation in the UI and server-side permission/freshness checks.
- Do not alter canonical formulas, shipment evidence, external Woo mappings, Excel import behavior, menus, Production, or other worktrees.

## Acceptance and verification

1. `git diff --check` and clean scoped diff review.
2. Bounded PHPUnit inventory/product tests, including warehouse isolation, authorization, stale update conflict, no full-row overwrite, no negatives, reserved protection, SKU/alias collision, and projection refresh.
3. Targeted Vitest tests covering cell-local hover/focus CSS/DOM and Enter/Escape/blur no-duplicate behavior.
4. `npx vue-tsc --noEmit` or repository typecheck/build as practical.
5. Prepare Local/UAT pages for Owner inspection only after implementation and automated gates pass; do not claim screenshots as deployment evidence.

## Evidence log

- Issue #505 body read from GitHub before implementation; updated scope explicitly includes every-column click response and the integrity-sensitive computed-column boundary.
- Initial worktree status was clean at the requested branch/HEAD.
- Initial `git fetch origin main --prune` required approved read-only escalation because the shared worktree metadata was not writable inside the sandbox; latest remote state was checked before mutation.

## Final report

Implementation result: completed the warehouse current-inventory click-to-edit contract for SKU, product name, quantity on hand, quantity reserved, location, inbound date, standard QoH, and expected restock quantity. Source-field pencils are hidden by default and scoped to each cell hover/focus; row-wide hover and the invalid permanent `保存` action are removed. Enter submits once; Escape/blur cancel. Success reloads the warehouse projection; failures cancel back to the original value and alert the server error. Computed availability/status/shipped-90d/replenishment cells remain canonical read-only inspectors with source guidance.

Backend result: added the scoped `/stock/{inventory}/cell` PATCH, `inventory.operate` route gate, warehouse+record query scope, warehouse then row locks, expected timestamp checks, field allowlist/typed validation, `quantity_reserved <= quantity_on_hand`, active reservation floor protection, existing repository `InventoryAdjustment`, and audit recording. SKU/name edits require `products.operate`, product version freshness, direct SKU collision check, and `ProductService::updateProduct`; no direct Product model update was added. Excel import and clearance action remain intact.

Verification evidence:

- `git diff --check`: PASS.
- PHP lint for changed PHP files: PASS.
- `php artisan test tests/Feature/Inventory/WarehouseInlineEditV1Test.php tests/Feature/Inventory/WarehouseClearanceGiftPoolV1Test.php tests/Feature/Inventory/WarehouseRestockPlanTest.php --compact`: PASS, 14 tests / 110 assertions.
- `npm run test -- --run resources/js/Pages/Inventory/WarehouseRestockPlan.test.ts`: PASS, 3 tests.
- `npm run typecheck`: PASS.
- `npm run build`: PASS; existing Vite chunk-size warning only.
- `tests/Architecture/ModuleArchitectureTest.php`: not green on this baseline because its frozen module-directory and MIG-001 migration file lists already omit directories/migrations present in the checkout; no baseline file was changed in this package.

GitHub CI evidence: PR #506 (GitHub Actions run 40158757772) returned failure for mysql8-runtime and sqlite-regression jobs within ~1 second with no runner assigned and no steps; similar runner-less failures are visible on Oct 23 historical runs. These are NOT counted as code test failures or CI PASS, and merge readiness is blocked pending runner recovery or an approved equivalent required check.

Owner UAT state: implementation and automated verification complete; live Desktop Local/UAT inspection remains pending and is the required closure gate. No screenshots are claimed as deployment evidence. No Production database, deployment, merge, or other worktree was touched.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Existing Operations / Tencent Docs Replacement → ERP-INQUIRY-M1 B2B Inquiry Foundation → ERP-INQUIRY-M1 Local Milestone UAT → milestone-gated remote release → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Production Hardening / Expansion
CURRENT_TRACK_PHASE_MAP: Inventory and Procurement / warehouse current-inventory inline-edit remediation; prior inventory foundation and automatic allocation packages are baseline; this package has completed implementation and bounded automated verification; GitHub Actions CI runner issue and Owner live UAT are pending; Production release is waiting.
COMPLETED_PHASES: Architecture Migration / Global Architecture Gate; inventory foundation and existing warehouse inventory projections relevant to this package.
CURRENT_PHASE: Inventory and Procurement — Warehouse current-inventory inline edit V1.
CURRENT_WORK_PACKAGE: ERP-INVENTORY-INLINE-EDIT-HOVER-V1-001 / GitHub Issue #505.
PACKAGE_STATUS: DESIGN_PASS_COMPLETE; DEV_IMPLEMENTED_AND_VERIFIED; GITHUB_CI_RUNNER_BLOCKED; OWNER_UAT_PENDING.
WAITING_PHASES: Owner live Local/UAT PASS; any required follow-up; merge and Production release gates.
BASELINE: Branch feature/erp-inventory-inline-edit-hover-v1 at 60ccafc2 before implementation; latest origin/main checked before mutation.
GITHUB_STATE: Issue #505 OPEN; Draft PR #506 OPEN, branch pushed, main unmerged; no Production release authorized.
SOURCE_CONFLICTS: NONE WITH EVIDENCE; Issue #505 body is the active scope and local governance files define the delivery gates.
GLOBAL_GATE: HIGH_RISK verification; owner visual design PASS recorded in Issue #505; GitHub CI runner failed before any job steps; live Owner UAT remains mandatory.
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE — no Production command or data operation is in scope.
NEXT_DEPENDENCY: Resolve GitHub Actions pre-runner failure or independently validate equivalent required CI; then Owner inspection of isolated Local/UAT page.
EVIDENCE_AS_OF: 2026-10-25; Draft PR #506 at e11acbd8 and GitHub run 40158757772 observed.

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 8–14 effective hours; planning value 11 hours for bounded UI, service/controller hardening, tests, and delivery.
KNOWN_CURRENT_TRACK_REMAINING: Owner live UAT remediation/acceptance for this package; no later package is selected.
ARCHITECTURE_GATE_REMAINING: 0 hours for this package; existing InventoryService/ProductService/repository contracts are reused.
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE — governed by the living roadmap, outside this bounded package.
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE — no recalculation in this package.
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE — no recalculation in this package.
MVP1_REMAINING: UNKNOWN WITH EVIDENCE — no recalculation in this package.
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE — no later increment selected.
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE — outside this package.
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE — governed by the living roadmap.
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — Work Log ledger not supplied in this worktree.
ESTIMATE_CONFIDENCE: MEDIUM; existing routes/services and page were inspected, but test/runtime timing remains variable.
ESTIMATE_AS_OF: 2026-10-24.
ESTIMATE_METHOD: bounded file/scope estimate from Issue #505, existing implementation, high-risk validation requirements, and one Draft PR delivery.

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 1.5
CORE_EXECUTION: 6.0
TEST_AND_VALIDATION: 2.5
REMEDIATION_BUFFER: 1.0
ESTIMATED_EFFECTIVE_HOURS: 11.0
ESTIMATE_RANGE: 8–14 effective hours
ESTIMATE_BASIS: Existing WarehouseShow and InventoryService are extended with narrow cell-update contracts; no migration or new architecture is planned.
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: INCLUDED
DEPLOYMENT: EXCLUDED
