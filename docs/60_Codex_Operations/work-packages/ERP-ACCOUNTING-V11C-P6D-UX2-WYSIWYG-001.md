# ERP-ACCOUNTING-V11C-P6D-UX2-WYSIWYG-001

## Objective
Close P6D Owner UAT gaps: profit visibility authorization and WYSIWYG finance-fact editing.

## Frozen scope
- Generic Order detail must never serialize/display profit facts.
- `analysis.view` is the canonical profit-visibility permission; current presets granting it are Finance, Operations, Administrator.
- Authorized users receive only a jump link from Order detail into `/accounting/order-costs`.
- Finance Order Profit drawer must show live net revenue, known cost, stage profit, stage margin, coverage, missing facts, and final-profit readiness as fields are edited.
- Saving keeps the drawer open and shows saved feedback.
- Existing backend confirmed/completeness/accounting semantics remain authoritative.

## Explicit non-scope
No schema, migration, FX, cost materialization, scheduler, P7/P8/P9 logic, historical reconciliation, or Production business-data writes.

## Governance
GitHub main sole source of truth. One WP / one branch / one PR. Owner-approved P6 chain continuous authorization remains active until Owner UAT. Production deploy is code/UI only.

## Acceptance
- orders.view-only user: no profit facts and no profit link
- analysis.view user: Finance jump link only on generic Order page
- `/accounting/order-costs`: remains permission protected
- drawer preview reacts before save; missing facts are not silently treated as zero
- save leaves drawer open and backend remains final authority
- frontend build + focused backend tests PASS or exact-main inherited delta=0

## Validation evidence
- Vite production build: PASS, 933 modules transformed.
- P6D UX2 focused: 3 tests / 74 assertions PASS.
- ProfitDashboard: 5 tests / 122 assertions PASS.
- UserManagement / role presets: 16 tests / 106 assertions PASS.
- `profitVisibility` absent from generic Order controller/page; only `canViewProfit=analysis.view` link remains.
- PHP lint + git diff --check PASS.

## Work log
GPT Web + US DC: scope freeze, implementation, tests, review and delivery in one bounded worktree. No Production data writes.
