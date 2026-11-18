# BYL Warehouse Final SKU V1 — Owner-approved R1–R4 Work Package

**Date:** 2026-11-18 · **Owner authorization:** R1–R4 · **Branch:** `feat/warehouse-final-sku-unification-20261118` · **Baseline:** `eb8ed64e7790efbcc6271742c8beaac3eaa3b30b` (merged PR #798) · **EXECUTION_PROVIDER:** GPT_WEB (frozen contract implementation; no concurrent provider in this worktree) · **VERIFICATION_TIER:** HIGH_RISK.

## Business contract

- Original sales/Woo identity remains `order_items.source_sku` (and source product metadata); no rewriting external Woo order or booked sales.
- `product_warehouse_skus` is the **one confirmed physical SKU per `(product_id, warehouse_id)`**, including cases where the real SKU equals the source SKU. Warehouse `007` is inventory-only, not a fulfillment destination.
- A physical mapping requires warehouse/product identity, an operator confirmation and meaningful warehouse evidence. Conflicting mappings to the same physical SKU in a warehouse are rejected. An unverified mapping is never silently authorized.
- Each physical fulfillment `order_items` line has its own persisted `final_sku`. Selecting a different warehouse recalculates it from that warehouse's verified mapping; different lines of a split order may use different warehouse SKUs. Only pending, non-dispatched fulfillment can be changed. Source SKU is retained for financial/traceability purposes, but no separate per-order SKU mutation history is required.
- Customer-initiated product replacement must select the *new product* (not assert an alias). For unallocated orders, the source identity changes and `final_sku` is cleared; for allocated/reserved/dispatched orders, existing controlled replan/OMS cancellation gates apply. Never mutate a provider-accepted warehouse order merely because ERP SKU changed.
- All operational fulfillment UI projections, legacy/new grid rows, outbound XLSX and OMS payloads must read `final_sku` (or the explicit legacy compatibility fallback before migration); no downstream provider re-canonicalization may replace a confirmed physical SKU.
- Mapping confirmation is separate from stock availability. MorePlus retains read-only physical catalog/stock validation before any provider submission. Other warehouses retain their existing provider-specific readiness gates; missing physical mapping blocks strict-mode dispatch.
- Finance, Woo sync, inventory/product master, procurement source/received SKU remain their own authorities and must **not** be overwritten with warehouse SKU merely for UI uniformity.

## Rollout / irreversible data guard

`WAREHOUSE_FINAL_SKU_REQUIRE_VERIFIED_MAPPING=false` is a transitional compatibility setting. It must **not** be enabled in Production until verified warehouse data coverage, pending-order migration strategy and Owner-approved Production release/migration are complete. When enabled, allocation/export/OMS use fail-closed mapping checks and do not silently use initial SKU. No automatic Production data backfill, OMS POST, shipping, device/Chrome restart or Production deployment in this package.

## Coverage / acceptance matrix

| Gate | Target | Evidence required |
| --- | --- | --- |
| R1 | Mapping DB/model, Product360 mapping data + protected mutations, order-line final SKU | DB schema, uniqueness, permission positive/negative tests, verify source preserved |
| R2 | Manual/recommended allocation, split A/B, child change of warehouse, replacement and all export/push makers | Exact SKU in files/payload, no re-canonicalization, mismatch/missing mapping blocks |
| R3 | `/orders`, `/orders/master`, `/orders/{id}`, `/logistics/wait-stock`, domestic new/legacy views, overseas new/legacy views, logistics queues, Tingon, order XLSX | Explicit API projection `sku=final` when assigned, `source_sku` separately; search both |
| R4 | PHPUnit/route/UI contract, typecheck/build, actual Dev page Owner UAT via **007windows**, real MorePlus read-only SKU evidence | All passing on same commit, Owner's live-page PASS separately; no Production writes |

## Scope / dependencies

`DEPENDENCIES: NONE` beyond merged PR #798; `DEPENDENCY_TYPE: MERGED_PR`; `DEPENDENCY_STATUS: VERIFIED_BASELINE`; `PARALLEL_SAFE: NO` (shared OrderItem and fulfillment core); one worktree/branch/PR; **no Owner-visual approval implies UI implementation authorization**. The future mapping-editing form on Product360 requires its own rendered proposal and Owner visual PASS before adding visible controls; current R1 prepares the data contract and nonvisual API only. UI design/UAT gate remains open. Independent CI review is mandatory. Production remains unchanged without separate authorization.

## Project position

PROGRAM_ROADMAP: Architecture Migration → MVP1 Operations → B2B Inquiry → Woo Inbound/Tracking/Sync → Product/Media → Content/SEO → Order/Logistics/KOL/Inventory/Accounting → Integrations → Production Hardening (full baseline: `docs/10_Planning_Roadmap/13_PROGRAM_ROADMAP_AND_HOURS.md`).
CURRENT_TRACK_PHASE_MAP: Final SKU R0 read-only audit COMPLETE → R1 mapping contract IN PROGRESS → R2 outbound unification IN PROGRESS → R3 frontend data coverage IN PROGRESS → R4 exact revision validation/UAT WAITING.
COMPLETED_PHASES: R0 read-only code inventory only.
CURRENT_PHASE: R1–R3 implementation.
CURRENT_WORK_PACKAGE: BYL_WAREHOUSE_FINAL_SKU_V1.
PACKAGE_STATUS: IMPLEMENTING; NOT TESTED; NOT MERGE-READY.
WAITING_PHASES: R4 Dev runtime and Owner visual acceptance, separately approved Production deploy.
BASELINE: GitHub main `eb8ed64e7790efbcc6271742c8beaac3eaa3b30b`.
GITHUB_STATE: Feature branch from main, no PR as of package creation.
SOURCE_CONFLICTS: `AI/CURRENT_CONTEXT.md` is historical and predates PR #798; GitHub current main governs.
GLOBAL_GATE: HIGH_RISK / Owner UAT required / Production separate.
PRODUCTION_DEPLOYMENT_STATUS: NO; no Production write authorized.
NEXT_DEPENDENCY: Tests and correct exact-SHA build, mapping visual proposal.
EVIDENCE_AS_OF: 2026-11-18.

## Roadmap hours

CURRENT_PACKAGE_ESTIMATE: planning value 39h / range 30–48h.
KNOWN_CURRENT_TRACK_REMAINING: R1–R4 30–48h planning estimate; actual subtraction unavailable.
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE (historical program not rebaselined).
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE (not relevant to this post-MVP remediation).
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE (refer to document 13).
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE (refer to document 13).
MVP1_REMAINING: UNKNOWN WITH EVIDENCE (refer to document 13).
POST_MVP_KNOWN_INCREMENT: This package 30–48h independent.
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE (not recomputed).
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE (not recomputed).
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE (no accepted Work Log yet).
ESTIMATE_CONFIDENCE: medium; multi-export legacy surface audit ongoing.
ESTIMATE_AS_OF: 2026-11-18.
ESTIMATE_METHOD: Bottom-up 4 stages; code audit and real warehouse checks separate.

## Estimated Codex hours

PREFLIGHT_AND_SCOPE: 6–10h, plan 8h (R1).
CORE_EXECUTION: 18–28h, plan 23h (R2+R3).
TEST_AND_VALIDATION: 4–7h, plan 5h (R4).
REMEDIATION_BUFFER: 2–3h, plan 3h (R4).
ESTIMATED_EFFECTIVE_HOURS: 39h.
ESTIMATE_RANGE: 30–48h.
ESTIMATE_BASIS: R1 6–10h + R2 10–16h + R3 8–12h + R4 6–10h.
OWNER_REVIEW: INCLUDED.
MERGE: INCLUDED (only after required Owner UAT + checks).
MIGRATION: EXCLUDED.
MIGRATION_FILE_AUTHORING: INCLUDED.
MIGRATION_EXECUTION: EXCLUDED.
UAT: INCLUDED (preparation; Owner wait excluded).
DEPLOYMENT: EXCLUDED.
