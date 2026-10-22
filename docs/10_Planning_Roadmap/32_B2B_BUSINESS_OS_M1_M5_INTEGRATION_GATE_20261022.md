---
project: BYL ERP
workstream: B2B Business OS / M1–M5
date: 2026-10-22
status: SOURCE-CANDIDATE / DEV-UAT-HOLD / CI-NOT-PASS
owner_direction: authorize M1 through M5 intermediate implementation
consolidated_pr: https://github.com/beyourlovercom/erpbeyourlovercom/pull/473
source_plan: https://github.com/beyourlovercom/erpbeyourlovercom/pull/467
parent_issue: https://github.com/beyourlovercom/erpbeyourlovercom/issues/466
---

# BYL B2B Business OS — M1–M5 engineering implementation and acceptance gate

## 0. Binding facts
- Owner approved separate level-1 `B2B 业务` and legacy `客户` (B2C) modules. No direct YBY customer database migrations; source-code/UX patterns may be adapted.
- M1/M2/M3/M4/M5 code authored in distinct feature branches and Draft PRs #468, #469, #470, #471, #472. These branches are stacked and some child mergeability calculations are affected by parent heads changed after branch cut. **Cumulative M1–M5 source is now available in Draft PR #473 against main**, which resolves need to manually cherry-pick intermediate state for review.
- Existing `app/Modules/Customers`, `Orders`, `KOL`, `Marketing`, `Logistics`, `Support` business files are **not modified by integrated PR #473**; only the shared Module Manifest, Shell navigation, permission catalogue/keys, UI navigation baseline and tests are changed.
- Only manual incoming B2B business Cases can be created through registered routes. Provider dedupe is an **internal, unexposed service** for future trusted source adapters. Email, website-form, official WhatsApp, CRM imports, quotes and sales-invoice issuance are not connected.

## 1. Delivery status — implementation vs verification
| Stage | Code candidate implemented | Evidence | Runtime acceptance |
|---|---|---|---|
| M1 Shell and RBAC | YES | `/b2b`, `b2b.*`, own provider and new first-level menu, B2B vs C-end test | **NOT VERIFIED** |
| M2 B2B Organizations / Contacts | YES | Separate `b2b_organizations`, `b2b_contacts`, list/create/details and owner scope | **NOT VERIFIED** |
| M3 Case inbox and source keys | YES | `b2b_cases`, `b2b_case_activities`, server pagination/filters, idempotency key | **NOT VERIFIED** |
| M4 Manual Case / follow-up | YES | operator case creation, notes, due date, lifecycle, manager-assignment and activity audit | **NOT VERIFIED** |
| M5 Isolation and replay tests | WRITTEN | `tests/Feature/B2B` suite, source contracts, assigned-contact security fix, source replay fixture | **NOT EXECUTED / NOT PASS** |

**Completed source-only inspection:** 11 new `app/Modules/B2B/**/*.php` files passed lightweight balanced-delimiter and namespace-dependency scanning. This **does not** establish PHP lint, unit tests, DB migration or runtime PASS.

## 2. Important permission corrections in M5
1. A salesperson reassigned an existing Case may read its linked B2B Organization.
2. That reassignment is not a blanket permission to edit the Organization or browse colleagues' unrelated Contacts. Only Contacts attached to Cases assigned to the salesperson are displayed for read-only Organization access.
3. Assigning a Case requires `b2b.assign` **and** `b2b.view_all`; the assignee must be active and have both `b2b.view` and `b2b.operate`.
4. `customers.view`, `customers.operate` do not grant B2B access. `b2b.view` does not grant Woo B2C Customers, newsletter subscribers or consumer customer exports.
5. Case-Activity and Case-parent FK deletion are `restrictOnDelete`, avoiding cascaded erasure of follow-up history. C-end Company and Customer tables remain unchanged.
6. Distributor-interest fields do not constitute automatic Authorized/Exclusive Distributor qualification or price entitlement.

## 3. CI and baseline constraints — NOT PASS
- New integrated PR #473 (and earlier PR #468) triggered GitHub Actions. Five unrelated/general ERP workflows returned `failure`; one inspected run (#39717106415) ended within seconds, with zero job steps, no initialized runner name, and job logs unavailable. The exact root cause was **not proven**; therefore do not label it a source test failure, a known quota failure or a CI PASS.
- **Pre-existing frozen ArchitectureTest drift verified on main** `60ccafc2848a9453cdfe018268517ca7ca7324fb`:
  - actual 709 module files, baseline lists 408: **301 inherited paths unpinned**;
  - actual 217 frontend files, baseline lists 161: **56 inherited paths unpinned**;
  - actual 132 migrations, baseline lists 94: **38 inherited paths unpinned**.
  - Total **395 existing unpinned paths** on main. This issue predates the B2B branches. No broad baseline rewrite/waiver has been silently approved.
- M1–M5 current package adds the specifically pinned new B2B migration/frontend/module files, while leaving the inherited drift as a distinct architecture-test remediation ticket.
- No `php -l`, Composer/PHPUnit, JS/TS/Vite build, Dev migration/rollback, database correctness UAT, Browser/PC/M14 screenshot UAT, Owner visual PASS or production runtime health check was executed successfully.

## 4. M5 execution commands once authorized environment is available
Use a **synthetic isolated local or Dev database**, with no real live user orders/customers, and approved repository checked out at the exact cumulative PR #473 HEAD.

```bash
php -v
composer validate --no-check-publish
php artisan route:list --path=b2b
php artisan migrate:status
php artisan migrate --pretend
php artisan test tests/Feature/B2B
php artisan test tests/Feature/AppShellNavigationTest.php tests/Feature/ApprovedMenuBaselineTest.php
npm ci
npm run build
```
Do **not** run schema migrations on Prod. For synthetic/local schema rehearsal use an isolated local DB, run `php artisan migrate`, run targeted tests, and then reset/destroy the isolated DB according to approved test practice; do not roll back shared Dev migrations that other branches may be using.

**Architecture note:** Legacy `tests/Architecture/ModuleArchitectureTest.php` is known to have pre-existing out-of-date exact-file-count checks; report this test independently, do not pass by applying an unreviewed wholesale whitelist. New `tests/Feature/B2B/B2BM5SourceContractTest.php` is a narrow, source-only complementary gate, not a substitute for full architecture regression.

## 5. Owner PC + M14 visual/functional UAT checklist
- [ ] B2C `客户` menu unchanged; `B2B 业务` is a distinct first-level menu on desktop and M14; second-level items `询盘管理`, `企业客户`, `跟进任务`.
- [ ] B2B user without C-end permission sees no C-end customer data; C-end-only user gets 403 for every B2B list/create/detail/write URL.
- [ ] Create a synthetic MY wholesale Organization/Contact and a 100-unit Naughty Clock inquiry; confirm it records interest but does not auto-grant $25.99 Authorized Distributor pricing.
- [ ] Create a separate synthetic PL B2B retailer lead; verify each owner sees their own Case only; unauthorized direct URL/POST/PATCH returns denied without side effect.
- [ ] Create follow-up note and date, change stage, close Won/Lost; status filters, overdue sorting and Case Activity chronology should persist/reload correctly.
- [ ] Manager assigns MY Case to another B2B operator. New owner can read assigned Case and its exact primary Contact, cannot read unrelated contact or write former owner's Company; old owner loses Case access when not `b2b.view_all`.
- [ ] View 0 Cases / empty states, long company name, pagination >30 cases, screen 390px wide and M14, keyboard focus, native select and overflow.
- [ ] C-end Customers, subscribers, Woo Order list and KOL sample views retain their previous totals and do not inherit B2B records.
- [ ] Admin/Owner can review screenshot UAT on actual desktop and M14 with representative **synthetic** data only.

## 6. Release decision
**HOLD FOR VERIFICATION.** The Owner granted intermediate development authority, but no code/main merge, Dev deployment, live database write or Production rollout occurred in this work. Do not set `M5 PASS` until target tests, schema rehearsal and actual PC/M14 Owner UAT pass. After acceptance, issue a fresh exact-head readback and authorize deploy under existing project governance.

## 7. Next bounded moves
1. Diagnose GitHub Actions run failures and runner eligibility; unblock CI with least privileges, without paid usage changes or restarting READY devices.
2. Run B2B-focused and core-regression tests and correct failing new code; separately track historic ArchitectureTest drift.
3. Dev-only deploy with isolated/migration-safe rehearsals, then PC + M14 Owner UAT.
4. Convert Draft #473 into the single final merge candidate after CI and UAT. Do not merge intermediate stacked PRs plus #473 as duplicates.


## 8. Actual isolated China Mac verification — 2026-10-22 (supersedes earlier unverified gate status where noted)

Execution provider: **formal self-hosted OAuth MCP** `china-mac` (READY), isolated checkout at `/Users/a/.cache/aicc-b2b-m5-uat/erp`, exact tested source base after syntax/navigation fix `87756e059b20b684fab4bf11fcb822b062047a93`. This directory is neither a Production ERP checkout nor a shared Dev site. The source was cloned with `git clone --depth 1`; no server/website deploy, no user data access or device restart.

| Verification | Measured result | Limit |
|---|---|---|
| PHP CLI | 8.3.32 | China Mac local |
| Composer | `composer install --no-scripts` exited 0, 137 packages | Isolated vendor only |
| PHP syntax | **21/21 PASS** (11 B2B PHP, three migrations, seven B2B test files), plus checked changed shared PHP files | Actual `php -l` |
| Frontend typecheck | `npm run typecheck` **exit 0** | Actual `vue-tsc --noEmit` |
| Frontend build | `npm run build` **exit 0** (964 modules transformed) | Chunk-size warning only |
| Isolated B2B PHPUnit | **28 tests / 169 assertions PASS** | SQLite `:memory:` |
| Combined B2B + Shell Navigation PHPUnit | **49 tests / 527 assertions PASS** | SQLite `:memory:` |
| B2C Customers + Marketing subscriber + selected Orders regressions | **94 tests, one failing**, 93 otherwise passed | Failure reproduced on clean `main`, not caused by B2B |
| Standalone main comparison | `CustomerMasterTest::test_customer_detail_aggregates_only_explicitly_linked_customer_orders` **same failure** on untouched `main`: recentOrders.0.number `null` vs `WEB-1001` | Pre-existing C-end failure |
| ApprovedMenuBaselineTest | clean `main`: **4 failures**; integrated candidate: **3 failures** | Legacy accounting-loss-orders URL/slug and marketing/accounting children drift, plus previously approved baseline metadata mismatch |
| SQLite migration `migrate:fresh` | **exit 0**, all 3 B2B migrations run | Isolated local file `browser-uat.sqlite`, no Production |
| SQLite `migrate:rollback --step=3` | **exit 0**, B2B migrations all rolled back | Isolated local file only |
| SQLite re-apply `migrate` | **exit 0**, all 3 B2B migrations applied again | Isolated local file only |
| `php artisan route:list --path=b2b` | **14 B2B routes registered** | Route registration, not visual browser check |
| Original GitHub Actions workflows | **failure, no job steps** | No runner-level proof of root cause; still separate blocker |
| True desktop/M14 screenshot/Owner visual UAT | **NOT EXECUTED / NOT PASS** | Do not infer from responsive CSS or compiled assets |

### Runtime defects found and fixed before tests passed
1. Source `app/Modules/ModuleManifest.php` mistakenly had `B2BServiceProvider::class;` instead of `,` and initially prevented Laravel boot. Fixed in cumulative PR by GitHub commit `d6f7d472aae3255626915b5c4b1c106a58261bb8`, then `php -l` and PHPUnit confirmed syntax resolution.
2. Existing Shell navigation expected 13 first-level modules; Owner-approved B2B addition requires 14. Fixed in `AppShellNavigationTest`.
3. Configured B2B progress (45) did not match versioned menu baseline (65); aligned to 65. The full AppShell+B2B suite then passed 49/49.
4. Vite build manifest was absent from the fresh isolated checkout, causing initial Inertia response 500. Actual local `npm run build` generated the assets; this was a test environment setup issue, not a Prod bug.

### Outstanding explicitly separated, not silently waived
- `ApprovedMenuBaselineTest` still has 3 failing assertions due to historical Accounting / Marketing menu drift. Clean main fails 4; **do not rewrite unrelated Owner-approved navigation data just to force green CI**. Resolve through separate bounded baseline correction with product owner approval and exact source comparison.
- The single CustomerMaster order-number failure is independently reproducible on clean main. Do not alter Consumer Customer domain in B2B PR without separate regression ownership.
- GitHub-hosted CI is still failing before observable job steps; China Mac local tests provide concrete isolated evidence but are **not** substitute for authorized GitHub workflow gate.
- A local synthetic browser-data seeding process could not proceed due platform execution safety inspection; no test account or live customer record was added. Actual PC/M14 screenshots and Owner UAT remain pending; do not claim visual PASS.
- New prospective rollout must be limited to Dev after its separate deployment gate, and main/Production changes remain unauthorized by this test record.

**Current classification:** M1–M5 source implemented, PHP/Vue build and focused runtime tests PASS; DB migration/rollback PASS on isolated SQLite; inherited full-suite contract and PC/M14 UAT remain **HOLD**.


## 9. China Mac frontend-only visual fixture and scoped CI gate — 2026-10-22

**Owner-independent progress, NOT final Owner visual UAT**. Formal self-hosted OAuth `china-mac` permitted execution in a local disposable clone; no real authenticated B2B user account, production API, mail, website, Dev host or customer data was accessed. A separate, never-committed Vite HTML fixture injected synthetic Inertia v3 `script[data-page="app"]` page props and mounted the **actual repo Vue components**. This neither bypasses Production auth nor demonstrates live backend behavior.

- Six view components: `B2B/Overview`, `B2B/Cases/Index`, `B2B/Companies/Index`, `B2B/Cases/Show`, `B2B/Cases/Create`, and Case follow-ups list.
- Headless local Google Chrome browser inspected each at **1280×800 desktop** and **390×844 responsive mobile**; **12 component/view viewport checks**. Full document width equalled viewport width in all checks, with expected table-only inner horizontal scroll on desktop. Components had headings and mounted without JavaScript page exceptions. An incidental favicon request 404 on first desktop overview was not an app exception.
- Mobile sidebar started aria-hidden=true, opening via the actual topbar mobile toggle changed it to false, B2B menu text became available, with no page error.
- First rendering inspection exposed a concrete mobile ergonomics limitation: original 920px inquiry table only exposed its first two columns on mobile. A separate **mobile card list** was implemented in the actual `B2B/Cases/Index.vue`, preserving desktop table unchanged and displaying Case ref, Company, title, country, stage, product, quantity, source, next follow-up on cards.
- After fix: at 390px the three synthetic cards were visible, desktop 1280px still showed the data table; in both widths there was no document-level horizontal overflow and browser page errors remained empty.
- Source test `B2BMenuContractTest` was added to compare B2B level 1 and three child entries against the frozen Owner-approved B2B subset and ensure existing C-end `customers` route and permission stay unchanged; this avoids weakening the inherited Accounting/Marketing baseline tests.
- **Actual China Mac PHPUnit after latest card fix and baseline pin:** 58 tests / 632 assertions **PASS** (`tests/Feature/B2B`, `AppShellNavigationTest`, `ModuleProviderTest`); `npm run typecheck` PASS; `npm run build` PASS, with only Vite chunk size advisory.
- Exact feature SHA tested: `d2223411e883053db5ccd3c6ee15ac3ddb0692da`. Local HTML browser fixture and preview screenshots reside only on the China Mac temporary test directory, not in GitHub or a customer-facing website.

### Root cause of GitHub Actions unstarted jobs proven via authenticated read-only GitHub CLI
At [run #39732785153](https://github.com/beyourlovercom/erpbeyourlovercom/actions/runs/39732785153) `gh run view` showed two jobs failing before assignment, each annotated:
```
The job was not started because recent account payments have failed
or your spending limit needs to be increased.
Please check the 'Billing & plans' section in your settings
```
Do not claim this proves an actual failed transaction: it is the GitHub-hosted billing/budget *restriction message*. The repo's GitHub API reported **zero registered self-hosted runners**; cannot simply change existing workflow label to `self-hosted`. No payment, budget change or new Runner registration authorized/executed. GitHub-hosted CI is **still HOLD**, even though local tests passed.

### Existing CI baseline debt isolated
The inherited menu/architecture/C-end test failures and hosted Actions limitation are tracked separately in [Issue #474](https://github.com/beyourlovercom/erpbeyourlovercom/issues/474). Do not bulk waive main's 395 missing exact-file pins or silently change shared Accounting and Marketing menus while performing B2B work.

### Current final gate
B2B feature and local mock viewport tests PASS. **Dev-authenticated browser/real M14 Owner UAT, full shared-app frozen baseline, GitHub Actions, exact-head merge and Production release: NOT PASS**. No merge, no deploy, no customer data changed.
