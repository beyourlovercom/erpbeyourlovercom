# S4 Integration UAT — BYL B2B Sales, Trade Order & Finance

**Date:** 2026-10-22
**State:** technical isolated test suite **PASS**; real authenticated browser + physical M14 + Finance Owner + host CI **HOLD**. This is not Production UAT/Go-Live PASS.
**Source:** branch `feature/b2b-trade-finance-g4-20261022` SHA `34b05ad00624b5d89e80458a49251a3c67a1d87f`, stacked Draft PR #481 → #480 → #479 → #478 → #477 → #476 → #473. Issue #475 / inherited debts #474.

## Execution environment and source isolation
- The approved China Mac B2B UAT service was not modified. Formal OAuth listed `china-mac` READY but its execution endpoint was **PARITY_AGENT_UNREACHABLE** for this run; there was **no restart**.
- Recreated a completely separate local clone on the authorized **US Mac mini**: `/Users/a/AI/uat/b2b-sales-s4/erp`. This is not the existing `/Users/a/AI/007/erpbeyourlovercom` site or `/Users/a/AI/production/erp.beyourlover.com/current`, both untouched.
- Composer install with scripts disabled, Node `npm ci --ignore-scripts`; local PHP 8.3.33 and Chrome headless. No Production/Dev database, payment provider or customer record opened.
- `npm run typecheck`: **PASS**; `npm run build`: **PASS** (ordinary historical chunk-size warning).
- Focused test matrix: **106 PHPUnit tests, 1,059 assertions PASS**, combining previously passing 102/985 baseline with 4 new Laravel role-switching HTTP tests/74 assertions.
- Fresh **synthetic SQLite only** `migrate:fresh`, last G4 rollback, G4 reapply: PASS. Zero rows in `finance_b2b_trade_cash_events`, `b2b_trade_orders`, legacy `orders`, `order_financials`, `customers` after schema test.
- The remaining historical `ModuleArchitectureTest` exact-source paths and `ApprovedMenuBaselineTest` drifts are tracked separately in #474; the focused S4 suite does not claim the full repository CI is green.
- GitHub PR #481 is **Draft**, host reports no checks on branch in its current state. This is **not CI PASS** and does not resolve the prior hosted Actions billing/budget restriction.

## S4 test acceptance record (synthetic records, HTTP + isolated DB)

| Step | Actor/identity | Evidence | Result |
| --- | --- | --- | --- |
| 1 | B2B seller | B2B organization, won Opportunity, USD 2,999.00 versioned Quote; POST trade draft | PASS |
| 2 | Other seller + consumer | Direct route attempt to trade order must not reveal owner data or allow post | PASS (404/403) |
| 3 | B2B manager (second actor) | Approved only Won trade draft; seller cannot self-approve | PASS |
| 4 | Seller | POST bank evidence, USD 2,999.00; cash summary remains **unpaid** | PASS |
| 5 | Independent document reviewer | Review submitted evidence; cash still **unpaid**; seller self-review refused | PASS |
| 6 | Finance officer | Requires `accounting.view` + `finance.b2b_cash_confirm`, independent manual bank statement attestation + same source/ref/amount | PASS |
| 7 | Accounting summary | Finance receipt USD 2,999.00 → **paid**, remaining USD 0; Woo `orders`/`order_financials` unchanged | PASS |
| 8 | Second Finance reviewer | Independent refund USD 99 → **partial**, net USD 2,900, outstanding USD 99 | PASS |
| 9 | Cross-order attacker | Cannot attach receipt evidence or refund from Order A to Order B | PASS (404) |
| 10 | Inactive Finance user + anonymous | Denied protected Accounting pages/post requests and redirected to login | PASS |
| 11 | Negative prerequisites | Unreviewed evidence cannot be Finance-confirmed or counted as cash | PASS |
| 12 | Zero C-end mixing | No synthetic C-end `customers`, `orders`, `order_financials` rows after normal B2B flow | PASS |

**Precision:** USD 2,999.00 quoted → approved → confirmed USD 2,999.00; USD 99.00 refund → confirmed net USD 2,900.00, outstanding USD 99.00. All internal amounts use integer `micros`, not floating-point posted money. These are **test fixtures only**, not actual wholesale customer orders.

## Real Chrome visual matrix (synthetic Vue props — not authenticated backend)
US Mac mini actual Chrome/Vite renders of **six Vue components** at PC 1280×800 and responsive M14-class 390×844:
- B2B Sales Pipeline Index and Show
- B2B Trade Order Index and Show
- Finance B2B Trade Cash Index and Show

**12/12 screenshots/checks PASS**, zero browser page errors and zero document-wide horizontal overflow. The screenshots live in a private isolated local test folder `/Users/a/AI/uat/b2b-sales-s4/screenshots`, not in GitHub or Production; **do not claim these are actual physical iPhone 14 screenshots**. Source test fixture was temporary and is not committed to the code branch.

## Outstanding Owner/Production gates — NOT PASS
1. **Real sign-in browser session** across distinct B2B seller, Sales manager, evidence reviewer, authorized Finance users on an isolated Dev deployment with test-only records. `actingAs` PHPUnit identities are not real login sessions. Do not add a test backdoor, seed production users or reuse real payments for this purpose.
2. **Owner visual review on real computer and physical M14** (375×812). Current responsive 390×844 Chromium is useful preliminary coverage but not the Owner physical M14 gate.
3. **Finance Owner policy and reconciliation:** manual statement confirmation authority, signed external bank/provider ownership, exact currency/FX, chargebacks, refunds, tax/P&L aggregation, retention, permission scope, provider callback and idempotency. No automatic bank API verification or real settled revenue claimed.
4. **GitHub CI, exact-head gate and legacy baseline:** full repo CI restrictions, menu/Architecture inherited drifts #474; no bypassing broken checks or masking unrelated tests.
5. **Release:** requires explicit exact-head authorization for each contemplated merge/deploy. No GitHub main merge, migrations on real DB, Dev/Prod deploy, actual Woo Orders, payment, finance posting, mail, user creation or READY device restart performed.

**Gate decision:** S4 isolated code/HTTP/synthetic-Vue validation PASS; real browser authentication + Owner M14 + Finance sign-off + hosted CI and release **HOLD**. Preserve the chain of Draft PRs; do not represent this work as a completed Production release.
