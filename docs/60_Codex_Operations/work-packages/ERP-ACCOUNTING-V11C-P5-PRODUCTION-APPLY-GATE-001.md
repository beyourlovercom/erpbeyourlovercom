# ERP Accounting V1.1C-P5 Production Payment Fee Apply Gate

- Owner-approved rule: `payment_fee = provider_order_total × 3.5%`.
- Production target frozen from dry-run: seen=6095, apply=6095, blocked=0, new FX freezes=1502.
- Production `--apply` requires process-local `ERP_ACCOUNTING_PAYMENT_FEE_PRODUCTION_APPLY_ENABLED=true`.
- Production apply also requires exact `--expect-*` counts; mismatch aborts before any write.
- Command scope is payment_fee only. Product, first-mile, customs, last-mile, KOL, warehouse, and other costs are excluded.
- Backup #48 must be ready before Production write: UUID `167f0b54-6ebf-452f-b030-068deffdb679`, SHA256 `3f4637395e46d46f641c5d0f371099ac44cbf4e3ff85b99fdb391f56db8b41c3`.

## Verification
- focused production-gate/payment-fee suite: 4 tests / 42 assertions PASS
- full Finance suite: 60 tests / 511 assertions PASS
- PHP lint + git diff --check PASS
- Architecture remains repo-known 3 frozen-baseline failures / 8 tests / 129 assertions; no new failure class
- latest main at final gate: d6884561cb40743cb0f2e6c63c294d495d8222e6; zero overlap
