# ERP-ACCOUNTING-V11C-P6A-LAST-MILE-ESTIMATE-001

**Status:** IMPLEMENTED / VERIFIED / MERGE READY / BACKEND ONLY
**Date:** 2026-09-11  
**Owner scope:** Accounting V1.1C-P6 — Last-mile Cost, phase P6A Estimated Last-mile  
**EXECUTION_PROVIDER:** GPT_WEB  
**VERIFICATION_TIER:** HIGH_RISK  
**UI_UAT:** NOT REQUIRED — backend-only  
**PRODUCTION_ACTIVATION:** NOT AUTHORIZED

## Objective
Materialize a bounded, traceable `last_mile_cost` estimate for canonical customer Orders when an actual carrier bill is not yet available, using only an already-selected fulfillment source and frozen Logistics rate/measurement evidence. Estimated facts remain `estimated_reference / estimated` and must never be promoted to confirmed profit.

P6 remains estimate-first. P6A does not import carrier bills; P6B owns actual carrier-bill ingestion/replacement. P6C later owns continuous-delta integration.

## Baseline / Dependencies
- Baseline: `origin/main@e333c9a4e58b661b813206d3cb85bcd8932788d6`.
- Dependency: PR #289 merged in `1d7aae2649988e2c7c03eb483ea8cf203b5f33d0`; selected fulfillment allocation / derived-order infrastructure is canonical.
- Dependency type: MERGED_PR.
- C1 continuous delta is Production accepted; P6A must not alter its schedule.
- Open PR #276 touches `ShipmentService.php` and Architecture baseline; P6A will not modify either file.
- PARALLEL_SAFE: YES, subject to exact-file guard before delivery.

## Canonical Truth Boundaries
- Order Finance truth stays on the original `order_kind=customer` Order.
- Logistics owns Warehouse / Shipment / fulfillment-allocation / carrier-billing truth.
- Finance owns the `order_cost_entries.last_mile_cost` projection.
- Fulfillment-derived child Orders do not become a second Finance order truth.
- Actual carrier billing always outranks estimates.
## Frozen Estimation Contract
1. Candidate scope is operational `order_kind=customer`; Historical Logistics remains excluded.
2. Estimate only from a canonical selected fulfillment source:
   - first priority: current canonical Shipment with a selected Warehouse;
   - fallback: submitted `fulfillment_allocation.plan_snapshot` for split-parent or other allocation-backed cases.
3. Never estimate from a hypothetical cheapest/recommended plan that the operator has not selected.
4. Parent split Order aggregates all selected fulfillment segments; child fulfillment Orders are evidence only.
5. P6A stores **routing / carrier transport cost only**. Product packaging cost must never be included in `last_mile_cost`.
6. China FACTORY uses the frozen Carrier Routing policy. Only 4PX / 速达 `SD经济挂号-带电` is estimable in P6A; owner-confirmed billing basis is actual weight only.
7. Germany DE uses the existing frozen DHL routing calculator and approved packaging-weight evidence.
8. Other warehouse/carrier combinations without a frozen rate calculator return an explicit blocker; no invented rate.
9. Multi-segment routing components are normalized to CNY through canonical Settings FX, then Finance posts the estimate into the Order transaction currency using the existing Accounting FX snapshot bridge.
10. Estimate candidate identity is stable per Order: `last-mile-estimate:v1:order:<id>` so refreshed selected-source/weight/rate evidence may update an estimate before actual billing exists.
11. Candidate is `provenance=estimated_reference`, `status=estimated`.
12. Strong existing facts (`actual`, `confirmed_manual`, `provider_import`, `allocated_actual`) must never be overwritten by an estimate.
13. Actual carrier billing replacement is deferred to P6B; P6A must not widen foreign-currency actual billing semantics.
14. Estimated last-mile must result in Finance `ESTIMATED`, never `CONFIRMED`, when all other cost/revenue facts are otherwise complete.

## Implementation Surface
- Add a Logistics application contract for selected-fulfillment last-mile estimate evidence.
- Add one Logistics infrastructure implementation using existing allocation/shipment truth, measurement readers, routing calculators, Carrier Routing policy, and Settings FX.
- Bind the contract in `LogisticsServiceProvider`.
- Extend `CostMaterializationService` with a dedicated P6A preview/apply gate only; do not alter P6B actual semantics.
- Add `finance:materialize-last-mile-estimates`, dry-run by default.
- Register the command in `FinanceServiceProvider`.
- No migration, menu, route, permission, frontend, dependency-manifest, scheduler, or C1 change.

## Production Apply Safety
Production `--apply` is fail-closed unless process-local `ERP_ACCOUNTING_LAST_MILE_ESTIMATE_PRODUCTION_APPLY_ENABLED=true`. Production apply requires exact guard counts for seen/apply/blocked/FX-freeze. Merge/deploy must not set this flag.
## Acceptance Criteria
- Factory 4PX selected fulfillment + adopted shipping reference weight produces a routing-only CNY estimate and Finance transaction-currency candidate.
- DE selected fulfillment + approved packaged weight produces DHL routing-only estimate.
- Split selected fulfillment can aggregate supported segments; any unsupported segment blocks the whole Order estimate.
- Missing selected fulfillment, missing Product identity/weight, unsupported carrier/source, unavailable rate, or unavailable FX is explicit and nonfatal.
- Packaging-cost amount is provably excluded from last-mile estimate.
- Dry-run causes zero cost-entry and zero FX-snapshot writes.
- Idempotent rerun is noop when evidence is unchanged.
- Changed estimate evidence may refresh an existing P6A-owned estimated row.
- Strong existing last-mile facts remain protected.
- Finance projection preserves `ESTIMATED` semantics for estimated last-mile.
- Historical Logistics and non-customer Orders are excluded.
- Production apply guard refuses count drift before writes.

## Validation Contract
- Focused P6A Finance/Logistics feature tests.
- Existing `CostMaterializationTest` and `OrderAnalysisService` regression.
- Existing Logistics fulfillment-plan/allocation tests.
- Existing C1 focused regression; P6A must not change scheduler behavior.
- PHP syntax/lint and `git diff --check`.
- Command discovery and Production-guard tests.
- Architecture/Menu regression delta against exact baseline; no new failure is allowed.
- Full Finance + relevant Logistics regression on US Mac merge-candidate revision.

## Explicit Non-Goals
- No actual carrier-bill spreadsheet import in P6A.
- No USPS / Canada Post / Weship / POSTNL / KingTrans rate invention.
- No item-level last-mile allocation.
- No ocean-freight decision engine.
- No C1 scheduler integration yet.
- No Production deployment or Production Accounting write.

## Evidence / Preflight
Production read-only audit before implementation: 6,159 customer Orders; 16 Shipments; 0 `shipment_carrier_billing`; 0 `last_mile_cost` entries. Existing actual-billing infrastructure therefore has no current Production facts to materialize, while frozen 4PX China and DE DHL estimate engines exist on `main`.
## Source Conflicts
`AI/CURRENT_CONTEXT.md` is stale at 2026-08-29 and does not describe the current Accounting production track. Mutable Git/PR facts and current code from GitHub `main` are authoritative. No product-scope conflict was found between current P6A and merged PR #289.

## Verification Evidence
- P6A focused: 6 tests / 33 assertions PASS.
- Finance + relevant fulfillment/routing/allocation: 111 PASS / 866 assertions; 1 MySQL-only skip.
- `FulfillmentActualWeightEvidenceTest`: exact baseline and P6A both have the same 2 inherited failures / 4 assertions; regression delta = 0.
- `FulfillmentPlanPreviewServiceTest`: exact baseline and P6A both have the same 2 inherited failures / 6 pass / 85 assertions; regression delta = 0.
- Architecture/Menu/AppShell exact baseline: 7 failures / 45 pass / 1838 assertions; P6A identical failure set and counts; regression delta = 0.
- PHP lint PASS for every touched PHP file; `git diff --check` PASS.
- `finance:materialize-last-mile-estimates` is registered. No scheduler, migration, frontend, menu, permission, route or dependency-manifest change.
- Complete carrier-billing evidence blocks estimated materialization before any Finance write.
- GitHub Actions run `34672057509` on head `5f9ac39f52776b38f09b763a81940e17d0c7427a`: both `sqlite-regression` and `mysql8-runtime` failed before runner assignment with `runner_id=0`, blank `runner_name`, and `steps=[]`; no test command started. Classified as the established GitHub Actions infrastructure exception, not a P6A regression.

## Work Log
- Preflight / contract / dependency reconciliation: 0.45h.
- Core routing-only evidence + Finance gate + command implementation: 0.85h.
- Focused/full regression, isolated baseline harness and inherited-failure reconciliation: 0.90h.
- Static review, delivery evidence and merge-candidate preparation: 0.25h.
- ACTUAL_EFFECTIVE_HOURS: 2.45h. External waiting and overnight idle time excluded.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate [COMPLETE] → MVP1 Existing Operations / Tencent Docs Replacement → ERP-INQUIRY-M1 → Milestone Remote Release → MVP1.1 WooCommerce Inbound → MVP1.2 Tracking Outbound → MVP1.3 Sync Orchestration → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion [CURRENT] → Shopify and Additional Integrations → Production Hardening / Expansion
CURRENT_TRACK_PHASE_MAP: V1.1B Revenue Evidence Woo [COMPLETE] → V1.1C-P1 Product Cost Source [COMPLETE] → P2 Product Cost Materialization [COMPLETE] → P3 Fixed Cost Rules [COMPLETE] → P4 Transaction FX Snapshot [COMPLETE] → P5 Payment Fee [COMPLETE] → C1 Continuous Delta [COMPLETE] → P6A Estimated Last-mile [CURRENT] → P6B Actual Carrier Bill Import/Replacement → P6C Continuous Delta Integration → P7 Warehouse Cost → P8 KOL Commission → P9 Loss / Other Costs → V1.1C Final Reconciliation / Closure Gate → V1.1 Final Profit Readiness
COMPLETED_PHASES: V1.1B, P1, P2, P3, P4, P5, C1
CURRENT_PHASE: V1.1C-P6 Last-mile Cost / P6A Estimated Last-mile
CURRENT_WORK_PACKAGE: ERP-ACCOUNTING-V11C-P6A-LAST-MILE-ESTIMATE-001
PACKAGE_STATUS: IMPLEMENTED / VERIFIED / MERGE READY / BACKEND ONLY
WAITING_PHASES: P6B, P6C, P7, P8, P9, V1.1C Final Reconciliation / Closure Gate, V1.1 Final Profit Readiness
BASELINE: issued baseline origin/main@e333c9a4e58b661b813206d3cb85bcd8932788d6; merge-candidate rebased onto origin/main@07dcb5fe28b39bbbb0c7c9aae5d0cf76147b65f7
GITHUB_STATE: PR #291 Ready; implementation/local verification complete; first CI run 34672057509 is exact runner_id=0 / steps=[] infrastructure exception; final docs-only evidence commit will be the merge candidate
SOURCE_CONFLICTS: AI/CURRENT_CONTEXT.md is stale relative to GitHub/main and Production; GitHub/main governs mutable execution facts
GLOBAL_GATE: BYL_ERP_CONTINUOUS_OWNER_AUTHORIZATION_V1 active; Production deploy/data write remain hard Owner gates
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: Final evidence push → PR #291 checks → continuously authorized merge → Production code-only deploy hard gate
EVIDENCE_AS_OF: 2026-09-12
ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 4.75 effective hours planning value; range 3.5–7.0h
KNOWN_CURRENT_TRACK_REMAINING: P6B/P6C/P7/P8/P9 are known roadmap packages; exact non-overlapping remaining hours UNKNOWN WITH EVIDENCE pending each package preflight
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE — no separate current architecture package estimate accepted for this Accounting track
MVP1_TOTAL_ESTIMATE: Historical program estimate not safely applicable to current Accounting expansion; UNKNOWN WITH EVIDENCE
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE in current merged context
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE in current merged context
MVP1_REMAINING: UNKNOWN WITH EVIDENCE — current Accounting work is post-original MVP accounting expansion
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE; cumulative horizons are not summed
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE — later packages require separate preflight estimates
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — no complete accepted cross-program Work Log ledger was found in current preflight
ESTIMATE_CONFIDENCE: MEDIUM
ESTIMATE_AS_OF: 2026-09-11
ESTIMATE_METHOD: bounded file/contract preflight against exact main; no migration/UI; includes implementation, US verification and remediation buffer

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.75h planning value; range 0.5–1.0h
CORE_EXECUTION: 2.0h planning value; range 1.5–3.0h
TEST_AND_VALIDATION: 1.25h planning value; range 1.0–1.5h
REMEDIATION_BUFFER: 0.75h planning value; range 0.5–1.5h
ESTIMATED_EFFECTIVE_HOURS: 4.75h
ESTIMATE_RANGE: 3.5–7.0h
ESTIMATE_BASIS: bounded P6A backend implementation reusing merged fulfillment/rate/FX primitives; actual-bill import and Production execution excluded
OWNER_REVIEW: EXCLUDED
MERGE: INCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
ACTUAL_EFFECTIVE_HOURS: 2.45h
ACTUAL_HOURS_BREAKDOWN: preflight 0.45h; core execution 0.85h; test/validation/remediation 0.90h; final evidence 0.25h
VARIANCE: -2.30h / -48.4%
VARIANCE_REASON: existing fulfillment/routing/FX primitives allowed a smaller implementation surface than the planning envelope; inherited regression failures were resolved by exact-baseline comparison instead of product-code remediation
