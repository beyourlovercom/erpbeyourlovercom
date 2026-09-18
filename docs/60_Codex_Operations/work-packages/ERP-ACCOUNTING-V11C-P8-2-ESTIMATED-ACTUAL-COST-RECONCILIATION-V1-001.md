# ERP-ACCOUNTING-V11C-P8-2-ESTIMATED-ACTUAL-COST-RECONCILIATION-V1-001

**Status:** CLOSED / PRODUCTION ACTIVE / HISTORICAL BACKFILL COMPLETE / OWNER UAT PASS
**Branch:** `feature/erp-accounting-v11c-p8-2-estimated-actual-reconciliation-v1-001`  
**P8.1 Frozen:** Multi-shipment cost aggregation remains unchanged.  
**Production Continuous Delta:** Active through `ERP_ACCOUNTING_P82_ESTIMATED_FALLBACK_ENABLED=true`; automatic creation is maturity-guarded to orders aged 0–30 days.

---

## Rules

### Last-Mile Projection Priority (Read-Only)

1. **Actual strong fact** — existing `order_cost_entries` with `provenance IN (actual, confirmed_manual, provider_import, allocated_actual)` for `cost_type = last_mile_cost`; OR live carrier billing evidence.
2. **Existing eligible estimate** — `LastMileEstimateEvidenceReader` output from routing tables (CNY, converted when needed).
3. **Historical comparable actual median** — 90-day window; dimensions: warehouse slug + carrier + destination country + order currency. Min 1 sample required. Confidence: high (≥10), moderate (≥3), low (<3).
4. **Overseas-warehouse low-confidence fallback** — USD 10 × shipped_units. China-direct warehouses (`factory`, `007`) MUST NEVER use this fallback.

### Product Cost (unchanged from P8.1)
Confirmed source first; standard purchase OR production+packaging reference; otherwise blocked. No universal guessed dollar value.

### Warehouse Cost (unchanged)
Actual allocation or defensible same-warehouse historical actual median; otherwise missing.

### Optional Costs (refund_replacement_loss, other_variable_cost)
Absent = provisional zero for **estimated profit projection only**. Never persist confirmed zero to DB.

### Profit / Maturity

| Age (days) | `maturity_state` | Estimate allowed | Reconciliation due |
|---|---|---|---|
| 0–7 | `fresh_estimate_allowed` | ✓ | ✗ |
| 8–30 | `estimate_allowed` | ✓ | ✗ |
| 31–45 | `actual_reconciliation_due` | ✗ | ✓ |
| >45 | `actual_cost_overdue` | ✗ | ✓ |

- Missing `order_date` → `age_days = null`, `maturity_state = null`. Never guessed.
- **Final Profit** (`canonical_contribution_profit`) requires ALL costs confirmed (no estimated_reference). Strict.
- **Estimated Profit** (`canonical_estimated_profit`) — projected from confirmed + estimated facts; optional absent costs provisionally zero. Read-only.

### Actual Replaces Estimate — Audit

When `CostMaterializationService.writeCandidate()` replaces an `estimated_reference` entry with a strong provenance (actual/provider_import/confirmed_manual/allocated_actual), it emits an audit record with:
- `action: finance.estimate_to_actual_restatement`
- `before`: estimated amount/currency/source_reference/provenance
- `after`: actual amount/currency/source_reference/provenance + variance (if same currency)
- `reason: finance.p8_2_estimate_to_actual_restatement`

Estimate can never replace a confirmed/actual fact (existing `disposition()` logic preserved).

---

## Safety

- **Implementation-stage boundary (historical):** initial code/test work performed no Production writes or env mutation.
- Production activation/backfill later proceeded only through explicit guarded gates.
- Continuous Delta now uses the existing `last_mile_estimate` stage with the P8.2 feature flag; no second scheduler exists.
- Automatic P8.2 fallback is limited to `estimate_allowed=true` (age <=30d). Orders aged 31+d are reconciliation/actual-overdue work and cannot receive a new automatic P8.2 estimate.
- The dedicated guarded P8.2 materialization command remains available for explicit historical governance.
- **P8.1 multi-shipment remains frozen.**

---

## Roadmap

| Stage | Description | Status |
|---|---|---|
| P8.2-0 | `OrderMaturityService` — pure age/maturity computation | ✅ Done |
| P8.2-1 | `LastMileHistoricalProjectionService` — 4-tier read-only projection | ✅ Done |
| P8.2-2 | `EstimatedProfitProjectionService` — read-only estimated profit projection | ✅ Done |
| P8.2-3 | `OrderProfitExplanationService` — add maturity + estimated profit fields | ✅ Done |
| P8.2-4 | `CostMaterializationService` — estimate→actual restatement audit trail | ✅ Done |
| P8.2-5 | `finance:p8-2-estimated-reconciliation` — dry-run command (read-only) | ✅ Done |
| P8.2-6 | `OrderProfitShow.vue` — KPI strip, overdue banners, 预估利润 card | ✅ Done |
| P8.2-7 | Continuous Delta integration with maturity guard | ✅ Done |

---

## UAT Acceptance Criteria

1. **Maturity states** — Orders at 0, 8, 31, 46 days show correct `maturity_state` in explain output.
2. **Estimated profit** — Order with mix of confirmed + estimated costs shows `canonical_estimated_profit` (not null); `canonical_final_ready = false`.
3. **Final profit strict** — Order with ALL confirmed costs shows `canonical_final_ready = true` and `canonical_contribution_profit`.
4. **30d+ amber banner** — Order >30d with estimated costs shows "30+ 天订单 · 待真实成本结算" banner in UI.
5. **45d+ red banner** — Order >45d shows overdue warning.
6. **Profit card label** — Shows `预估利润` (not `最终利润`) when any cost is estimated; `最终利润` only when final_ready.
7. **Overseas fallback** — Order with overseas warehouse and no billing/estimate shows `fallback_usd10_per_unit` tier, amount = USD 10 × units.
8. **China-direct no fallback** — Order with factory/007 warehouse shows `no_projection_china_direct` tier, amount = null.
9. **Audit trail** — When carrier billing replaces an estimated_reference last-mile cost, an audit record with `action = finance.estimate_to_actual_restatement` is created, showing variance.
10. **Estimate cannot replace actual** — No estimated cost entry can overwrite a confirmed/actual fact.
11. **Dry-run command** — `finance:p8-2-estimated-reconciliation --order X` outputs valid JSON, writes nothing to DB.
12. **P8.1 regression** — All multi-shipment cost aggregation tests pass.

## Actual Validation Evidence (Fixture Candidates)

- Order with `order_date = today` → `maturity_state = fresh_estimate_allowed`
- Order with `order_date = today - 35d` → `maturity_state = actual_reconciliation_due`, `reconciliation_due = true`
- Order with overseas warehouse + 3 items + no billing → last-mile projection = USD 30.00 (USD 10 × 3)
- Order with factory warehouse → last-mile projection = null (china_direct_no_fallback_allowed)
- Order with all confirmed costs + revenue → `canonical_final_ready = true`
- Order with one estimated cost + revenue → `canonical_estimated_ready = true`, `canonical_final_ready = false`


## Production closure — 2026-09-18
- Base P8.2 implementation merged in PR #352; activation bridge merged in PR #356 and was first deployed inert with the persistent P8.2 flag disabled.
- Production Estimated canary Order `5121` materialized `USD 12.14` as `estimated_reference / estimated`, tier `historical_comparable_median`, confidence `high`, sample count `113`; replay became noop and duplicate groups stayed zero.
- `ERP_ACCOUNTING_P82_ESTIMATED_FALLBACK_ENABLED=true` was then activated while all Production apply flags remained unset. Continuous Delta dry-run at activation had `candidate_count=0`, so no historical flood occurred.
- Historical backfill proceeded through exact per-order `plan_sha` gates. Final P8.2 population is **739** materialized estimates with **739** matching `finance.p8_2_last_mile_estimate_materialization.applied` audit records and **0** duplicate `(order_id, cost_type)` groups.
- Full post-backfill dry-run converged to `apply=0 / noop=739`; existing Actual, China-direct and legacy-estimate blockers remained fail-closed.
- Visual UAT V2 merged in PR #358. Owner UAT PASS on Production Order `6996` (normal Estimated Profit state) with `5063` used as the 45+ day overdue contrast. Estimated is page-level amber and never labelled Final Profit; overdue semantics remain visually distinct.
- Final Closure audit identified a maturity gap in automatic P8.2 fallback. PR #362 (`e4cd3feba6e6aeb04298205185ede12a79de1ec1`) added the canonical `OrderMaturityService` guard to the automatic path while preserving the dedicated historical gate.
- PR #362 validation: P8.2 focused **26 tests / 117 assertions PASS**; Finance full **135 tests / 966 assertions PASS / 1 MySQL-only skip**.
- Production age-guard verification after deploy: Order `7175` at age 0 passed the maturity gate into normal projection (`estimate_allowed=true`); Order `5754` at age 31 was blocked with `p82_automatic_estimate_age_blocked` and maturity `actual_reconciliation_due`. Boundary verification confirms 10/30d allowed, 31d reconciliation-due/automatic-blocked, and 46d actual-cost-overdue/automatic-blocked.
- A live Estimate→Actual replacement canary remains unavailable because no persisted P8.2 Estimate currently has a newly arrived matching real carrier bill. No fake Actual evidence was created. The first genuine match must be observed post-closure and must emit `finance.estimate_to_actual_restatement`.
- Overall 30+d Profit Coverage is still constrained by other required cost chains, especially Product Cost / First Mile / Handling completeness; P8.2 does not claim to close those unrelated gaps.
- ERP Changelog intentionally remains unchanged because publication is manual-only.
