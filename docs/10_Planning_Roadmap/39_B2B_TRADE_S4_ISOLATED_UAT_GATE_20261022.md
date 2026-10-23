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

## Owner-approved isolated private Dev-only deployment — 2026-10-22
The owner explicitly authorized isolated Dev-only UAT deployment in chat. We activated a NEW independent instance on US Mac mini within the already isolated S4 checkout. **This is NOT the historical `deverp.beyourlover.com` host**, which currently does not resolve; do not substitute Production.
- Dedicated isolated `database/b2b-s4-dev-only.sqlite`; standalone app `.env` with mode 0600, unique application key, standalone listening port **bound only to Tailscale IPv4** (never 0.0.0.0 or public WAN).
- `APP_ENV=local`, `APP_DEBUG=false`, `ERP_LOCAL_ADMIN_BYPASS=false`, `ERP_LOCAL_ADMIN_BYPASS_ENABLED=false`, `MAIL_MAILER=log`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `SESSION_DRIVER=file`, `WOO_PRODUCT_SYNC_ENABLED=false` and no provider/bank secrets. All test-only configuration remains local; the exact private endpoint is supplied to the Owner only within private conversation, **not committed to public GitHub**.
- Apply the PR #482 stacked migrations **only** to this disposable new SQLite; zero `users`, `orders`, `order_financials`, `b2b_trade_orders` or `finance_b2b_trade_cash_events`. Did not import identity, business or financial records from Production/Dev/current UAT.
- Real HTTP checks against live private Dev service: `/up` and `/login` **200**; `/b2b`, `/b2b/trade-orders` and `/accounting/b2b-trade-cash` anonymously **302 to /login**. Browser actual served login page at 1280x800 PC and 375x812 M14-equivalent Chrome with 2/2 PASS, zero page errors, no page horizontal overflow; screenshot files are kept private outside the Git checkout.
- From an **independent authorized Windows device on the same Tailscale**, private login endpoint returned HTTP 200. The service was **not exposed to the Internet**, nor bound to shared erp.b/WWW processes.
- GitHub main, existing US Mac ERP checkout `/Users/a/AI/007/erpbeyourlovercom`, original China Mac S4 UAT, existing live/Production databases, and READY device states unchanged.
- **Authenticated in-browser S4 business UAT is still HOLD**: this deliberately clean Dev SQLite has **zero users**, there is no public registration route, and there is no credentialless access. Initial trusted test admin must be provisioned via the repository's supported interactive `php artisan erp:user:create` procedure **by an authorized operator**, in this isolated directory only; then other test roles may be created through `/settings/users` with least privilege. Never seed production accounts, copy password hashes, enable admin bypass, use real bank settlements or invent login PASS. User passwords/temporary links must not be committed to GitHub or transmitted in chat.
- Physical owner-reviewed iPhone M14 and independent Finance-owner acceptance still remain gated, in addition to GitHub CI restrictions and original inherited architecture concerns #474.

**Deployment gate:** Private Dev-only service live and its anonymous authentication/health checks PASS; real authenticated role/browser/Owner UAT **HOLD** until legitimately created isolated test accounts exist. Do not promote this to live main/Stable/Production approval.

## Initial isolated test administrator ready — 2026-10-23
- Owner reported completing official interactive `erp:user:create` in the new isolated US Mac mini UAT.
- Read-only persisted verification: `users=1`, one active user and role binding `role_preset_administrator`, administrator permission mapping includes `system.permissions.manage`, `b2b.view`, `b2b.operate`, `b2b.view_all`, `b2b.quotes_manage`, `b2b.trade.approve`, `b2b.receipts.review`, `accounting.view`, `finance.b2b_cash_confirm`. This is a database/permission verification **only**, not a successful login assertion.
- `b2b_trade_orders`, receipt evidence, B2B Finance events, legacy Woo Orders and `order_financials` remain at zero. Private UAT `/login` returns HTTP 200, with protected B2B/Finance pages still redirecting anonymous visitors.
- Application routes for `/settings/users`, `/settings/users/create`, and per-user custom permission editing require `system.permissions.manage`, which this admin has. Initial test admin can therefore use the **official authenticated UI** to create subsequent least-privilege test identities.
- Permission gotcha: `RolePreset::SalesRepresentative` currently includes the legacy C-end sales permissions but no new `b2b.*` rights. `RolePreset::Finance` includes `accounting.view` but does not automatically include `finance.b2b_cash_confirm`. During UAT, select the appropriate role and explicitly set CUSTOM permissions in the user edit page. Do **not** silently broaden Production preset permissions.
- Suggested isolated UAT policy **only**: seller `b2b.view, b2b.operate, b2b.quotes_manage`; manager `b2b.view, b2b.view_all, b2b.trade.approve`; document reviewer `b2b.view, b2b.view_all, b2b.receipts.review`; Finance verifier #1 and #2 `accounting.view, finance.b2b_cash_confirm`. Apply official UI only using actual logged-in admin; usernames and passwords stay private. The second Finance person must not be the original receipt-confirming Finance actor.
- Next unfulfilled gate: human logs into the private UAT browser using the newly created valid admin password; **neither the assistant nor the automation knows or should retrieve the password**. No credentialless login, DB session fabrication, hash copying or role impersonation is permitted. Then use authorized browser UI for account creation, multi-role flows and Owner M14. No authenticated UI testing has been asserted yet.
