# ERP Product Liquid + Material Full Name V1
Date: 2026-11-19. Owner design PASS in the current conversation.
WORK_PACKAGE_ID: ERP-PRODUCT-LIQUID-MATERIAL-20261119
EXECUTION_PROVIDER: GPT_WEB (self-built BYL DC Remote; Owner mandated no Claude/Codex CLI)
VERIFICATION_TIER: HIGH_RISK
BASELINE: beyourlovercom/erpbeyourlovercom main 078de3ecdebc1b9dbbc26f29717e0f4833af55bc
BRANCH: feat/erp-product-liquid-material-20261119
WORKTREE: /Users/a/AI/007/_worktrees/erp-product-liquid-material-20261119
DEPENDENCIES: NONE (do not import unfinished product-cost branch changes)
PARALLEL_SAFE: YES only in this dedicated worktree; one active provider.
STATUS: OWNER_VISUAL_PASS / US_LOCAL_UAT_OWNER_PASS_2026-11-19 / PRODUCTION_NOT_AUTHORIZED

## Goal
Add Product master has_liquid (boolean default false) and material_full_name (nullable UTF-8 text), preserve has_battery behavior, show the full name above battery and liquid controls in Product 360, and offer material name as an opt-in user-account-specific column in SKU-bearing ERP grids. Show amber non-blocking liquid warning in domestic pending, including mixed-item orders.

## Frozen business rules
- Initially only product SKUs BY489A and BV339A are liquid. All other SKUs default false. Seed through a deterministic migration; no Production data access or write in this package.
- Admin/products.operate can edit both Product master attributes, with audit; products.view may view them. Woo inbound sync must never overwrite either ERP-owned field.
- Material full name is internal purchasing/warehouse text, not Woo/customer-facing product title, SKU, or ledger item name.
- Preserve original Chinese, English, punctuation, model/version and exact raw string; never generate or translate a missing name.
- An order is flagged if any linked product is has_liquid=true. Resolve by canonical Product association; for historical missing links use exact SKU/approved SKU alias lookup only, not fuzzy guesses. Never block shipments or change order state/approval/carrier/tracking.
- Domestic pending flag stays visibly near fixed order identity even when SKU column is hidden; indicate the triggering SKU in SKU cell or a readable tooltip.

## Source workbook schema and import contract
Owner supplied an XLSX with 9 sheets. Canonical first source is sheet 物料代码 with headers 物料代码(旧系统） and 物料名称. Alternative recognized names include 谦禾库存明细/即时库存 with 物料名称, and 未生产订单 with 旧系统代码 and 产品名称.
Observed source facts: 595 material rows in 物料代码; 515 names start with SKU, 333 distinct SKU prefixes, 95 prefix-SKUs have multiple distinct names. BY489A name does NOT contain SKU: old code 1BY489A5C0100, name BEYOURLOVER  35毫升软管润滑油. BV339A: 1BV339AC50260 -> BV339A胸部按摩仪成品紫色含润滑油版. MF414A: 1MF414A5C0160 -> MF414A小恶魔分级别紫色成品.
- Build a permission-protected XLSX material-name importer, ideally at the existing Product list header and reuse current import UI conventions. Do not persist the original workbook, stock counts, order or sales data; parse only material code and name.
- Match by authoritative ERP SKU/product alias. If name lacks SKU, use longest unique known SKU embedded in the old material code, with strict boundary/uniqueness checks. Never blindly take an arbitrary substring or first match. Unknown/ambiguous/multiple material variants remain in a clear review queue.
- One unique and verified candidate may populate an EMPTY material_full_name. Never silently replace a nonempty manual/confirmed name. Same source re-import is idempotent; changed source and one-SKU/multi-variant cases require explicit operator confirmation. Preserve source code and provenance for review/audit.
- Provide quick row-level confirm/match/manual full-name entry for conflicts, restricted to products.operate. Confirmation must apply to exactly one Product master after validation and be audited. No automatic Product creation.
- Import preview/report: total rows, unique auto-match, duplicate/noop, ambiguous, unmatched, invalid; no false successes. Reject malformed XLSX and unauthorized calls without writes.

## UI and grid contract
- Product360 /products/{id} 产品参数 > 参数总览: editable material_full_name immediately above existing 是否带电 and new 是否带液体 binary controls; readonly panel also displays both. Existing save parameters action/permission/audit and factory-review behavior remain intact. Product Create and Edit forms also carry the fields.
- Relevant SKU/product tables: Products list, Domestic logistics (especially pending), Overseas logistics, Orders Master, Inventory Products, Procurement/External purchase. Add opt-in material_full_name column where the existing grid supports the SKU and product relationship. Do NOT fabricate missing data or add a separate truth table. For multi-item orders, show per-SKU names with clear separation; long text truncates visually but has accessible full text.
- Optional columns default hidden; reuse current Global Grid account-level preferences keyed by user, not local-only. Preserve personal ordering, selection and existing column identifiers; do not reset preferences.
- Domestic amber warning is NON-BLOCKING and not an alert for a missing material name. No page-level banner or extra approval gate.
- Preserve Owner-approved compact workspace, action placement, existing controls, workspace tabs default-on, and order mutation contract.

## Acceptance and verification
- PHP feature tests: Product create/edit/360 validation/permissions/audit, default false+two true SKUs, Woo sync non-overwrite; XLSX import exact match, BY489A name-without-SKU, multiple VA617A variant conflict, unmatched, malicious input, duplicate idempotency and authorization; mixed-item domestic pending projection.
- Vue/Vitest tests: Product360 placement/save/read-only, domestic fixed identity warning and SKU marker, optional material column per user and multi-item source mapping. Test both legacy/native and GlobalGrid pending if both visible.
- TypeScript check, focused PHPUnit, Vitest, Pint, npm run build and node ops/production/verify-workspace-tabs-build.mjs must PASS. Run full relevant US-local verification and prepare live US local UAT pages for Owner. CI/PR head must be exact. Never mark UAT PASS yourself.
- No Production database, Production orders, real OMS, deployment, merge before Owner live UAT PASS; this package approves code and Dev/UAT only. GitHub is code truth, remote US Mac is verification host.

## Verified local implementation evidence (not Owner UAT)
- Implemented schema/master/Product360 Create/Edit, XLSX preview+unique empty-fill+manual row confirmation with immutable audit, mixed-order domestic warning, optional Product/Order/Inventory/Oversea/Domestic/External material columns.
- New focused Laravel tests: Product360 liquid/full-name permissions & validation, XLSX exact BY489A/BV339A/VA617A variants/noop/overwrite/audit, mixed pending order with fallback SKU, Orders Master material projection. All these focused tests passed locally on 2026-11-19 (existing PHP deprecation warnings only).
- New Vue component tests: Product360 display/edit/save/read-only and domestic pending order/SKU amber warning passed locally; frontend TypeScript typecheck and build with 30-tab workspace guard passed after inventory type compatibility remediation.
- Broad unrelated legacy test suites currently do **not** pass cleanly: PHP six existing failures include fake nonnumeric Woo order references and stale domestic viewCounts=10 expectation; full frontend 979/986 passing with 7 failures in 3 pre-existing test suites (including WarehouseCostCenterP3 stale label). They are not claimed as this package regressions until baseline comparison is recorded. Re-run focused contract gates after final diff.
- US-local isolated SQLite UAT: migrated new schema, ran `LocalUatSeeder`, set up synthetic BY489A/BV339A/MF414A Product masters and mixed domestic order, started `127.0.0.1:8777`, logged into approved local-only admin, and opened Product360 / material preview / product list / domestic pending / external procurement / orders / inventory pages in a dedicated Chrome profile. All opened pages returned 200; browser smoke confirmed product controls, one amber order warning + SKU marker, and external material column toggler.
- Import live UAT preview used synthetic XLSX only: 6 rows = 1 unique empty-fill, 2 multiple variants for manual review, 2 unchanged, 1 unmatched, and no write to Product master. No Production or real-order mutation.
- Owner explicitly responded `pass` on 2026-11-19 after the US Local/UAT 10-tab handoff: **OWNER LIVE UAT PASS**. PR [#806](https://github.com/beyourlovercom/erpbeyourlovercom/pull/806) had all 11 required checks PASS at aee1180b; advance through final documentation-head CI and merge gate. No Production database mutation or deployment authorization.

## PROJECT POSITION
PROGRAM_ROADMAP: Architecture -> MVP1 Operations -> Inquiry -> Woo Sync -> Product/Media -> Logistics/KOL/Inventory/Procurement/Accounting -> Hardening
CURRENT_TRACK_PHASE_MAP: R0 scope PASS -> R1 schema -> R2 Product360/import -> R3 grids/reminders -> R4 tests/UAT -> merge gate -> separate Production gate
COMPLETED_PHASES: R0 visual design and source workbook analysis
CURRENT_PHASE: R4 Owner live UAT PASS; final CI and Git merge gate
CURRENT_WORK_PACKAGE: ERP-PRODUCT-LIQUID-MATERIAL-20261119
PACKAGE_STATUS: OWNER_LIVE_UAT_PASS / MERGE_GATE_AUTHORIZED
WAITING_PHASES: FINAL_CI_AND_MERGE; PRODUCTION_RELEASE_AUTHORIZATION
BASELINE: main 078de3ec
GITHUB_STATE: Draft PR #806; branch pushed, not merged
SOURCE_CONFLICTS: NONE WITH EVIDENCE (source workbook ambiguity is intentionally queued)
GLOBAL_GATE: Owner visual PASS; US Local UAT OWNER PASS 2026-11-19; merge after final green CI
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE
NEXT_DEPENDENCY: final PR #806 CI green then merge; Production release separate Owner gate
EVIDENCE_AS_OF: 2026-11-19

## ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 11-19 effective hours
KNOWN_CURRENT_TRACK_REMAINING: 11-19h for this isolated work package
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: 11-19h isolated increment
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE
ESTIMATE_CONFIDENCE: medium
ESTIMATE_AS_OF: 2026-11-19
ESTIMATE_METHOD: 1-2h contract + 2-3h master/UI + 3-5h import/review + 3-5h grids + 2-4h tests/UAT

## ESTIMATED EXECUTION HOURS
PREFLIGHT_AND_SCOPE: 1-2h
CORE_EXECUTION: 8-13h
TEST_AND_VALIDATION: 1-2h
REMEDIATION_BUFFER: 1-2h
ESTIMATED_EFFECTIVE_HOURS: 11-19h
ESTIMATE_RANGE: 11-19h
ESTIMATE_BASIS: Owner approved combined roadmap; high-risk import and multiple grid consumers
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED (requires Owner live UAT PASS)
MIGRATION: EXCLUDED (Production schema execution)
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: EXCLUDED (Production)
UAT: INCLUDED (US local preparation only)
DEPLOYMENT: EXCLUDED
