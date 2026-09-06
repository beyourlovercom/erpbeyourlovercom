# ERP-ACCOUNTING-CONFIRMED-LOSS-ORDERS-A7-001

STATUS: IMPLEMENTED / FOCUSED_VERIFICATION_PASS / PR_PENDING
BASELINE: origin/main@7247c3a41b51a8b3878dc3193a1215bb59168d3b
BRANCH: feature/erp-accounting-confirmed-loss-orders-a7-001
WORKTREE: /Users/a/AI/007/worktrees/erp-accounting-confirmed-loss-orders-a7-001
VERIFICATION_TIER: HIGH_RISK
PRODUCTION_WRITE: NOT_AUTHORIZED

## Objective

Make Finance the single authority for the ERP `亏本单` projection. A true loss Order is derived only from complete Finance facts and never from missing or estimated data.

## Frozen rule

An Order is a confirmed loss only when the Finance projection is `CONFIRMED` or `RECONCILED`, `profit_status = confirmed`, and `contribution_profit < 0` in the Order transaction currency.

`INCOMPLETE`, `UNPOSTED`, and `ESTIMATED` Orders are never loss Orders. The projection never creates an Order status or operational marker and never mutates Logistics state.

## Scope delivered

- Added Finance-owned `ConfirmedLossReader` application contract and `ConfirmedLossProjection` implementation.
- Orders `view=loss` now delegates to the Finance confirmed-loss reader instead of duplicating the legacy `amount_paid - refund - costs` SQL formula.
- Logistics `/logistics/loss` and Logistics status-bar loss count use the same Finance reader.
- Carrier workspace loss count no longer hard-codes `0`; it uses the same Finance projection.
- Historical Logistics Orders remain excluded from operational Logistics loss views/counts.
- Authoritative Woo `provider_import` settlement semantics are supported; unpaid/unreconciled Woo evidence is excluded.
- Estimated cost provenance/status is excluded from confirmed loss.

## Verification evidence

- Orders saved-view + confirmed-loss focused tests: PASS, including positive/incomplete/estimated exclusion and Woo provider-import cases.
- Finance Order Profit V1 tests: PASS.
- Logistics historical-isolation/status-bar focused regressions: PASS.
- Carrier workspace regression: PASS.
- Cross-module application contract architecture rules: PASS.
- Production build: PASS.
- `git diff --check`: PASS.
- Full repository Architecture frozen-file baseline test remains a known mainline governance drift unrelated to A7; A7 does not rewrite that global baseline.

## Current gate

A7_IMPLEMENTATION = COMPLETE
A7_FOCUSED_VERIFICATION = PASS
OWNER_VISIBLE_UI_CHANGE = NONE
PRODUCTION_DEPLOYMENT = NOT_AUTHORIZED
NEXT = PR / CI / MERGE → A8 PROFIT DASHBOARD / AGGREGATION V1
