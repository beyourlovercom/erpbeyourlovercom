# ERP-ACCOUNTING-FIXED-COST-RULES-V11C-P3-001

## Scope
Add only Owner-confirmed fixed cost rules that are safe without guessing:
- `customs_tax = 0` for operational customer Orders.
- `first_mile_cost = USD 1/order` for exact overseas-warehouse fulfillment.
- `first_mile_cost = 0` for exact China-direct fulfillment.

`payment_fee` remains unsupported in this WP because the 3.5% percentage base is not yet frozen in the Finance contract. No Production writes are authorized by this WP.

## Frozen warehouse classification
- Overseas: `us-la`, `us-ny`, `de`, `ca`, `us-sj`.
- China-direct: `factory`, `007`.
- Unknown warehouse, missing canonical Shipment, or multiple canonical Shipments fail closed.
- Overseas first-mile is denominated in USD. Non-USD Orders block on `first_mile_fx_conversion_required` pending the transaction/posting FX bridge.
- Zero China-direct first-mile may be represented as zero in the Order currency.

## Production read-only evidence — 2026-09-08
- Operational customer Orders: 6094.
- Canonical Shipment warehouse coverage: `de=11`, `us-la=1`; remaining 6082 have no canonical Shipment.
- Shipment currencies: `de/EUR=11`, `us-la/USD=1`.
- Expected P3 preview with current data:
  - customs confirmed-zero candidate: 6094 apply candidates;
  - first-mile: 1 direct apply (`us-la/USD`), 11 FX-blocked (`de/EUR`), 6082 canonical-shipment-missing.

## Safety
- Historical logistics remains excluded by the existing materializer scope gate.
- No inference from destination country or warehouse name.
- Current Settings FX is management/reference-only and is not used for historical cost conversion.
- Existing stronger/manual facts retain overwrite protection.
- Production `--apply` remains restricted; this WP does not add a Production apply gate.
