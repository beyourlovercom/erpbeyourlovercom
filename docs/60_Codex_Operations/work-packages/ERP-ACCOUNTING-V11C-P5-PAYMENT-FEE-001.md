# Accounting V1.1C-P5 — Payment Fee 3.5%

## Frozen owner rule
- payment_fee = order_financials.provider_order_total × 3.5%
- provider_order_total is the authoritative settlement basis.
- refunds are separate evidence and do not reverse or reduce the original payment fee.
- missing or negative provider_order_total fails closed.
- payment fee is recorded in transaction currency.
- non-USD posting freezes Accounting FX snapshot through the existing closed Global FX resolver bridge.

## Scope
- Add payment_fee candidate to CostMaterializationService.
- Remove payment_fee from unsupported list.
- Add payment-fee-only preview/apply methods.
- Add finance:materialize-payment-fees dry-run command; --apply remains local/testing only.
- Preserve historical_logistics exclusion and strong-fact idempotency.
- No Production writes in this WP.

## Verification
- Focused CostMaterialization: 15 tests / 129 assertions PASS.
- Full Finance suite: 59 tests / 501 assertions PASS.
- `finance:materialize-payment-fees` registration PASS.
- PHP lint + git diff --check PASS.
- Architecture remains repo-known 3 frozen-baseline failures / 8 tests / 129 assertions; no new failure class.
- latest main `016687a42e3b2dfe2d8a569dd8869fb95d782e2c`; zero overlap at final gate.
- No Production writes.
