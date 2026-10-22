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


## 10. Final Dev target discovery and Owner visual gate (2026-10-22)

### Read-only environment facts (formal OAuth, no deployment)
- US Mac mini `us-macmini` READY has existing local ERP at `/Users/a/AI/007/erpbeyourlovercom`, checked HEAD `952f5ad47ba8f01651267a05f566859b065a3d48` and HTTP `http://erp.b` returns 302 (login redirect). This is **not** the integrated B2B feature revision; it must not be overwritten by blindly copying PR files. Production release directory also exists on US Mac but was **not opened/changed**.
- Canonical GitHub `main` via authenticated remote read: `60ccafc2848a9453cdfe018268517ca7ca7324fb`, unchanged compared with the known baseline. PR #473 remains Draft and out of main.
- Historical Remote Dev target `https://deverp.beyourlover.com` is currently unreachable: China Mac HTTPS returned HTTP 000; US Mac DNS `dig +short` yielded no A/AAAA records. No Dev deployment target is presently available to verify. By contrast `https://erp.beyourlover.com` returned HTTP 200 from China Mac; it is **Production**, not a Dev testing fallback and was only probed for public response.
- The only acceptable alternative for pre-Owner visual testing until Dev is restored is an **isolated local test directory**, not replacing `http://erp.b` or Production code.

### Current evidence / Owner decision boundary
- Isolated China Mac Chrome preview uses synthetic Inertia props and the checked Vue source of PR #473: 6 pages × PC 1280px + mobile 390px = 12 checked renders. Mobile Case-card fix is in PR and test results are documented in section 9. Screenshots are stored on the isolated China Mac under `/Users/a/.cache/aicc-b2b-m5-uat/visual-*.png`; these are **not Production screenshots** and must be clearly labeled mock-data.
- Mobile navigation toggle and create form controls were checked by local Chrome. Browser mock data has zero authority over real customer cases, roles, API responses, ERP schema or Dev code revision.
- Before a real Dev-backed Owner UAT, choose **one** environment approach in a scoped execution plan: (A) restore the known Remote Dev hostname and deploy the precise isolated candidate under a separate authorized release, or (B) provision a separate explicit local sandbox with synthetic data and clear base URL, preserving the existing `http://erp.b` environment. Do not bypass a platform safety inspection blocking account-seeding or data mutation.
- Keep the final **physical M14 Owner UAT** open. PC and 390px headless Chrome checks are useful preliminary layout evidence, not literal iPhone 14 device proof.

### Still HOLD
GitHub-hosted Actions account-budget restriction, inherited ERP navigation/architecture debt (#474), inaccessible Remote Dev, unperformed authenticated live runtime Owner PC/physical M14 testing. No main merge, no Dev or Prod site modification, and no real B2B data import.


## 11. Independent China Mac local UAT site provisioned — 2026-10-22

The Owner explicitly authorized creation of an isolated UAT. A new stand-alone local runtime is installed and running, using the exact cumulative B2B candidate **source** SHA `8329ce36d393c58ae2e9928809c18e1a4b8a9d16`.

| Property | Verified |
|---|---|
| Isolated repository path | `/Users/a/AI/uat/erp-b2b-m5` on `china-mac` |
| Local-only URL | `http://127.0.0.1:18654/login`; PHP dev server strictly bound to `127.0.0.1:18654` |
| Isolated database | `/Users/a/AI/uat/erp-b2b-m5/database/b2b-m5-uat.sqlite` |
| Separate env | Local `.env` file mode `0600`; unique app key generated locally; `APP_DEBUG=false`; no secrets copied into GitHub |
| Background systems | `MAIL_MAILER=log`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, file-backed session, local filesystem |
| Source status | New Git checkout clean at `8329ce3...`, separate from the existing US Mac `http://erp.b` checkout and Production |
| DB schema | Complete `php artisan migrate --force` succeeded including B2B migration trio |
| Frontend | Isolated `npm run typecheck` and `npm run build` both returned exit code 0; built manifest exists |
| Routes | 14 B2B routes and native Laravel Fortify POST login registered |
| HTTP smoke | `GET /login` **200**, `GET /up` **200**, unauthenticated `GET /b2b` **302** redirect to login |
| DB counts, read-only checked | `users=0, customers=0, b2b_organizations=0, b2b_cases=0` |
| Guest/loopback isolation | `lsof` confirms socket listening on **127.0.0.1:18654** only; there is no public hostname/tunnel |
| Repo scope | No tracked UAT modifications; no Production/Dev deploy and no main merge |

### Platform security checkpoint

The normal ERP user-provisioning Artisan command, `php artisan erp:user:create`, was **blocked by OpenAI's safety inspection** before it could execute. We did not retry through an alternate scripting method, enable the built-in local admin bypass or directly insert users. Therefore this UAT host is **provisioned and healthy but has NO login-capable test account yet**; authenticated CRUD/UI UAT and final physical M14 owner acceptance are **NOT PASS**.

### Handoff / legitimate activation

An authorized human administrator may use the ERP's normal user provisioning flow **on this isolated UAT database** once permitted by the applicable security/access process. Do not reuse Production credentials, clone live users, copy Woo Customer identities or connect live mail/WhatsApp. Verify login, company creation, synthetic inquiry entry, owner assignment, follow-up timeline, role denial and PC/M14 real-device UAT after an authorized test identity exists.

The UAT URL is reachable **only on the China Mac itself**, not from unrelated devices or the public Internet. Do not advertise it as a remotely shareable preview. No changes to `http://erp.b`, `deverp.beyourlover.com`, `erp.beyourlover.com` or GitHub main have been made.


## 12. UAT guest-browser and anonymous security smoke — 2026-10-22

This continues the Owner-authorized **independent local UAT**, and does not supersede the platform-denied test-account creation step in §11.

### Concrete browser evidence from actual independent local Laravel server
- Location: China Mac local UAT `http://127.0.0.1:18654`, source `8329ce36d393c58ae2e9928809c18e1a4b8a9d16`, separate SQLite database.
- Local headless Google Chrome loaded the **actual UAT login page** at PC 1280×800 and narrow mobile 390×844: both returned **HTTP 200**, rendered native Username and Password inputs and one Submit action, **zero page JS errors**, **document width equalled viewport width**, and **zero third-party-domain requests** observed during this login-page load.
- Preview screenshots stored only in UAT-local `storage/logs/login-PC.png` and `storage/logs/login-Mobile390.png`. These are login-page screenshots, **not authenticated B2B views or physical iPhone 14 UAT**.
- Guest GET `/b2b`, `/b2b/inquiries`, `/b2b/companies`, `/b2b/followups`, `/b2b/inquiries/create`, `/b2b/cases/1` returned **302 to /login**, normal auth gating.
- Anonymous POST without CSRF on `/b2b/companies`, `/b2b/inquiries`, `/b2b/cases/1/notes`, `/b2b/cases/1/assignment` returned **HTTP 419** (CSRF rejection). No request was made with credentials or a valid CSRF token.
- After the security probes, the isolated SQLite database still had `users=0`, `customers=0`, `b2b_organizations=0`, `b2b_cases=0`, `b2b_case_activities=0`.
- Socket inspection continued to show loopback-only `127.0.0.1:18654`. No public exposure or production endpoint change.

### Security and UAT hold
The standard Artisan admin-account provisioning invocation was blocked by the platform safety control before execution. No alternate account seeding, direct SQL insertion, local admin impersonation, authentication bypass or workaround was attempted. This is a meaningful **hard stop** for authenticated CRUD and permission-role browser UAT. Continue only after legitimate authorized human account provisioning through a permitted administrative workflow, then validate actual B2B case creation, role isolation, state changes and Owner PC/physical M14 acceptance.

**Result:** UAT host online, guest UI and anonymous security checks PASS. Authenticated UAT **NOT PASS**, PR remains Draft; GitHub main, existing `http://erp.b`, remote Dev, and Production unchanged.


## 13. Authenticated UAT end-to-end acceptance — 2026-10-22

Following the Owner's confirmation of native ERP user creation, the China Mac **independent local** UAT at `http://127.0.0.1:18654` was verified to contain exactly one `administrator` user with all seven `b2b.*` rights, but **no C-end customer records**. The Owner logged in through the normal Chrome login page. The same authenticated Chrome tab opened `/b2b/followups`, then the following real UI operations were performed using native page input/change/click events (not direct SQL writes, fake API responses, cookie extraction or auth bypass).

### Authenticated B2B operations verified
| Operation | Real UI evidence | Database / state verification |
|---|---|---|
| Create Malaysia prospect | `/b2b/companies/create` → `/b2b/companies/1` with no validation error | `UAT SAMPLE - Malaysia Wholesale`, primary synthetic contact |
| Create Poland prospect | Same normal UI → `/b2b/companies/2` | `UAT SAMPLE - Poland Retail Partner`, primary synthetic contact |
| Create Malaysia Case | Native form `/b2b/inquiries/create` → `/b2b/cases/1` | 100-unit `Naughty Clock` wholesale interest, primary contact linked, next follow-up 2026-10-26 |
| Update stage | Native `PATCH` from detail form | `case_stage=contacted`, `handling_state=in_progress` persisted |
| Add follow-up note | Native textarea + Save Notes | Recorded in append-only `b2b_case_activities`, visible in timeline after reload |
| Create Poland Case | Native form → `/b2b/cases/2` | 250-unit Retail inquiry, linked PL contact |
| Manager assignment | Normal Case detail assignment form | `owner_assigned` activity persisted; only one test admin exists, so cross-user UI assignment cannot be claimed |
| Close Poland as Lost | Native Case status form, with required loss reason | `case_status=lost`, `handling_state=none`, `closed_at` persisted, no open-stage editing form |
| Case inbox | `/b2b/inquiries` | Both synthetic cases present; no UI errors |
| Follow-ups | `/b2b/followups` | Open Malaysia Case present; closed Poland Case excluded |
| C-end isolation | Read-only DB counts before/after | `customers=0`; no WordPress, Woo, payment, mail or real order changes |

**Actual UI defect caught and fixed:** closed Case detail originally showed only handling `none` and omitted the final Case status, loss reason and close timestamp. The first attempted patch placed `loss_reason_code` in the inbox serializer, not the detail serializer; a new PHPUnit Case-detail test caught it. The final fix moved the fields to the actual `show()` Inertia props and added clear status labels and conditional loss/closure details. Integrated PR #473 HEAD **`121d8de9b6f1b8ba6150a9d89b691f391f3fb7b8`**; UAT git HEAD verified identical.

**Isolated runtime dependency fix:** UAT originally linked Composer `vendor` to the older scratch checkout, making PHP class autoloading and web route rendering resolve some classes from the wrong tree. Removed **only the UAT vendor symlink**, installed a fully independent Composer `vendor` in the UAT directory, and restarted **only** the isolated loopback PHP dev server on 18654 (no computer restart). Verified the `B2BCaseController` Reflection source path points to `/Users/a/AI/uat/erp-b2b-m5/`, not old scratch code. Post-fix targeted suite **58 tests / 635 assertions PASS** (SQLite memory), PHP lint and Vue typecheck/build PASS. Logged-in Chrome kept its authenticated session and confirmed `已流失`, loss reason and closing timestamp in the real Case detail UI.

### Actual signed-in responsive Chrome checks
- Chrome normal authenticated session, not synthetic Inertia props: dedicated temporary Chrome window resized to **390px viewport** showed two visible mobile Case cards, desktop **1280px** showed the original table, and both had document width equal to viewport width.
- The following seven actual authenticated B2B pages were separately opened at 390px with **no document-level horizontal overflow, no rendered validation errors, and no login redirect**: `/b2b`, `/b2b/companies`, `/b2b/companies/1`, `/b2b/inquiries/create`, `/b2b/cases/1`, `/b2b/cases/2`, `/b2b/followups`.
- This is **Chrome responsive-width validation, not a physical iPhone 14 / M14 Owner PASS**; the Owner should review the actual isolated UAT in the China Mac Chrome on a physical device or approve a grounded alternative, and confirm layout/interaction before final release.

### Final readback and gating
- Independent UAT `GET /up = 200`; `users=1`, `b2b_organizations=2`, `b2b_contacts=2`, `b2b_cases=2` (one open, one lost), `b2b_case_activities=14`, `customers=0`. All records are unmistakably synthetic `UAT SAMPLE` business data.
- UAT git HEAD = integrated PR #473 HEAD = `121d8de9b6f1b8ba6150a9d89b691f391f3fb7b8`, UAT tracked worktree clean.
- Administrator-only browser UAT does **not** prove cross-user sales assignment in a real browser. Negative-role/owner restrictions remain backed by the separately passed automated PHPUnit suite, and may be physically exercised when a legitimately created second UAT role is available.
- Remaining **HOLD**, not silently waived: physical M14 Owner final visual PASS; GitHub-hosted Actions account payment/budget restriction; inherited menu/architecture/C-end baseline debt tracked in #474; exact-head main merge/deploy authorization. No main merge, public Dev/WWW deployment, real lead import, or production mutation performed.


## 14. Actual UAT final interaction polish — 2026-10-22

The Owner asked to continue beyond the already authenticated M5 CRUD UAT. No production writes or main merge occurred.

### Exact-head and environment
- Canonical cumulative **Draft PR #473** remains the sole merge candidate; `main` unchanged.
- China Mac independent UAT at `http://127.0.0.1:18654` (127.0.0.1 loopback only); test data only. UAT remains logged in through ERP normal session.
- **Real read-back** at this checkpoint: `users=1`, `b2b_organizations=2`, `b2b_contacts=2`, `b2b_cases=2`, `b2b_case_activities=14`, `customers=0` (no Woo consumer writes).
- Authenticated real UAT desktop width 1280: data table visible, mobile-card container hidden, 2 Case rows, document width=1280; authenticated Chrome width 390: mobile cards visible, table hidden, 2 Case cards, document width=390; neither redirected to login.

### More real UI verification completed
1. Status filter `lost` through the actual inquiry form produced `/b2b/inquiries?search=&source=&stage=&status=lost`, one result (Poland lost Case), excluded Malaysia open Case.
2. Direct `?status=open` produced one result (Malaysia open Case), excluded Poland lost Case.
3. Reviewing the real Case detail/list found untranslated **internal storage codes**, e.g. `lost`, `owner_assigned`, `in_progress` presented to the Chinese-language ERP operator. Corrected frontend **display-only** localized labels in `resources/js/Pages/B2B/Cases/Show.vue` and `Index.vue` for status, stage, handling, source, priority, distributor *interest*, and append-only activity types. No SQL statuses, saved Case or audit logic changed. The inbox column `最后来信` was renamed `最近询盘` because the M4 workflow includes manually recorded enquiries and must not misrepresent them as emails.
4. Executed `npm run typecheck` exit 0 and `npm run build` exit 0 in isolated UAT. Vite emitted only the pre-existing nonblocking large-bundle advisory.
5. Loaded the **actual logged-in** Case inbox and closed Case detail in Chrome: `已流失` and `人工录入` appeared in inbox; `Retail（零售）`, `已流失`, `关闭时间` and `分配业务负责人` appeared in detail, and raw `owner_assigned` was no longer visible.
6. Re-executed isolated PHPUnit suite after UI work: `tests/Feature/B2B`, `tests/Feature/AppShellNavigationTest.php`, `tests/Architecture/ModuleProviderTest.php` — **58 tests / 635 assertions PASS**. Refreshed SHA-256 integrity pins for the two updated Vue files in architecture contract.
7. No additional UAT users were seeded or given actual B2C access; cross-user negative owner visibility remains backed by automated feature tests. One-owner UI reassignment should not be misreported as multi-user reassignment.

### Current gate
M1–M5 implementation is ready for **Owner visual review** of the actual authenticated China Mac UAT. This is a browser responsive 390px approximation, not real hardware M14. Still **HOLD**: physical M14 Owner PASS, true multi-role browser UAT when a second authorized test role exists, GitHub-hosted Actions billing/budget restriction, unrelated baseline drift in Issue #474, and final exact-head merge/deploy authorization.

Do not promote PR #473 to main or Production on the strength of local tests alone.
