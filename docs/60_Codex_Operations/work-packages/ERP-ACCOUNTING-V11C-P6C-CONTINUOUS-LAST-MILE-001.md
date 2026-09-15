# ERP-ACCOUNTING-V11C-P6C-CONTINUOUS-LAST-MILE-001

## Contract

- EXECUTION_PROVIDER: GPT_WEB
- VERIFICATION_TIER: DAILY
- BASELINE: `main@3eed38479d7f3338e5b0f707c8c9315106c33239`
- DEPENDENCIES: P6A estimated last-mile and P6B actual carrier-billing materialization already merged/deployed/Production-accepted
- DEPENDENCY_TYPE: MERGED_PR
- DEPENDENCY_STATUS: SATISFIED
- PARALLEL_SAFE: YES; bounded Finance continuous-delta service/candidate-store/tests only

## Objective

Close Accounting V1.1C-P6C by making the existing Accounting continuous-delta pipeline consume the already-approved P6A/P6B last-mile gates. New/updated canonical Shipments should make estimated last-mile eligible; later `shipment_carrier_billing` evidence should make actual carrier billing eligible and supersede the estimate through the existing `CostMaterializationService` rules.

## Frozen scope

1. Add `last_mile_estimate` stage to `AccountingContinuousDeltaService` preview/apply.
2. Add `last_mile_actual` stage to the same service preview/apply.
3. Extend `EloquentAccountingContinuousDeltaCandidateStore` so changes to `shipment.billingEvidence.updated_at` select the canonical customer Order.
4. Reuse `preview/applyLastMileEstimateGate()` and `preview/applyCarrierBillingGate()` exactly; do not duplicate cost rules.
5. Keep current `ERP_ACCOUNTING_CONTINUOUS_DELTA_ENABLED` scheduler/Production apply gate. Do not create a second scheduler or flag.
6. Update focused tests and command wording/evidence only as needed.

## Explicit exclusions

- no schema or migration;
- no UI/menu/routes;
- no historical Shipment reconciliation;
- no carrier-bill source adapters/import changes;
- no FX-rule changes;
- no Product/warehouse/KOL/loss cost changes;
- no scheduler activation in this WP;
- no Production data writes before bounded Production verification/deploy gate;
- no changelog update.

## Acceptance criteria

- Continuous-delta preview exposes product_cost, fixed_costs, payment_fee, last_mile_estimate and last_mile_actual stages.
- Apply uses existing P6A/P6B materialization gates and refreshes completeness once per Order after the stages.
- A Shipment-only update can select an Order for the window.
- A carrier-billing-only update can select an Order even if Order/Shipment timestamps are older than the window.
- Existing actual billing prevents an estimate from becoming a competing strong fact.
- Actual provider_import remains idempotent and stronger than estimated_reference via existing writeCandidate semantics.
- Existing P2/P3/P5 continuous-delta behavior remains regression-safe.
- Focused Finance tests PASS; exact-main inherited failures have delta=0 if present.

## Validation plan

- focused continuous-delta feature tests;
- P6A estimate tests;
- P6B actual last-mile tests;
- Finance service/provider architecture tests relevant to touched files;
- PHP lint and `git diff --check`;
- command discovery;
- local dry-run using bounded fixture data.

## Production semantics

P6C code deployment may be separately released after merge. The existing continuous-delta scheduler remains controlled exclusively by `ERP_ACCOUNTING_CONTINUOUS_DELTA_ENABLED`. Production activation is not silently enabled by code deployment. Owner has granted continuous authorization for the active P6 chain until UAT, but unexpected scope/data anomalies still stop execution.

## Work Log

To be completed before merge with actual effective hours and variance.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration -> MVP1 Existing Operations -> ERP Inquiry -> Woo Inbound -> Outbound/Sync -> Product/Media -> Content/SEO -> Full Order Workspace -> Logistics OS -> KOL OS -> Inventory/Procurement -> Accounting/ROI Expansion -> Production Hardening
CURRENT_TRACK_PHASE_MAP: Accounting V1.1C P1-P5 COMPLETE -> P6A COMPLETE -> P6B COMPLETE -> P6C CURRENT -> P7 -> P8 -> P9 -> V1.1 Final Profit Readiness
COMPLETED_PHASES: P1 Product Cost Source; P2 Product Cost Materialization; P3 Fixed Cost Rules; P4 Transaction FX Snapshot; P5 Payment Fee; P6A Estimated Last-mile; P6B Actual Carrier Bill Tianyun production sample
CURRENT_PHASE: P6C Continuous Delta integration
CURRENT_WORK_PACKAGE: ERP-ACCOUNTING-V11C-P6C-CONTINUOUS-LAST-MILE-001
PACKAGE_STATUS: ACTIVE
WAITING_PHASES: P7 Warehouse Cost; P8 KOL Commission; P9 Loss/Other Costs; V1.1 Final Profit Readiness
BASELINE: main@3eed38479d7f3338e5b0f707c8c9315106c33239
GITHUB_STATE: branch feature/erp-accounting-v11c-p6c-continuous-last-mile-001 from exact baseline; delivery PR pending
SOURCE_CONFLICTS: AI/CURRENT_CONTEXT.md may be stale relative to current GitHub/main and Owner-approved Accounting roadmap; GitHub/main + this Owner-confirmed P6 roadmap govern
GLOBAL_GATE: BYL_ERP_CONTINUOUS_OWNER_AUTHORIZATION_V1 active; Production release/data semantics remain governed
PRODUCTION_DEPLOYMENT_STATUS: NO for P6C code at activation; P6A/P6B predecessor capabilities are already Production-deployed
NEXT_DEPENDENCY: focused implementation and verification
EVIDENCE_AS_OF: 2026-09-15

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 1.25h planning value; 0.8-2.0h range
KNOWN_CURRENT_TRACK_REMAINING: P6C 0.8-2.0h plus separately estimated P7-P9; no safe aggregate subtraction asserted here
ARCHITECTURE_GATE_REMAINING: NONE for this bounded package
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE; not recalculated in this Accounting package
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE; repository roadmap source not recomputed here
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: predecessor P6 package ledger exists in prior WPs; package-local actual pending
ESTIMATE_CONFIDENCE: HIGH for bounded code scope
ESTIMATE_AS_OF: 2026-09-15
ESTIMATE_METHOD: touched-path analysis + reuse of existing P6A/P6B gates + focused regression

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.20h
CORE_EXECUTION: 0.45h
TEST_AND_VALIDATION: 0.40h
REMEDIATION_BUFFER: 0.20h
ESTIMATED_EFFECTIVE_HOURS: 1.25h
ESTIMATE_RANGE: 0.8-2.0h
ESTIMATE_BASIS: two bounded service integrations + candidate-store trigger + focused tests; no schema/UI
OWNER_REVIEW: INCLUDED
MERGE: INCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: INCLUDED
DEPLOYMENT: EXCLUDED
