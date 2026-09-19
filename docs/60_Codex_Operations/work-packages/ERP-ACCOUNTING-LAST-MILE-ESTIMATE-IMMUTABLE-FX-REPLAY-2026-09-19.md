# ERP Accounting Direct Last-Mile Estimate Immutable-FX Replay — 2026-09-19

## Scope

Bounded follow-up to the P1 Accounting evidence-completeness audit. This work package fixes direct P6A routing estimates that were being re-evaluated when current Settings FX changed even though the underlying fulfillment/routing business evidence had not changed.

No Production business-data write is required to close this defect.

## Production evidence

The pre-fix full Production preview exposed exactly three direct last-mile estimate `apply` candidates:

- `007-1016-c29683` / external `29683-MF414A`: EUR 5.05 → EUR 5.02
- `007-0903-c40120` / external `40120-MF414A`: EUR 5.05 → EUR 5.02
- `007-0906-c40228` / external `40228-MF414A`: EUR 5.05 → EUR 5.02

All three use DE fulfillment and the same MF414A business evidence:

- unchanged Shipment evidence timestamp from 2026-09-06
- unchanged DHL quote `2025-天运-德国-邮好运德国海外仓服务报价（生效时间：20250505）.xlsx`
- unchanged DHL quote effective date `2025-05-05`
- unchanged billed weight 2 kg
- unchanged route components: EUR 4.0891 domestic transport + EUR 1.00 owner handling
- unchanged approved packaging measurement: 33.70 × 16.90 × 13.10 cm, 1.340 kg

MF414A packaging measurement #2 was the already-approved system unit-normalization repair created at 2026-09-14 02:32:18. All three existing last-mile estimates were first posted later that morning, so no packaging/weight business evidence changed after posting.

The only changing input was the Settings FX reference used by the routing estimator and posting bridge. That dynamic EUR → CNY → transaction-currency loop changed the historical estimate by a few cents and incorrectly created replay `apply` candidates.

## Contract

P8.2's 0–30 day maturity guard remains unchanged. It governs creation of new automatic P8.2 fallback estimates. This fix applies to direct P6A routing estimates and does not expand or bypass the P8.2 maturity contract.

## Fix

Direct P6A routing evidence now exposes two stable fields:

- `business_evidence_at`: newest timestamp across the selected Shipment/allocation, destination address, active approved packaging evidence and adopted shipping-weight evidence.
- `business_evidence_fingerprint`: SHA-256 of stable business inputs including destination, fulfillment source, items, carrier/service, native routing components and measurement/routing evidence. Dynamic Settings FX output/reference date is intentionally excluded.

New postings use:

`last-mile-estimate:v2:order:{order_id}:evidence:{business_evidence_fingerprint}`

Replay rules:

- same v2 evidence fingerprint → noop and preserve the already-posted amount/FX snapshot;
- v2 fingerprint changes because real business evidence changed → estimate remains refreshable;
- legacy v1 rows are treated as the same frozen evidence when current `business_evidence_at` is not newer than the existing posting time;
- legacy v1 rows remain refreshable when business evidence is newer than the posting;
- strong actual/manual facts remain protected exactly as before.

## Regression evidence

- PHP syntax checks: PASS on changed PHP files.
- `git diff --check`: PASS.
- Last-mile + KOL + Accounting Continuous Delta focused regression: 30 tests / 172 assertions / no failures.
- Multi-shipment focused regression excluding the already-proven clean-main Inertia baseline failure: 10 tests / 82 assertions / no failures.
- Existing direct-estimate regression still proves an adopted shipping-weight change changes the v2 evidence fingerprint and remains `apply`-eligible.
- `FulfillmentActualWeightEvidenceTest` has two unrelated current-main failures; both reproduce unchanged on clean `origin/main` and are not introduced by this patch.

## Candidate-runtime Production dry-run

The patch was overlaid onto an isolated copy of the current Production runtime. The live Production `current` symlink was not changed and both commands were dry-run only.

Direct last-mile:

`seen=6304 / apply=0 / noop=8 / blocked=6296 / fx_freeze=0`

Blocked detail:

- `p82_automatic_estimate_age_blocked=5660`
- `overseas_warehouse_required_for_fallback=451`
- `actual_carrier_billing_available=142`
- `china_direct_no_fallback_allowed=43`

The prior three false-positive apply candidates therefore converged to noop without any Production data rewrite.

KOL regression in the same candidate runtime remained stable after PR #379:

`seen=6304 / apply=0 / noop=2417 / blocked=3887 / fx_freeze=0`

## Remaining Accounting completeness backlog

This fix removes replay noise; it does not invent missing evidence. Remaining major gaps continue to be:

1. Product Cost source coverage, including 834 revenue-ready post-2026-07-01 Orders without product_cost.
2. Historical fulfillment evidence: 3,718 revenue-ready Orders currently lack canonical Shipment.
3. Real warehouse/carrier bill coverage as provider samples become available.

