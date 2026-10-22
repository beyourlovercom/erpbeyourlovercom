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
