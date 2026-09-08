# ERP-ACCOUNTING-CONFIRMED-PRODUCT-COST-SOURCE-V11C-P1-001

## Objective
Establish an evidence-backed, time-aware confirmed Product Cost source without changing the existing `products.standard_*_cost` estimate/reference semantics.

## Frozen source facts
- Authoritative source: `成本货本007组 7月进销存.xlsx`.
- Owner confirmed every cost value in this workbook is CNY.
- Unit product cost uses `原成本单价` (legacy `成本价`); source long-code is retained as evidence identity.
- Exact ERP Product/SKU reconciliation is required. Ambiguous or unmatched rows fail closed.

## Scope
- New `product_cost_sources` evidence table and model.
- CSV dry-run/import contract with provenance, source key, source reference and effective date.
- Product Cost reader surfaces confirmed evidence to Finance materialization.
- Same-currency confirmed evidence may materialize as confirmed owner-approved cost.
- Cross-currency confirmed evidence is explicitly blocked pending the separate FX Snapshot bridge.
- Existing standard Product cost fallback remains `estimated_reference`.

## Safety gates
- Import is dry-run by default.
- `--apply` remains local/testing only in this WP.
- No Production migration or Product Cost data write without a separate Owner gate.
- Historical logistics remain excluded from Finance materialization.
- Multiple different confirmed costs for one ERP Product/effective period are blocked.

## Acceptance
- Existing Cost Materialization regression stays green.
- Confirmed same-currency source is idempotent.
- CNY source on USD/EUR/etc order returns `confirmed_product_cost_fx_conversion_required`, never mixes currencies.
- Production dry-run can validate canonical CSV without requiring the new table to exist.


## 2026-09-08 execution evidence
- Dedicated branch/worktree: `feature/erp-accounting-confirmed-product-cost-source-v11c-p1-001` / `/Users/a/AI/007/worktrees/erp-accounting-confirmed-product-cost-source-v11c-p1-001`.
- Initial base: `f7a184616038dbfbe325c83e5e13dd1a2354a819`; reconciled forward to `main@c96ae18e15b01aeae84a44de58626cbbed427d97` with zero overlap.
- Canonical READY input: 57 source rows across 55 ERP Products; input SHA-256 `6b7562d8fb75f82af52e38af901d68091287d5e563787acf65fd67136e2731a9`.
- Production read-only identity reconciliation: 55/55 ERP Product IDs present; 57/57 rows exact canonical SKU match; `IDENTITY_BLOCKED=0`.
- Conservative effective date: `2026-07-01`. July cost is not back-propagated into 2025 or earlier 2026 orders without historical effective evidence.
- Production read-only impact snapshot for orders on/after 2026-07-01: 1,058 operational customer orders / 1,570 items; 572 READY item rows; 485 orders with at least one READY product; 288 orders with every item Product-cost-ready. 5,035 earlier operational orders remain intentionally outside this July source period.
- Latest effective version wins by Product/order date; multiple different costs for one Product/effective period fail closed.
- `main@c96ae18` includes Settings FX M1, but its own WP freezes it as management/reference-only, not transaction-FX authority. P1 therefore continues to block CNY-to-order-currency materialization with `confirmed_product_cost_fx_conversion_required` pending the transaction/posting FX Snapshot bridge.

## Verification
- Confirmed Product Cost + existing Cost Materialization focused: 14 tests / 73 assertions PASS.
- Latest-main affected regression: Finance + Product Management/Order/SKU Alias, 110 tests / 534 assertions PASS.
- PHP syntax and `git diff --check`: PASS.
- Architecture suite remains at the repository's inherited 3 frozen-baseline failures / 8 tests / 129 assertions; no new failure category was introduced and the global baseline was not rewritten.

## Production state
- No `product_cost_sources` Production migration has been run.
- No confirmed Product Cost source row has been written to Production.
- No Product Cost has been materialized into `order_cost_entries` by this WP.
- Next gate after merge: explicit Owner authorization for Production backup + schema migration + 57-row confirmed source import. Cross-currency order-cost materialization remains separately gated by transaction FX readiness.
