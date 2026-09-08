# ERP-PRODUCTION-STABILITY-V1-P1-001

## Scope

Bounded Logistics Performance V2 follow-up after Production Stability P0. Remove the two confirmed residual `/logistics/pending` N+1 groups without changing fulfillment behavior, schema, permissions, routing policy, or persistence semantics.

## Baseline

- Base: `origin/main@7b075df066f9d0503acc96f5c8a7d085530b4457`
- Branch: `fix/erp-production-stability-v1-p1-001`
- P0 Production evidence: 50-row pending page reduced from 950 SQL queries to 214.
- Residual confirmed groups: per-order `order_external_references` loads and repeated approved packaging-standard reads from fulfillment-plan cost estimation.

## Implementation

1. Eager-load `externalReferences` with the pending-page order page so automatic-allocation eligibility does not issue one relation query per order.
2. Prepare approved packaging standards and adopted shipping-reference-weight standards once for all product ids on the page and pass that request context through fulfillment-plan preview/cost estimation.
3. Keep existing fallback behavior when no prepared context is supplied, so other callers remain backward compatible.
4. Add a regression test proving both residual reads happen once across multiple pending rows.

## Acceptance gates

- Focused Logistics/unit tests pass.
- Typecheck/build pass.
- Production-equivalent read-only benchmark confirms query reduction below the P0 214-query baseline; target <100 queries for the same 50-row page.
- No schema migration and no Production deployment in this package without a separate Owner gate.

## Mainline reconciliation

- Rebased onto `origin/main@7b075df066f9d0503acc96f5c8a7d085530b4457` after PR #265 added distinct shipping-reference-weight evidence for actual-weight factory routing.
- P1 preserves PR #265 semantics and extends the prepared request context to batch both packaging standards and shipping-reference-weight standards.

## Production-equivalent evidence before PR #265 rebase

Using the live Production database in read-only mode and the exact Production runtime baseline, the isolated P1 delta reduced the same 50-row pending projection from 214 SQL queries to 22, with both runs returning PASS. This benchmark proves the residual N+1 elimination; the rebased candidate must still pass latest-main regression tests before delivery.

## Rebased validation

- Focused Logistics / fulfillment suite: `60 warnings / 526 assertions / 0 failures`.
- Includes PR #265 actual-weight evidence tests and the new pending-page batching regression test.
- `npm run typecheck`: PASS.
- `npm run build`: PASS (existing chunk-size/plugin timing warnings only).
- `git diff --check`: PASS.
- No full test suite claim is made for this package.
