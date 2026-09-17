# ERP-ACCOUNTING-V11C-P8-2-ESTIMATED-ACTUAL-COST-RECONCILIATION-V1-001

**Status:** UAT-Ready  
**Branch:** `feature/erp-accounting-v11c-p8-2-estimated-actual-reconciliation-v1-001`  
**P8.1 Frozen:** Multi-shipment cost aggregation remains unchanged.  
**Production Continuous Delta:** Not touched. P8.2 fallbacks are read-only projection only.

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

- **Worktree only.** No Production DB writes, no .env mutations, no changelog updates.
- **P8.2 fallbacks are read-only projection/explainability** until separate post-UAT activation.
- **NOT routed into `AccountingContinuousDeltaService`** auto-apply. The delta service remains unchanged.
- **P8.1 multi-shipment frozen.** All P8.1 tests pass.

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
| P8.2-7 | Post-UAT: route `LastMileHistoricalProjectionService` into ContinuousDelta | ⏳ Post-UAT |

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
