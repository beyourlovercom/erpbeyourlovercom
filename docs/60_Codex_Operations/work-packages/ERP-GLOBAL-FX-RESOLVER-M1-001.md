# ERP-GLOBAL-FX-RESOLVER-M1-001

Status: OWNER UAT PASS / MERGE READY
Owner authorization: APPROVED (2026-09-08)

## Baseline
- Repository: `beyourlovercom/erpbeyourlovercom`
- Branch: `feature/erp-global-fx-resolver-m1-001`
- Worktree: `/Users/a/AI/007/worktrees/erp-global-fx-resolver-m1-001`
- Base: `origin/main@c96ae18e15b01aeae84a44de58626cbbed427d97`
- Execution provider: GPT_WEB

## Objective
Make Settings → FX the canonical resolver for **current management/reference conversion to CNY** across ERP callers, without changing historical Accounting FX snapshot semantics.

## In scope
- extend `FxReferenceSettingsService` as the canonical current-management conversion resolver;
- preserve per-currency Settings mode: automatic = ECB, manual = stored manual rate, CNY = fixed 1.0;
- preserve saved manual values while automatic mode is selected;
- migrate Customer Library monthly RMB estimate away from direct `EcbFxService`;
- migrate Logistics `FulfillmentPlanCostEstimator` away from direct `EcbFxService`;
- report Settings FX as the source while retaining ECB reference date/stale evidence when automatic rates participate;
- focused PHP/Vue tests and current-main reconciliation.

## Explicit exclusions / accounting boundary
- no writes to `OrderFinancial.fx_rate`, `fx_rate_date`, or `fx_rate_source`;
- no writes to `OrderCostEntry.exchange_rate*` fields;
- no historical order, posted cost, confirmed profit, reconciled profit, or P&L revaluation;
- transaction/posting FX snapshots remain authoritative for historical Accounting facts;
- Accounting base currency remains USD.

## Resolution policy
For current management conversion only:
1. `CNY` → fixed `1.0`;
2. currency in manual mode → persisted manual CNY rate;
3. currency in automatic mode or with no override → ECB reference rate;
4. ECB unavailability only blocks currencies that actually require automatic mode; a fully-manual conversion remains available;
5. response source = `SETTINGS_FX`, with per-conversion source = `manual | ecb | fixed`.

## Current business callers
- Customer Library `stats.rmb_estimate`;
- Logistics fulfillment-plan current cost estimation.

## Verification target
- Settings FX existing behavior remains green;
- manual/auto/fixed mixed global conversion test;
- auto mode ignores but preserves stored manual value;
- manual-only conversion succeeds without ECB;
- Customer Library uses manual Settings rate in integration test;
- Logistics estimator container wiring uses Settings FX resolver;
- Customer tooltip labels the canonical source truthfully;
- TypeScript + focused frontend test + build + PHP focused regression + diff check.

## Inherited repository gates
Architecture frozen-baseline drift and GitHub Actions billing/spending-limit failures observed on the preceding Settings FX lane are inherited repository/account conditions and are not expanded in this WP.

## Gates
- No new material visual design; only truthful FX-source copy in an existing tooltip.
- Owner UAT: PASS (2026-09-08).
- Owner verified Settings FX manual USD 6.78 → Customer Library current RMB estimate ¥678 end-to-end.
- Local UAT fixture order and temporary USD rate were removed after PASS.
- Production deploy remains a separate Owner Gate.
