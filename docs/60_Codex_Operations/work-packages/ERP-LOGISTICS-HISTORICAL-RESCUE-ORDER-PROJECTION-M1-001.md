# ERP-LOGISTICS-HISTORICAL-RESCUE-ORDER-PROJECTION-M1-001

STATUS: IMPLEMENTED / VERIFICATION_PASS / PRODUCTION_PREVIEW_PENDING
BASELINE: bf1f3373a0089e3865313be5b93a73d5a1bbbe81
BRANCH: feature/erp-logistics-historical-rescue-order-projection-m1-001
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK

## Objective
Safely project committed Historical Review Rescue evidence into at most one canonical Historical Order per source row, without creating active logistics state.

## Frozen Production reconciliation
- 141 rescue source rows / 277 rescue evidences.
- 121 safe source rows -> 121 candidate Historical Orders / 244 tracking legs.
- 20 Review rows / 33 legs remain fail-closed.
- 106 Ready rows use direct canonical materialization rules.
- 15 Ready rows use the exact 2026-12 -> 2025-12 rollover rule already proven by Date Reconciliation.
- Existing Order rows in this rescue set: 0.
- Existing ShipmentAttempt tracking conflicts in safe set: 0.
- Cross-source tracking conflicts in safe set: 0.
- ERP identity exhaustion in safe set: 0.
## Scope
- Add additive rescue-order projection batch/row ledgers.
- Preview one row per rescue source row, preserving every evidence id/hash/tracking leg in the frozen manifest.
- Reuse canonical HistoricalOrderMaterializationService SKU/quantity/date evaluation.
- Require full-import source-order-number uniqueness, exact historical external-reference absence, and no tracking conflict.
- Freeze deterministic ERP order identity through OrderIdentityService.
- Apply is gated by `LOGISTICS_HISTORICAL_RESCUE_ORDER_APPLY_ENABLED`, default OFF.
- Apply locks source/evidence facts, revalidates the complete frozen plan, then creates only canonical Orders + historical external references in one transaction.

## Prohibitions
- No Shipment creation.
- No ShipmentAttempt creation in R4.
- No Reservation, ManualSync, LogisticsReview, warehouse inference, carrier inference, or active logistics queue mutation.
- No fuzzy Product/SKU/KOL/Order identity matching.

## Exact rollover contract
- Anchor row 78 must be `KOL010101` parsed as `2026-01-01`.
- Candidate source row must be 3..77.
- Current parsed source date must be `2026-12-DD`.
- `KOLMMDDNN` month/day must exactly match source date month/day.
- Proposed date is exactly one year earlier.
- Canonical SKU/quantity/date materialization is replayed after substitution; any remaining failure stays Review.
## Verification
- Focused HistoricalRescueOrderProjection: 12 tests / 69 assertions PASS.
- Historical Import + Materialization + Date Correction + ShipmentAttempt + Fulfillment/Allocation regression: 69 tests / 468 assertions PASS on `main@bf1f337` baseline.
- PHP lint: PASS for service, models, migration, test, and canonical evaluator change.
- `git diff --check`: PASS.
- Architecture A/B: branch and pure `main@bf1f337` both have the same 3 pre-existing ModuleArchitecture baseline failures; no new failure class. MigrationIndexNameTest passes.

## Production gate
Production deployment is not authorized by this package alone. The next gate is additive migration + R4 Production Preview with Apply OFF. Preview must reproduce the frozen 121 Ready / 20 Review classification before any real Order materialization.

## R5 dependency
R5 ShipmentAttempt projection begins only after R4 Production Apply is separately accepted. Current read-only warehouse precheck for the 244 safe legs is 243 exact warehouse legs / 1 unresolved leg; R4 does not implement or write those attempts.
