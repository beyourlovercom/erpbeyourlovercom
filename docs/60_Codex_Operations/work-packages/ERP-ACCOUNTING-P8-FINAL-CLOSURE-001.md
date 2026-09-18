# ERP-ACCOUNTING-P8-FINAL-CLOSURE-001

**Status:** CLOSED / PRODUCTION ACCEPTED
**Closure date:** 2026-09-18
**Canonical base:** `main@e4cd3feba6e6aeb04298205185ede12a79de1ec1`
**Production:** `erp.beyourlover.com`

## Scope
This closes Accounting P8 as the combined Production acceptance record for P8 KOL Commission, P8.1 Multi-Shipment Cost Aggregation, and P8.2 Estimated→Actual Cost Reconciliation. It does not claim that every non-P8 Accounting completeness gap is solved.

## Final Production state
- KOL Continuous Delta: `ERP_ACCOUNTING_CONTINUOUS_DELTA_KOL_COMMISSION_ENABLED=true`.
- P8.1 Multi-Shipment Aggregation: `ERP_ACCOUNTING_MULTI_SHIPMENT_AGGREGATION_ENABLED=true`.
- P8.2 Estimated Fallback: `ERP_ACCOUNTING_P82_ESTIMATED_FALLBACK_ENABLED=true`.
- `ERP_ACCOUNTING_KOL_COMMISSION_PRODUCTION_APPLY_ENABLED`, `ERP_ACCOUNTING_MULTI_SHIPMENT_PRODUCTION_APPLY_ENABLED`, and `ERP_ACCOUNTING_P82_ESTIMATED_FALLBACK_PRODUCTION_APPLY_ENABLED` are all unset.
- Existing Accounting scheduler remains the sole continuous runner: `finance:materialize-continuous-delta --apply --limit=10000 --no-interaction`.
- Duplicate `(order_id, cost_type)` groups: **0**.

## P8 KOL Commission closure
- Referral mirror: 3,327 resolved / 24 unresolved.
- KOL cost rows: 2,417 `provider_import / confirmed` + 1 pre-existing `confirmed_manual / confirmed`; 2,418 distinct Orders; duplicate KOL Orders = 0.
- Continuous KOL stage is active; dedicated Production apply flag is unset.

## P8.1 Multi-Shipment closure
- Production activation remains on the canonical aggregation flag.
- Final Closure found and fixed immutable-FX replay drift in PR #360 (`6d2b701b64521ad9aaf3f390fd77ce50a22d93b4`). Unchanged `multi-shipment:v1:*` evidence now preserves the already-frozen posting snapshot instead of being revalued by current Settings FX.
- Final Production dry-run: `orders_seen=6281 / shipments_seen=1860 / apply=0 / noop=3858 / blocked=14983 / review=2`; no remaining delta.
- P8.1 focused validation: 11 tests / 97 assertions PASS. Finance full at the hotfix gate: 133 tests / 953 assertions PASS / 1 MySQL-only skip.
- No eligible live Parent with a true N-shipment configuration existed at closure. The first genuine live multi-shipment Parent must receive a post-closure visual canary; no synthetic Production shipment was created merely to satisfy UAT.

## P8.2 Estimated→Actual closure
- Materialized P8.2 estimates: **739**. Matching P8.2 materialization audit records: **739**. Duplicate cost groups: **0**.
- Final historical dry-run converged to `apply=0 / noop=739`.
- Visual UAT V2 (PR #358) passed Owner UAT on Production Order 6996; Order 5063 verified 45+ overdue presentation. Estimated Profit is visibly non-final.
- Automatic maturity guard merged in PR #362 (`e4cd3feba6e6aeb04298205185ede12a79de1ec1`) and is live in Production: 0–30d may create a new automatic estimate; 31+d is blocked from automatic P8.2 fallback and routed to reconciliation/actual-overdue semantics.
- Production read-only proof: Order 7175 (age 0) passed the age gate into normal projection; Order 5754 (age 31) was blocked by `p82_automatic_estimate_age_blocked`. Boundary service proof: 10/30d allowed, 31d reconciliation due, 46d actual cost overdue.
- P8.2 focused validation: 26 tests / 117 assertions PASS. Finance full: 135 tests / 966 assertions PASS / 1 MySQL-only skip.
- Live Estimate→Actual replacement canary is deferred until the first genuine carrier bill matches a persisted Estimate. Fake Actual evidence is prohibited; the first real replacement must emit `finance.estimate_to_actual_restatement`.

## Non-blocking post-closure observations
1. First genuine N-shipment customer Parent: perform formula-page visual canary and record result.
2. First genuine P8.2 Estimate receiving real carrier billing: verify Estimate→Actual audit/variance and UI restatement.
3. Overall 30+d Profit Coverage remains constrained by non-P8 cost completeness. At the final P8.2 UAT audit, 5,762 customer Orders were 30+d; Product Cost was missing on 5,573, and First Mile / Overseas Handling were each missing on 3,995. P8 closure must not be interpreted as 95% overall Final Profit coverage.

## Superseded closure artifact
PR #345 was created before P8.1/P8.2 completion and contains only an earlier P8 KOL/compat closure. It must not be merged. This document and its PR supersede #345.

## Final decision boundary
Accounting P8 is formally closed by Production verification and Owner acceptance. This documentation merge records that closure; no additional Production data write is required. ERP Changelog remains manual-only and was not updated.
