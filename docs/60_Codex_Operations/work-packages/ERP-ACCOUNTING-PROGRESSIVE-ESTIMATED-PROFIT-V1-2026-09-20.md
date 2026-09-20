# ERP Accounting Progressive Estimated Profit V1 — 2026-09-20

## Status

IMPLEMENTED / REGRESSION PASS / PRODUCTION READ-ONLY PROBE PASS / DEPLOY PENDING

## Owner correction

The prior completeness presentation incorrectly allowed missing required cost evidence to null the entire estimated-profit result. The corrected business contract is:

> Once revenue evidence is valid, the system must always produce an estimated profit. Missing cost facts lower confidence and remain visibly unresolved; they do not suppress the number. Real cost evidence progressively replaces estimates. Only 10/10 confirmed cost facts produce Final Profit.

Revenue itself remains a hard boundary. A Woo order that is failed/pending/unpaid must not receive fabricated profit merely because product-total fields exist.

## Cost resolution priority

For each cost type, Estimated Profit resolves in this order:

1. posted confirmed / actual cost;
2. posted estimated cost;
3. current read-only materialization/projection candidate;
4. projection-only historical / rule fallback.

Projection-only fallbacks never write `order_cost_entries`, never change `financials.completeness_status`, and never qualify an order as Final Profit.

## Projection-only fallbacks

- `product_cost`: historical confirmed product-cost / net-revenue median ratio when order-specific evidence is unavailable.
- `warehouse_cost`: historical confirmed warehouse-cost / net-revenue median ratio.
- `first_mile_cost`: historical expected cost ratio (mean), including legitimate zero China-direct observations. Mean is intentional because the historical population contains both zero-cost direct and positive overseas fulfillment; median collapses to zero.
- `overseas_handling_cost`: same mixed-route expected-ratio method as First Mile.
- `last_mile_cost`: existing P8.2 actual / route estimate / comparable-history logic when available; otherwise historical confirmed last-mile / net-revenue median ratio.
- `payment_fee`: owner rule 3.5% of provider order total / manual paid basis.
- `customs_tax`: current owner rule 0.
- `kol_commission`, `refund_replacement_loss`, `other_variable_cost`: provisional projection zero only when no order-specific evidence is available.

If no eligible historical source exists for a cost type, a final low-confidence projection fallback of zero is allowed only in the read model so the estimate remains numeric. It is explicitly marked `very_low` confidence and never persisted.

## Progressive semantics

The projection exposes estimated total cost, estimated profit, estimated margin, confirmed-cost count, posted-estimate count, fallback-estimate count, projection confidence, and per-cost projection source/method.

`missing_required_cost_count` now means required components still dependent on fallback evidence. It no longer gates whether estimated profit exists.

Final Profit remains unchanged: strict canonical Accounting completeness; all ten cost facts confirmed/reconciled; no fallback or estimate can promote Final Profit.

## Revenue safety boundary

Provider-import revenue is estimate-ready only when merchandise / discount / shipping / refund facts are present, provider total and collected tax are present, `paid_at` exists, provider order status is `processing`, `completed`, or `refunded`, and provider reconciliation is `matched`.

Confirmed-manual and legacy revenue retain their existing manual/legacy evidence contracts.

## UI

Order Profit list shows `预估总成本`, `预估利润`, `预估 Margin`, separate `最终确认利润`, plus fallback count and strict Final confirmed count.

Order Profit detail keeps estimated total cost and estimated profit visible even with missing cost evidence; the 10-line formula displays projection values for unresolved costs; projection confidence is shown; real facts progressively replace estimates; Final Profit remains visually distinct.

## Regression

Final relevant run:
- P8.2 + Profit Dashboard + Continuous Delta + Multi-shipment: 64 warnings / 500 assertions / 0 failures.
- Dedicated tests prove missing required cost evidence still produces numeric estimated profit; projection fallback never persists cost rows; historical Product Cost ratio is used without historical-actual back-propagation; mixed zero/positive First Mile history uses expected mean rather than zero median; unpaid Woo failed order does not receive estimated profit; strict Final remains blocked while any cost is estimated.
- Vite production build: 936 modules PASS.
- PHP syntax and `git diff --check`: PASS.

## Production read-only probe

Against `byl_erp_prod` on 2026-09-20:
- revenue-ready customer Orders: 5,583 at probe time;
- latest 50 revenue-ready Orders sampled;
- numeric estimated profit: 50/50;
- elapsed: ~146.8 ms total / ~2.94 ms per Order;
- `order_cost_entries` write delta: 0.

Example ERP `007-0919-c40801`: estimated total cost USD 30.35; estimated profit USD 48.65; estimated margin 61.58%; 2 confirmed cost components; 8 fallback components; projection confidence low.

Fallback evidence observed in Production:
- Product Cost median ratio: 0.19936709 (340 samples)
- First Mile expected mean ratio: 0.00701632 (1,860 samples)
- Overseas Handling expected mean ratio: 0.00701279 (1,859 samples)
- Last Mile median ratio: 0.13175951 (142 samples)

Control ERP `007-0919-c40802`: Woo status `failed`, `paid_at = null`, estimated ready=false, estimated profit=null.

## Safety

- no migration;
- no Production Finance write;
- no historical actual-cost fabrication;
- no Product Cost effective-date rewrite;
- no P7/P8/P9 ledger mutation;
- no `completeness_status` promotion;
- no ERP Changelog write.

## Deployment gate

Production deployment must overlay the actual current runtime and must not accidentally ship unrelated GitHub-main frontend/runtime changes. After deploy, authenticated Owner UAT must confirm list + detail behavior. Do not record Owner UAT PASS until explicit Owner `pass`.
