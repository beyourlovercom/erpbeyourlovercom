# ERP Accounting Completeness Semantics V1 — 2026-09-19

## Status

IMPLEMENTED / LOCAL VALIDATION PASS / PRODUCTION DEPLOY PENDING

## Problem

The Order Profit list inherited one strict completeness signal from `OrderAnalysisService`: canonical Final Profit requires all ten cost lines to be present and confirmed. This remains correct for Final Profit, but it made the list/KPIs read as if every non-final order were equally unusable.

P8.2 already froze a second, different read-only decision boundary for Estimated Profit:

Required costs (6):
- product_cost
- first_mile_cost
- overseas_handling_cost
- last_mile_cost
- payment_fee
- customs_tax

Optional costs (4):
- warehouse_cost
- kol_commission
- refund_replacement_loss
- other_variable_cost

Optional absence may be treated as provisional zero only inside the read-only Estimated Profit projection. It must never persist a confirmed zero.

## Frozen semantics

This package does NOT relax canonical Final Profit.

The list now exposes two separate states:

1. Final Profit completeness
   - unchanged strict ten-line Accounting contract;
   - existing `financials.completeness_status` remains authoritative;
   - no migration or historical rewrite.

2. P8.2 Estimated Profit base readiness
   - revenue evidence must be ready under the existing revenue contract;
   - all six P8.2 required cost lines must be present in accepted confirmed/estimated state and order currency;
   - the four P8.2 optional costs do not block this readiness layer;
   - no provisional zero is persisted.

The field is deliberately named `p82_estimated_profit_base_ready`: it describes base readiness only and does not claim that every dynamic projection candidate has been resolved.

## UI / KPI changes

Order Profit list:
- keeps strict Final Profit status;
- adds P8.2 required-cost coverage (`x/6`);
- shows `预估利润基础就绪` separately from `最终利润已确认`;
- table completeness cell shows both layers.

CSV export adds:
- P8.2 base-ready state;
- required-cost coverage;
- missing P8.2 required cost types.

No existing filters or confirmed-profit semantics are changed.

## Production read-only measurement

Using current Production data on 2026-09-19:

- operational customer Orders: 6,307
- revenue-ready Orders: 5,573
- P8.2 six-required-cost base-ready Orders: 33
- P8.2 base-ready / revenue-ready: 0.6%
- strict Final Profit confirmed Orders: 0
- strict Final / revenue-ready: 0%

Read-only MySQL query timing for the KPI set: ~81.1 ms total in the verification run.

These numbers are measurements, not data writes.

## Validation

- dedicated semantic regression: PASS, proving one Order can remain Final `INCOMPLETE` while P8.2 base readiness is true with exactly 6/6 required costs and all four optional costs absent;
- `ProfitDashboardTest`: 6 tests / 162 assertions PASS;
- `P82EstimatedActualReconciliationTest` + `AccountingContinuousDeltaTest`: 44 tests / 211 assertions PASS;
- Vite production build: 936 modules PASS;
- PHP syntax / `git diff --check`: PASS.

Existing PHPUnit warnings are environment/test-suite warnings; no test failure occurred.

## Safety

- no Production data write;
- no migration;
- no `completeness_status` rewrite;
- no cost-entry creation/update/delete;
- no P7/P8/P9 business-rule change;
- no Continuous Delta behavior change;
- no ERP Changelog update.

## Parallel-main deployment note

At delivery time GitHub main had advanced through PR #380 while Production runtime had not. This package may merge onto current main, but a Production UI deploy must not silently ship PR #380 compiled frontend without its matching runtime. Production deployment therefore requires a bounded overlay against the actual current Production baseline, unless PR #380 has separately reached Production first.
