# ERP2 R0–R2 Latest-Main Reconciliation (2026-11-20)

> Status: R0 isolated Laravel schema/selected contracts PASS; R1/R2 backend-only adapter current-main PASS; ERP2 frontend end-to-end and Owner visual UAT pending. DEV ONLY. No Production data access or deployment.

## Authority and exact versions

- Repository: `beyourlovercom/erpbeyourlovercom`.
- R0 source tested: `main@e7b93bca30faa129dfe81cb1a9c51018ba0189f0`, including merged KOL Tencent PR #805.
- ERP2 existing frontend: Draft PR #766 `69de4554c082a3fbcfc7740689a71279673ac08b` (base `b07885e3`), **not** merged into main.
- R1/R2 existing Laravel readonly backend: Draft PR #803, pre-sync `d65c7738`, merged latest main at `1294170a48334bb90b22916372711e9904f42399`. No conflict; unchanged behavior intended.
- Baseline docs: Draft PR #800 at `1adc5e76`; this independent reconciliation does not rewrite #800's historical references.
- Worktrees: US Mac mini `/Users/a/ai/007/_worktrees/erp2-r0-source-20261120` (this docs branch) and `erp2-pr803-readonly-20261120` (#803 non-overlapping integration). Other provider worktrees untouched.

## R0 evidence — exact current GitHub main, isolated Dev

- Previous `eb8ed64e` source has 178 migrations; current `e7b93bca` has **182** (4 additions).
- Added: warehouse-specific final SKU table + `order_items.final_sku`; collaboration partners table/order relation; product `has_liquid` and `material_full_name`; BV339A liquid variant data update.
- Fresh temp-only SQLite DB, `APP_ENV=testing`, `DB_CONNECTION=sqlite`, explicit isolated file, `QUEUE_CONNECTION=sync`, no `.env` or Production DB credentials used.
- `artisan migrate` **182/182 DONE**, repeated `migrate:status` shows latest four **Ran**.
- SQLite checks: 182 migration rows; `orders=0`, `shipments=0`, `products=0`; `order_items.final_sku` and `product_warehouse_skus` exist; 2 seed collaboration partners (CHUNYI, AMAZON_JENNY).
- Exact-main 4 source-contract test files: **111 PHPUnit tests / 2140 assertions PASS**, including OrderIdentitySearch, PendingAllocationEntryPolicy, DomesticBusinessReview, OverseaWarehouseWorkbench.
- Caution: fresh Dev SQLite successful migration is **not** Production schema parity or true business data migration; 12 historical plus new data-affecting migrations require Production-specific dry-run review at R6.

## R1/R2 version catch-up and backend contract evidence

- Current main `GET /orders` routes to `OrderMasterController::index`; `GET /orders/master` redirects to `orders.index`. Older ERP2 #766 was pinned to a different Orders/Inertia contract: do not silently reuse the old payload assumptions.
- Current canonical `/customers/orders` and `/kol/orders` continue to be routed through `OrderController::index`; actual server scope and role authorization must remain authoritative.
- PR #803 supplies 3 read-only independent Laravel JSON projections: pending allocation from Order, domestic queue from Order, and overseas queue from Shipment; they must not be synthesized by filtering an Orders Master payload in the browser.
- Latest-main integration used a clean additive Git merge (not a force-rebase), updating #803 to `1294170a`. Three new ERP2 projection tests plus the four core source-contract files: **125 PHPUnit tests / 2290 assertions PASS** on merged exact code, isolated in-memory SQLite.
- This establishes source/backend regression only. It does **not** establish full 007windows frontend rendering, filters, cross-role row scope, actual state changes, shipment mutations, OMS/Woo integration, or 30+3 tab UAT.
- Previous 6/6 real-staff ERP2 Dev login/identity/permission browser PASS is separate evidence; session identity parity does not equal real row data-scope parity.
- The original ERP remains the only canonical Orders, Shipment, Inventory, Finance and AffiliateWP truth. ERP2 creates no second business data authority.

## Remaining gates and next bounded execution

1. R0: pin #766 frontend to the current GET route / menu / effective permissions contract after comparing its old gateway adapters; baseline diff of 126+ commits cannot be waved away. Retain #766 Draft until integration and Owner visuals.
2. R1: complete Orders Master, customer and KOL detail/search/paging projections against isolated current-main Laravel; include allow/deny, actual sample row scopes and zero-PII synthetic fixture tests.
3. R2: connect three #803 read-only GET projections to ERP2 Vue on 007windows, after required Owner visual proposal PASS; test list/count, state filters, warehouse and KOL restrictions with representative nonempty Dev data.
4. R1/R2 write actions (allocation, review/reject, tracking, shipping and return-to-allocation) remain separate positive/negative HTTP+DB rollback/audit gates. Do not issue external OMS/Woo/WMS calls.
5. No Draft PR is authorized by this report for Production release; do not merge #766/#803 until all applicable Owner and CI gates are satisfied.

## PROJECT POSITION

PROGRAM_ROADMAP: 原 ERP Production 稳定运行 → ERP2 R0 contracts → R1 Orders → R2 Logistics → R3 Products/Inventory/SKU → R4 Other modules → R5 regression/Owner UAT → R6 synthetic data rehearsal → R7 approved cutover.
CURRENT_TRACK_PHASE_MAP: R0 isolated source/schema PASS; R1 Orders source tests PASS / frontend HOLD; R2 readonly backend tests PASS / browser HOLD; R3–R7 waiting.
COMPLETED_PHASES: R0 GitHub main pin, SQLite 182/182 migrations, baseline 111/2140 tests, PR #803 additive sync 125/2290 tests.
CURRENT_PHASE: R0–R2 Dev integration.
CURRENT_WORK_PACKAGE: ERP2-R0-R1-R2-LATEST-MAIN-20261120.
PACKAGE_STATUS: PARTIAL PASS / DRAFT / DEV ONLY.
WAITING_PHASES: R1/R2 frontend Owner visual gate, row-scope E2E, R3–R7.
BASELINE: main@e7b93bca; PR #766@69de4554; PR #803@1294170a.
GITHUB_STATE: PR #766, #800, #803 Draft; no main merge from this package.
SOURCE_CONFLICTS: Older #766 Orders/Index and source pin; current main Orders/Master with /orders/master redirect; 182 migrations vs original 178.
GLOBAL_GATE: Owner visual PASS before new visible UI; Owner UAT and CI before merge; separate Production approval.
PRODUCTION_DEPLOYMENT_STATUS: NO.
NEXT_DEPENDENCY: 007windows frontend read-contract wiring and user visual approval; no Production data.
EVIDENCE_AS_OF: 2026-11-20 main, PR, exact US isolated PHPUnit/SQLite runs.

## ROADMAP HOURS

CURRENT_PACKAGE_ESTIMATE: R0 source/schema and R1/R2 readonly integration evidence 5–10 effective hours planned; scope of this bounded work package only.
KNOWN_CURRENT_TRACK_REMAINING: 007windows R1/R2 frontend visual/GET integration 8–16h preliminary (not an accepted work log).
ARCHITECTURE_GATE_REMAINING: 1–3h scope/visual contract reconciliation; subject to Owner feedback.
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE (full ERP2 migration remains to be rescoped after real R1/R2 integration).
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE.
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE.
MVP1_REMAINING: UNKNOWN WITH EVIDENCE.
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE.
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE.
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE, supersedes unvalidated 200h point estimate.
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE (no accepted time ledger).
ESTIMATE_CONFIDENCE: MEDIUM for R0/R2 backend slice; LOW for full ERP2.
ESTIMATE_AS_OF: 2026-11-20.
ESTIMATE_METHOD: Existing-code reuse, exact current-main Git diff, SQLite migration and role/business HTTP tests; no invented elapsed work log.

## ESTIMATED CODEX HOURS

PREFLIGHT_AND_SCOPE: 1–2h.
CORE_EXECUTION: 2–4h.
TEST_AND_VALIDATION: 1–2h.
REMEDIATION_BUFFER: 1–2h.
ESTIMATED_EFFECTIVE_HOURS: 7h planning point for bounded R0/R1/R2 backend catch-up.
ESTIMATE_RANGE: 5–10h.
ESTIMATE_BASIS: Latest-main R0 isolated validation + read-only PR #803 sync; excludes unapproved new UI, writes or data migration.
OWNER_REVIEW: INCLUDED.
MERGE: EXCLUDED.
MIGRATION: EXCLUDED.
MIGRATION_FILE_AUTHORING: EXCLUDED.
MIGRATION_EXECUTION: EXCLUDED (isolated disposable Dev migration verification included under tests).
UAT: EXCLUDED (007windows role/browser UAT requires separate completion).
DEPLOYMENT: EXCLUDED.
