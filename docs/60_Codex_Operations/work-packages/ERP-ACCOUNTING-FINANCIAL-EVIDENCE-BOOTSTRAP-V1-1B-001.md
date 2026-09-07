# ERP-ACCOUNTING-FINANCIAL-EVIDENCE-BOOTSTRAP-V1-1B-001

## Status
LOCAL VALIDATION PASS / PR READY / BACKEND ONLY / PRODUCTION APPLY NOT AUTHORIZED

## Baseline
- Branch: `feature/erp-accounting-financial-evidence-bootstrap-v1-1b-001`
- Baseline: `origin/main@e40db0d55cdceea1f0a40d89346ff07ee2bd3dec` (latest-main reconciliation; zero overlap with V1.1B paths)
- Accounting V1.1 Cost Materialization is merged; Production Accounting schema is present.

## Goal
Bootstrap WooCommerce settlement evidence into existing canonical operational Orders without re-importing or mutating Order core, addresses, items, logistics, inventory or KOL data.

## Frozen truth contract
- WooCommerce is re-fetched read-only because historical ERP Orders do not persist the full settlement payload.
- Existing `WooCommerceOrderNormalizer` remains the sole settlement normalizer and reconciliation authority.
- Canonical resolution is exact only: provider=`woocommerce`, same `connection_key`, exact provider reference.
- Only `order_kind=customer`; any `historical_logistics` Order is excluded.
- No fuzzy resolution and no line-revenue reconstruction of settlement truth.
- Missing source fields remain null; explicit zero remains zero; refund evidence remains separate from provider total.
- `provider_order_total` is authoritative provider settlement evidence; reconciliation status comes only from the existing normalizer.

## Write policy
- No financial row: candidate `create` with `revenue_provenance=provider_import`.
- Existing `provider_import`: identical evidence=`noop`; changed evidence=`update`.
- `confirmed_manual`: protected / blocked.
- Null/legacy provenance with any existing revenue facts: protected / blocked.
- Other unknown provenance: blocked.
- Currency change clears frozen FX fields.
- Successful apply recomputes Finance completeness with `preserveReconciled=false` and writes an audit record.
- No payment fee, FX rate or cost guessing.

## Operator contract
- `finance:bootstrap-woo-financials` is dry-run by default.
- Scope is bounded by exact `--order` or inclusive `--from`/`--to`, with `--limit` capped at 10000 and Woo page size 100.
- `--apply` is explicit.
- Production `--apply` is fail-closed unless `ERP_ACCOUNTING_FINANCIAL_BOOTSTRAP_PRODUCTION_APPLY_ENABLED=true` is explicitly enabled under a separate Owner Production backfill gate.
- No scheduler is added in V1.1B.

## Gates
- No UI changes; no visual gate.
- Local/test apply only during implementation verification.
- Production Woo reads may be used for bounded reconciliation/dry-run.
- Production financial backfill writes remain NOT AUTHORIZED in this Work Package checkpoint.
- No migration is expected or authorized.

## Required verification
- exact ref + connection-key resolution, unresolved/blocked isolation;
- create/update/noop idempotency;
- confirmed-manual and legacy-unclassified protection;
- Woo refund/reconciliation and missing-field semantics unchanged;
- currency-change FX clearing;
- non-customer/historical exclusion;
- dry-run has zero Finance mutations;
- Production apply guard fail-closed;
- Finance/Order Profit regressions and relevant Architecture boundary rules remain green or documented as pre-existing baseline drift.
## Validation evidence — 2026-09-07
- Woo source feasibility: Production read-only sample fetch returned complete settlement evidence and `reconciliation_status=matched`; no Finance writes were performed.
- Focused V1.1B: `7 tests / 46 assertions` PASS.
- Finance + Woo inbound/automatic sync + Order saved-view regression: `51 tests / 487 assertions` PASS.
- `npm run typecheck`: PASS. `npm run build`: PASS. PHP lint: PASS. `git diff --check`: PASS.
- Architecture comparison: branch and clean `main@e40db0d` both report the same pre-existing 3 frozen-baseline failures (`8 tests / 129 assertions`); no additional V1.1B Architecture failure.
- Finance Application no longer reaches Orders Infrastructure directly: exact operational order lookup is behind Finance `Application/Contracts/WooFinancialBootstrapOrderStore` with an Infrastructure adapter that re-validates exact provider/connection/reference and historical exclusion.
- Production baseline observed before V1.1B release: 6068 operational customer Orders, 11 existing `order_financials`, 0 V1.1B bootstrap audit writes.
- Full Production V1.1B dry-run is deferred until code-only Production release because security policy blocks loading un-deployed worktree code against Production runtime/env.

## Next gate
Merge code only. Then request an explicit V1.1B Production code-only Release Gate. After deployment, run `finance:bootstrap-woo-financials` in default dry-run mode over the bounded historical range. Production `--apply` remains a separate Owner authorization.
