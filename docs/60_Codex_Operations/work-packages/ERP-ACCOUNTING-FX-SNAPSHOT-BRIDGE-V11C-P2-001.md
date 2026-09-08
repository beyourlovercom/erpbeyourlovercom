# ERP-ACCOUNTING-FX-SNAPSHOT-BRIDGE-V11C-P2-001

## Goal
Bridge the already-closed Settings FX resolver into Accounting posting snapshots so confirmed foreign-currency costs can materialize without re-opening the FX settings line.

## Frozen semantics
- Settings → FX remains the canonical current management/reference resolver.
- Existing `OrderFinancial.fx_rate*` snapshots are authoritative and never revalued.
- A missing non-USD transaction snapshot may be frozen only when a new cost fact is posted.
- `OrderFinancial.fx_rate` is transaction currency → USD.
- `OrderCostEntry.amount/currency` remain in transaction currency.
- `OrderCostEntry.exchange_rate` is source evidence currency → USD; `base_amount/base_currency` freeze the source evidence in USD.
- Product-cost source CNY facts remain unchanged and retain source provenance.
- Production writes remain a separate Owner gate.

## Scope
- add Accounting posting FX bridge using `FxReferenceSettingsService`;
- release confirmed Product Cost foreign-currency preview/materialization;
- release overseas first-mile USD → non-USD preview/materialization;
- preserve idempotency and existing transaction snapshots;
- no `/settings/fx` UI changes; no historical bulk revaluation; no Production apply in this WP.
