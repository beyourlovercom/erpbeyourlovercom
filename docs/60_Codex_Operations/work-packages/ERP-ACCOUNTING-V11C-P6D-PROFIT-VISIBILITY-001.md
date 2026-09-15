# ERP-ACCOUNTING-V11C-P6D-PROFIT-VISIBILITY-001

## Scope
Expose the existing Order Profit V1 calculation transparently in the customer Order detail page and Finance Order Profit list.

## Frozen UX
- Customer Order detail gets an `订单利润` section matching Owner-approved render.
- Show net revenue, known posted cost, current-stage profit/margin, final confirmed profit when eligible.
- Show all ten cost categories with amount, currency, status, provenance/source reference.
- Show deterministic completeness count and missing cost facts.
- Show the exact profit formula in Chinese.
- Finance `/accounting/order-costs` distinguishes current-stage profit from final confirmed profit and exposes completeness progress.
- Order number remains clickable into the customer Order detail.

## Accounting semantics
- Do not change P6A/P6B/P6C materialization rules.
- Do not promote incomplete stage profit to confirmed profit.
- `current_stage_profit` is an informational projection: confirmed net revenue minus all currently posted cost entries.
- Existing `contribution_profit` remains the only final profit and remains null until Finance completeness allows it.
- Missing facts are never silently zero-filled.
- Existing currencies/provenance/source references remain authoritative.

## Out of scope
- No schema or migration.
- No new scheduler or Production data-write command.
- No P7/P8/P9 cost materialization.
- No historical fulfillment reconciliation.
- No change to FX or Order Profit confirmation rules.

## Validation
- Focused Finance Order Profit tests.
- Customer Order detail Inertia projection test.
- Frontend build/type/test coverage for affected Finance/Orders pages.
- Architecture regression delta must be zero.

## Delivery
- One branch / one PR.
- Production code-only deploy after merge under existing Owner continuous authorization until UAT.
- No Production business-data writes in P6D.

## Owner UAT
Owner must be able to answer from one Order page:
1. What is the current-stage profit?
2. What exact formula produced it?
3. Which costs are posted, estimated, not applicable, or still missing?
4. Is final profit confirmed yet, and why/why not?

## Final evidence
- Baseline: `origin/main@bf128e6c144a62a214fcfd46ba98ae1ec0455799`.
- PHP lint: PASS for OrderAnalysisService / OrderController / focused test.
- `git diff --check`: PASS.
- Vite Production build: PASS, 933 modules transformed.
- Focused profit visibility: 3 tests / 83 assertions PASS.
- Existing ProfitDashboard: 5 tests / 122 assertions PASS.
- P6 last-mile + continuous-delta regression: business tests PASS; 1 MySQL-only skip.
- Full Mvp1+OrderProfit set: only inherited shipment audit assertion fails; exact-main reproduces the identical failure.
- Architecture: branch 3 frozen-baseline failures; exact-main has the identical 3 failures; regression delta=0.

## Work Log
- UX contract + source inspection: 0.25h
- backend visibility projection + Order integration: 0.45h
- Orders / Finance frontend implementation: 0.60h
- focused tests + build + baseline comparison: 0.45h
- effective engineering time: 1.75h
