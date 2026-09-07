# ERP-ACCOUNTING-COST-MATERIALIZATION-V1-1-001

## Status
IMPLEMENTATION VERIFIED / DELIVERY READY / BACKEND ONLY / PRODUCTION APPLY NOT AUTHORIZED

## Baseline
- Branch: `feature/erp-accounting-cost-materialization-v1-1-001`
- Baseline: `main@2415ef4c194b7653814352d73f6a392f4228c7d8`
- Order Profit V1 A1-A8 is CLOSED / MERGED.

## Goal
Materialize evidence-backed order cost facts into Finance without changing the Order Profit formula. Missing stays missing. Reference cost stays estimated. Existing stronger facts are never overwritten by weaker evidence.

## Frozen source contract
### Product reference → `product_cost`
- Product cost is reference-only, never historical actual.
- Same-currency only: `product.cost_currency` must equal order Finance currency.
- Every order item must have a bound Product and positive quantity.
- Unambiguous purchase path: `standard_purchase_cost` is present and both `standard_production_cost` and `standard_packaging_cost` are null.
- Unambiguous production path: `standard_purchase_cost` is null and `standard_production_cost` is present; unit reference = production + packaging when packaging is present.
- Purchase and production paths both populated => ambiguous, do not materialize.
- Any item without an unambiguous reference => whole order `product_cost` remains missing; never partially sum and pretend complete.
- Materialized entry: provenance=`estimated_reference`, status=`estimated`.

### Carrier billing → `last_mile_cost`
- Order has one canonical Shipment.
- Shipment billing evidence requires non-null `actual_cost`, currency, and evidence identity (`billing_document_number` or `provider_reference` or `data_source`).
- Same-currency only.
- Materialized entry: provenance=`provider_import`, status=`confirmed`.
- Source timestamp prefers `billed_at`, otherwise billing row `updated_at`.

### AffiliateWP referrals → `kol_commission`
- Include only rows with `order_id` exact-linked, `order_resolution_status=resolved`, `reconciliation_status=present`, provider status in `unpaid|paid`.
- Same-currency only; mixed currencies or any eligible foreign-currency row blocks automatic materialization for that order.
- Sum all eligible exact-linked referrals for the order using decimal-safe arithmetic.
- Materialized entry: provenance=`provider_import`, status=`confirmed`.
- pending/rejected/failed/draft and missing-from-snapshot never count.

### Unsupported in V1.1 package
- `payment_fee`: no authoritative provider fee source exists; remain missing.
- `warehouse_cost`, `first_mile_cost`, `overseas_handling_cost`, `customs_tax`, `refund_replacement_loss`, `other_variable_cost`: remain missing unless already provided by stronger facts.
- No guessed percentages, no live FX conversion, no historical backfill assumption.

## Write-strength / idempotency rules
- Never overwrite provenance in `actual|confirmed_manual|provider_import|allocated_actual` with `estimated_reference`.
- Provider materializers may update only entries they own by stable `source_reference` namespace, or fill a missing cost type.
- Never overwrite `confirmed_manual` or `actual` even if provider data differs; report conflict/blocker instead.
- Re-running with unchanged source evidence produces no row churn and no duplicate cost entries.
- If a previously materializer-owned source becomes unavailable/invalid, do not silently delete an existing confirmed cost; report stale/conflict for explicit reconciliation.

## Architecture
Finance owns orchestration and write policy. Finance Application may depend only on other modules' `Application/Contracts`, never their Infrastructure models. Products/Logistics/KOL each expose read-only evidence contracts backed by Eloquent adapters in their own module.

## Required implementation
- Finance `CostMaterializationService` with preview + bounded apply per Order.
- Product/Logistics/KOL evidence reader contracts + Eloquent implementations + provider bindings.
- Result payload includes candidates, applied/noop/blocked, blockers, provenance, source_reference.
- Backend-only console command for local/operator use; dry-run by default, bounded `--order=` / `--limit=`; explicit `--apply` flag. No scheduler in this package.
- Recompute Finance completeness after successful apply without changing revenue facts.
- Audit materialization writes.

## Required tests
- Product purchase reference estimated; production+packaging estimated; ambiguous/missing item blocks; same-currency enforcement.
- Carrier actual confirmed, idempotent, stronger manual fact protected.
- KOL paid+unpaid summed; pending/rejected/failed excluded; unresolved/missing snapshot excluded; foreign/mixed currency blocks.
- No payment-fee guessing.
- Existing OrderProfit V1 / ConfirmedLoss / Dashboard semantics remain green.
- Cross-module Application dependency rules PASS.

## Gates
- No new UI, so no visual gate in this package.
- Local/test DB only for apply validation.
- No Production deploy, migration, cost backfill, or Finance writes authorized.

## Implementation Evidence
- Added read-only Product, Logistics carrier-billing, and KOL AffiliateWP evidence contracts, Eloquent adapters, and module-provider bindings.
- Added Finance `CostMaterializationService` preview/apply orchestration with same-currency checks, missing-as-missing behavior, decimal-safe aggregation, stable source references, stale-source blocking, write-strength protection, idempotent writes, completeness refresh, and audit records.
- Added `finance:materialize-costs`, dry-run by default, bounded by `--order`/`--limit`, with explicit local/testing-only `--apply`; no scheduler was added.
- Added decimal arithmetic unit coverage and updated only the Architecture module file/directory lists for files introduced by this Work Package. No migration or UI file was changed.
- PHP lint and `git diff --check` passed for all introduced/changed PHP files.
- Direct PHPUnit regression gate passed: 17 tests / 214 assertions across Cost Materialization, DecimalMoney, Order Profit V1, Profit Dashboard, and MVP1 analysis workflows.
- Vite production build passed after reusing package-lock-identical local dependencies; no package install from the internet was required.
- Temp SQLite command runtime passed for dry-run and explicit local/testing `--apply`; no scheduler reference exists.
- Targeted Architecture gate passed: 4 tests / 22 assertions, and new Finance Application files import foreign modules only through `Application/Contracts`; no foreign `Infrastructure` import was introduced.
- Full `ModuleArchitectureTest` is not green on clean canonical `main@2415ef4`: the same pre-existing baseline reproduces 3 failures / 5 passes before this Work Package. A bounded reconciliation attempt exposed broader historical Application→Infrastructure and route snapshot debt, so unrelated architecture debt was not folded into this Accounting package.
- `origin/main` still equals the package baseline `2415ef4c194b7653814352d73f6a392f4228c7d8`; no latest-main divergence exists at verification time.
- No Production, remote provider, live credential, migration, backfill, or Finance production write was used.

PROJECT POSITION
PROGRAM_ROADMAP: Accounting / ROI Expansion → Production Hardening / Expansion
CURRENT_TRACK_PHASE_MAP: Order Profit V1 A1-A8 CLOSED → Cost Materialization V1.1 DELIVERY READY → Production apply gate WAITING
COMPLETED_PHASES: Order Profit V1 A1-A8; Cost Materialization V1.1 implementation and local verification
CURRENT_PHASE: Cost Materialization V1.1 delivery
CURRENT_WORK_PACKAGE: ERP-ACCOUNTING-COST-MATERIALIZATION-V1-1-001
PACKAGE_STATUS: IMPLEMENTATION VERIFIED / DELIVERY READY / PRODUCTION APPLY NOT AUTHORIZED
WAITING_PHASES: GitHub PR/checks/merge; later explicit Production apply gate
BASELINE: main@2415ef4c194b7653814352d73f6a392f4228c7d8
GITHUB_STATE: local branch ready for commit/push/PR
SOURCE_CONFLICTS: clean main full ModuleArchitectureTest already has 3 pre-existing failures; targeted no-new-violation gate passes for this Work Package
GLOBAL_GATE: no-new-architecture-violation PASS; global historical architecture debt remains separate
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE
NEXT_DEPENDENCY: Git delivery and required CI checks
EVIDENCE_AS_OF: 2026-09-07

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 1.5h planning value, 1.0-2.5h range
KNOWN_CURRENT_TRACK_REMAINING: Git delivery/CI plus future Production apply gate
ARCHITECTURE_GATE_REMAINING: historical global architecture debt is separate from this package
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; no accepted Work Log ledger exists for this package
ESTIMATE_CONFIDENCE: HIGH
ESTIMATE_AS_OF: 2026-09-07
ESTIMATE_METHOD: bounded backend implementation + focused Finance regression + targeted architecture verification

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.3h
CORE_EXECUTION: 0.6h
TEST_AND_VALIDATION: 0.4h
REMEDIATION_BUFFER: 0.2h
ESTIMATED_EFFECTIVE_HOURS: 1.5h
ESTIMATE_RANGE: 1.0-2.5h
ESTIMATE_BASIS: frozen backend materializer with three evidence adapters, command, tests, and verification
OWNER_REVIEW: EXCLUDED
MERGE: INCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; no accepted Work Log ledger exists
ACTUAL_HOURS_BREAKDOWN: UNKNOWN WITH EVIDENCE
VARIANCE: UNKNOWN WITH EVIDENCE
VARIANCE_REASON: accepted Work Log ledger unavailable
