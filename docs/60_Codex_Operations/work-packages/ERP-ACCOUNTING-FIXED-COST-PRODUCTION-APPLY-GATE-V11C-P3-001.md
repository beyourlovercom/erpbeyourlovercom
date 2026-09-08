# ERP Accounting V1.1C-P3 — Fixed Cost Production Apply Gate

## Scope
- Add a dedicated `finance:materialize-fixed-costs` command.
- The command may write only `customs_tax` and `first_mile_cost` candidates produced by the frozen P3 rules.
- Default mode is dry-run. Production `--apply` requires the single-process gate `ERP_ACCOUNTING_FIXED_COSTS_PRODUCTION_APPLY_ENABLED=true`.
- Batch limit is capped at 10,000 operational customer Orders; historical logistics remain excluded.

## Frozen Production apply scope
- `customs_tax`: Owner-confirmed zero, in each Order currency.
- `first_mile_cost`: USD 1/order only for exact known overseas warehouse + USD Order; China-direct exact warehouse is zero; foreign-currency overseas cases remain FX-blocked.
- No Product Cost, Last-mile, KOL, payment fee, warehouse cost, overseas handling, refund/replacement loss, or other variable cost may be written by this command.

## Gate evidence
- Owner approved `Accounting V1.1C-P3 Production Apply Gate`.
- Production dry-run before this patch: 6,094 customs apply; 1 first-mile apply; 11 first-mile FX-blocked; 6,082 missing canonical Shipment; zero writes.
- Backup #45: UUID `3e657cba-90ee-4443-8672-81388708e5c9`, SHA256 `c82ea8d614bc4a12b5d7a053fa6c925080467064a08d3ac53b4ef92d1df24645`.

## Safety
- Production gate is environment-variable based and defaults false.
- Re-run after apply must be idempotent (`noop` for already materialized fixed facts).
- Post-verify must prove no unauthorized cost types were created.
