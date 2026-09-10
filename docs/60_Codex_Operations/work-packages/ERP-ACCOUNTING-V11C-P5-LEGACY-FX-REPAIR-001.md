# ERP Accounting V1.1C-P5 Legacy FX Repair Gate

## Purpose
Repair only legacy Payment Fee FX metadata created before the frozen-FX idempotency fix.

## Frozen Production evidence
- operational non-USD owner-rule payment_fee rows with complete immutable transaction FX snapshot: 1588
- repair candidates: 81
- base_amount changes: 13
- currencies: AUD=4, CAD=3, EUR=59, GBP=15
- payment_fee local-currency amount remains unchanged
- maximum observed base_amount delta before repair: USD 0.01 per row
- absolute observed base_amount delta total before repair: USD 0.13

## Allowed mutation
For exact owner-rule Payment Fee rows only, align these fields to the already-frozen OrderFinancial transaction FX snapshot:
- exchange_rate
- exchange_rate_date
- exchange_rate_source
- base_amount
- base_currency = USD

## Explicit exclusions
- no payment_fee amount mutation
- no source_reference/provenance/status mutation
- no order_financial FX mutation
- no other cost type mutation
- no new Payment Fee rows
- no Production write in this WP/PR

## Production safety gate
Production apply requires process-local `ERP_ACCOUNTING_PAYMENT_FEE_LEGACY_FX_REPAIR_ENABLED=true` plus exact preflight counts:
- expect-seen = 1588
- expect-candidates = 81
- expect-base-changes = 13

Any count drift aborts before repair. Each applied row records an immutable audit event.
