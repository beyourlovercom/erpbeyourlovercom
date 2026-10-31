# ShipOut/MorePlus R10 — read-only Canary eligibility and warehouse-code guard

- Work package: [Issue #584](https://github.com/beyourlovercom/erpbeyourlovercom/issues/584).
- R9 dependency: PR #580 merged `9d6a8a50624a9e54cdb3a84a1fe82880d615d6fe`, deployed **dark**, no Production READ/DISPATCH/APPLY enabled.
- R10 work package has **no approval to send a real provider POST**. This change is an isolated, non-visual preflight bug fix and regression tests. No Production deployment is implied.

## R10-A: production ERP and historical provider stock, read-only

A sanitized, DB-server `READ ONLY` transaction with rollback was run on the US Mac against the existing Production ERP. Local audit: `/Users/a/AI/uat/shipout-r9/r10_candidates_readonly.php`. No provider call, no DB write, no address/name/phone output.

- MorePlus ERP warehouse actual `warehouses.code` is **`us-la`** (lowercase) and exists as one Production warehouse.
- Existing ShipOut factory/controller/tracking guards incorrectly compared against uppercase `US-LA` using case-sensitive equality. Real Production candidate blocked before SKU availability evaluation.
- Among the newest up to 200 US-LA pending-shipment orders, **one** was found: ERP internal order **6826**, pending, Dede approval present, USPS, no tracking, shipping country US, basic recipient fields present.
- That order has `VA617A-PINK × 1`; the 2026-10-30T23:46:14Z protected ShipOut production stock snapshot has **no currently verified provider physical SKU candidate** for its canonical ERP Product. Thus **order 6826 remains NOT ELIGIBLE** even after warehouse normalization. Do not auto-map, auto-approve, dispatch or select it without physical identity verification, fresh stock, duplicate-OMS read, and exact-order Owner PASS.
- Historical provider stock snapshot: 45 stock SKUs, 1,999 available units; 42 verified canonical identities and three blocked (`BY489A`, `BA561A`, `Logo Sign`). Not a fresh stock guarantee and not an inventory reservation.

## R10-A bounded code remediation

- Preserve the exact warehouse identity guard but normalize case/outer whitespace before comparing to `US-LA`; missing or wrong warehouse codes still fail closed.
- Apply consistently to factory, ShipOut workbench controller and **sandbox-only** Tracking Apply guard. No shipping mode, provider host, signature, owner allowlist, DB schema, product alias, or shipping outcome change.
- Add regression: lowercase real ERP code passes **sandbox preflight only** without any provider network call or dispatch ledger write; unrelated `us-ny` is still HTTP 404, no provider call.
- All Production gates stay OFF. This patch does not add Production dispatch or an entry point and must be tested independently against exact GitHub SHA.

## R10-B pending standalone implementation

Production dispatch is not implemented by the R9 GET-only reader. R10-B needs separate implementation of **one real POST only after exact-order Owner PASS**, including:
- exact ERP order/warehouse/reference/SKU+qty/shipping-mode whitelist, independent Production credentials;
- fixed endpoint `POST /open-api/oms/order/batchSubmitAsync`; GET-before-POST duplicate query, complete verified SKU stock fresh read, no simultaneous attempt; atomic once-per-order ledger before POST;
- no automatic retry on timeout; async ACK is **not** proof of outbound or shipped;
- status query and manual reconciliation first; no Woo/AfterShip/Tracking Apply until independent approval;
- use documented UTC source for `orderShipments[].shippedTime` (China display Asia/Shanghai only).
- R10-C exact real order and first Production POST approval is a separate Owner gate.

## Current release gate

- `R10_FIRST_REAL_OUTBOUND=NOT_AUTHORIZED`
- `SHIPOUT_PRODUCTION_READ_ENABLED=false`
- `SHIPOUT_PRODUCTION_DISPATCH_ENABLED=false`
- `SHIPOUT_PRODUCTION_TRACKING_APPLY_ENABLED=false`
- `R10_CANDIDATE_ORDER_ELIGIBLE=NO` (at historic snapshot; must rerun live preflight and provider duplicate query)

This document must not be interpreted as approval to activate any Production ShipOut endpoint.
