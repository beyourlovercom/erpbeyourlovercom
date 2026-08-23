---
type: work-log
project: BYL ERP
work_package_id: ERP-INQUIRY-M1.1-COMPANY-001
status: US IMPLEMENTATION + US VERIFICATION COMPLETE (INCLUDING BOUNDED RESTORE UAT FOLLOW-UP) / AWAITING CHINA VERIFICATION AND OWNER RE-UAT
baseline: main@7308f81af3660acff3bcec5d843dbe83cfbf14a6
branch: feature/erp-inquiry-m1-1-company-001
updated: 2026-08-23
---

# ERP-INQUIRY-M1.1-COMPANY-001 Work Log

## Activation

```text
M1.0_CONTRACT = OWNER APPROVED / FROZEN / MERGED
M1.0_ACCEPTANCE_SYNC_PR = #86 / MERGED
MAIN_BASELINE = 7308f81af3660acff3bcec5d843dbe83cfbf14a6
WORK_PACKAGE = ERP-INQUIRY-M1.1-COMPANY-001
DELIVERY_BRANCH = feature/erp-inquiry-m1-1-company-001
IMPLEMENTATION_PROVIDER = US MAC / CLAUDE CODE
TEST_PROVIDER = CHINA MAC / CODEX CLI
DESIGN_DIAGNOSIS_AUTHORITY = GPT WEB + OWNER
IMPLEMENTATION_AUTHORIZED = YES (Owner comment on PR #89, 2026-08-23: "CLAUDE_IMPLEMENTATION = AUTHORIZED")
MERGE_AUTHORIZED = NO
DEPLOYMENT_AUTHORIZED = NO
```

Authorization note: the local Work Package/Work Log documents (this file and
`docs/60_Codex_Operations/work-packages/ERP-INQUIRY-M1.1-COMPANY-001.md`) still read
"NOT AUTHORIZED" as committed. Per `AI/EXECUTION_ROLE_MODEL.md`, GitHub is the unique
source of truth; the Owner's explicit authorization comment was posted directly on
PR #89 rather than as a doc-updating commit. This log now reconciles that fact.

## Frozen implementation objective

Company master data only: `companies` schema, Customers module foundation, Company list/create/detail/edit/archive, `customers.view`/`customers.operate`, Audit integration, focused/full US validation, China exact-SHA verification and Owner UAT.

Explicitly excluded: Contact, Case, inbound messages, Inquiry Inbox, Email, WhatsApp, Dashboard inquiry metrics, Orders linkage, menu redesign and remote deployment.

## Pre-implementation evidence

```text
CUSTOMERS_MODULE_EXISTS_ON_BASELINE = NO
MODULE_MANIFEST_EXISTS = YES
CUSTOMERS_LEVEL1_MENU_EXISTS = YES
CUSTOMERS_LEVEL1_ROUTE = shell.customers
CUSTOMERS_LEVEL1_PERMISSION = customers.view
M1_1_ESTIMATE = 6–10h / planning 8h
```

## Implementation evidence

Delivered exactly the bounded M1.1 Company scope, mirroring existing repository
module conventions (`Orders`/`Audit` service/controller/route/provider shapes).

```text
app/Modules/Customers/Domain/WebsiteDomainNormalizer.php          (new)
app/Modules/Customers/Domain/CountryCodeNormalizer.php             (new)
app/Modules/Customers/Domain/Exceptions/InvalidCompanyAttributeException.php (new)
app/Modules/Customers/Application/CompanyService.php                (new)
app/Modules/Customers/Infrastructure/Models/Company.php             (new)
app/Modules/Customers/Experience/Controllers/CompanyController.php  (new)
app/Modules/Customers/Experience/Routes/web.php                     (new)
app/Modules/Customers/Providers/CustomersServiceProvider.php        (new)
app/Modules/ModuleManifest.php                                      (CustomersServiceProvider registered, appended)
database/migrations/2026_08_23_000000_create_companies_table.php    (new, frozen field/index contract only)
app/Modules/Identity/Application/AuthorizationKeys.php               (added CustomersOperate = 'customers.operate')
database/seeders/LocalUatSeeder.php                                   (added 'customers.operate' to local UAT permission catalog)
resources/js/Pages/Customers/Companies/{Index,Create,Edit,Show}.vue  (new)
resources/js/Pages/Customers/Companies/{Index,Create}.test.ts        (new)
tests/Feature/Customers/CompanyManagementTest.php                    (new)
tests/Unit/Customers/{WebsiteDomainNormalizerTest,CountryCodeNormalizerTest}.php (new)
tests/e2e/customers/company.spec.ts                                   (new)
playwright.config.ts                                                  (added 'customers/**/*.spec.ts' to testMatch)
tests/Architecture/ModuleArchitectureTest.php                         (bounded sync: new module files/dirs, Domain-layer
                                                                        assertion now lists the 3 Customers Domain files
                                                                        instead of asserting Domain is always empty, new
                                                                        route names, 'customers.operate' permission key,
                                                                        new migration hash, new frontend file hashes,
                                                                        CustomersServiceProvider appended to the manifest
                                                                        provider-order assertion)
tests/Architecture/ModuleProviderTest.php                             (CustomersServiceProvider appended, same bounded reason)
```

Design notes tying implementation to the frozen contract:

- No repository/contract interface layer was introduced in `Application`; `CompanyService`
  addresses the `Company` Eloquent model via a dynamic class-string
  (`'App\\Modules\\Customers\\Infrastructure\\Models\\Company'`), exactly mirroring
  `OrderService`'s existing pattern for staying clear of the `APP-001` Infrastructure-import
  architecture rule while keeping Application/Infrastructure a single straight-line module.
- `website_domain_normalized` is derived only via `filter_var(..., FILTER_VALIDATE_URL)` +
  `parse_url(..., PHP_URL_HOST)` -> lowercase -> strip one trailing dot. A scheme-less input
  (e.g. `example.com`) is treated as invalid rather than guessed/corrected, per Section 4.
- `country_code` normalization uppercases and enforces exactly two letters; no country-specific
  semantics added. `registration_number` trimming is handled by Laravel's default
  `TrimStrings`/`ConvertEmptyStringsToNull` web middleware (already active repository-wide); no
  bespoke trimming code was added.
- `status` is set to `'prospect'` on create and is never written by `update()` or `archive()`;
  no additional workflow/status values were invented. Archive only sets `archived_at`.
- `customers.operate` gates create/update/archive routes server-side via the existing
  `can:` middleware + `Gate::define` loop in `IdentityFortify`; `customers.view` (pre-existing)
  gates index/show. No `channels.manage` placeholder was introduced (deferred per Section 5).
- `/customers` (Shell placeholder) and `config/erp_shell_navigation.php` /
  `menu/APPROVED_MENU.json` were **not** touched. `/customers/companies/**` is reachable only
  by direct URL, matching Section 7 ("Owner UAT may enter M1.1 via `/customers/companies`").
- Audit: `company.created` / `company.updated` / `company.archived` are recorded through the
  existing `AuditRecorder` contract with actor id (when available), before/after field
  snapshots, and a reason code, mirroring `OrderService`'s existing Audit integration.

## Verification evidence

All commands were run from the repository root on the US implementation host.

```text
$ php artisan test --filter=Customers
{"tool":"phpunit","result":"passed","tests":18,"passed":18,"assertions":76,"duration_ms":383}

$ php artisan test --filter=ModuleArchitectureTest
{"tool":"phpunit","result":"passed","tests":8,"passed":8,"assertions":185,"duration_ms":76,"risky":1}

$ php artisan test --filter=ModuleProviderTest
{"tool":"phpunit","result":"passed","tests":8,"passed":8,"assertions":33,"duration_ms":5}

$ php artisan test --filter=AuthorizationFoundationTest
{"tool":"phpunit","result":"passed","tests":4,"passed":4,"assertions":34,"duration_ms":192}

$ php artisan test        # full suite
{"tool":"phpunit","result":"passed","tests":183,"passed":181,"assertions":2356,"duration_ms":4076,"skipped":2,"risky":1}

$ npm test                # full Vitest suite
Test Files  14 passed (14)
Tests  84 passed (84)

$ npm run typecheck       # vue-tsc --noEmit
(clean exit, no output)

$ npm run build           # vite build
✓ built in 377ms

$ npm run typecheck:e2e   # tsc --noEmit -p tsconfig.e2e.json
(clean exit, no output)

$ PLAYWRIGHT_BROWSERS_PATH=<external-versioned-cache>/1.62.1 npm run test:e2e
22 passed (10.2s)          # full repository Browser E2E: foundation, auth, authorization,
                            # shell, and the new customers/company.spec.ts (3 tests: full
                            # create->view->edit->archive->archived-filter flow, required-name
                            # validation, unauthorized-user 403) all in one run — this covers
                            # both "Company browser E2E on US local runtime" and "full
                            # repository Browser E2E" in a single execution.
E2E_CLEANUP=PASS

$ git diff --check
(clean exit, no output — no whitespace errors)

$ git status --short
 M app/Modules/Identity/Application/AuthorizationKeys.php
 M app/Modules/ModuleManifest.php
 M database/seeders/LocalUatSeeder.php
 M playwright.config.ts
 M tests/Architecture/ModuleArchitectureTest.php
 M tests/Architecture/ModuleProviderTest.php
?? app/Modules/Customers/
?? database/migrations/2026_08_23_000000_create_companies_table.php
?? resources/js/Pages/Customers/
?? tests/Feature/Customers/
?? tests/Unit/Customers/
?? tests/e2e/customers/
```

Pre-existing, out-of-scope facts observed (not diagnosed or fixed, per role boundary):

- `php artisan test` reports `skipped: 2` and `risky: 1` on this revision. The risky notice
  ("Test code or tested code did not remove its own exception handlers") is raised inside
  `tests/Architecture/ModuleArchitectureTest.php::test_all_rule_ids_have_real_assertions_and_compatibility_evidence`
  (line ~490), inside the pre-existing `routeNames()` helper that boots a full Laravel kernel
  from a plain `PHPUnit\Framework\TestCase`; this helper and its kernel-bootstrap call already
  existed before this Work Package and were not modified. One skipped test's name references
  "Illegal skip and reverse transitions are rejected," which belongs to the existing
  Sync/Logistics fulfillment-state test surface, not to Customers/Company. Both are reported
  as raw facts for GPT Web; no root-cause diagnosis or fix was attempted outside the M1.1
  Company package.

The Playwright browser binary (Chromium build for Playwright `1.62.1`) was not already present
on this implementation host in a repository-external, version-pinned cache. It was installed
via `PLAYWRIGHT_BROWSERS_PATH=<path>/1.62.1 npx playwright install chromium` into a disposable
scratch directory outside the repository (not committed, not part of any repository asset) so
that `npm run test:e2e` (Company browser E2E + full repository Browser E2E) could execute per
Section 11's requirement. No repository files or dependency manifests were changed to do this.

## Owner UAT

Not run by Claude Code (US implementation-host role boundary). Owner UAT on `http://erp.b`
per Section 13 of the Work Package covered the Company create/view/edit/archive lifecycle
and reported `EXISTING_ARCHIVE_OWNER_UAT = PASS`. The Owner then identified a missing
restore/取消归档 lifecycle operation and authorized it as a bounded UAT remediation via the
PR #89 comment `OWNER UAT FOLLOW-UP — COMPANY RESTORE / 取消归档 AUTHORIZED (2026-08-23)`,
posted at the then-current PR head `902cc4b153c7daff9bbac8e43d5c2f3046f26c0e`. Owner re-UAT
of restore specifically remains outstanding; this remediation does not claim Owner PASS for
restore.

## Restore / 取消归档 UAT follow-up remediation (bounded)

Implemented exactly the Owner-authorized restore scope from the PR #89 comment referenced
above, on the same branch/PR, no schema change:

```text
app/Modules/Customers/Application/CompanyService.php               (modified: added restore())
app/Modules/Customers/Experience/Controllers/CompanyController.php (modified: added restore())
app/Modules/Customers/Experience/Routes/web.php                    (modified: added restore route)
resources/js/Pages/Customers/Companies/Index.vue                   (modified: 取消归档 action per archived row)
resources/js/Pages/Customers/Companies/Show.vue                    (modified: 取消归档 action on archived detail)
resources/js/Pages/Customers/Companies/Index.test.ts               (modified: restore-button visibility coverage)
tests/Feature/Customers/CompanyManagementTest.php                  (modified: restore lifecycle + idempotency +
                                                                      unauthorized-restore coverage)
tests/e2e/customers/company.spec.ts                                 (modified: archive -> restore lifecycle,
                                                                      visibility-rule and state-transition coverage)
tests/Architecture/ModuleArchitectureTest.php                      (bounded sync: new route name
                                                                      'customers.companies.restore', updated
                                                                      frontend file hashes for the three modified
                                                                      resources/js files above)
```

Design notes:

- `CompanyService::restore()` mirrors the existing `archive()` shape exactly: lock-for-update,
  early-return with no mutation and no Audit call when the Company is already active
  (`archived_at === null`), otherwise clear `archived_at`, record before/after Audit evidence
  and return the fresh model. This satisfies the idempotency requirement without inventing a
  new pattern.
- Audit event `company.restored` uses the existing `AuditRecorder` contract with reason code
  `company_restored`, mirroring `company_archived`.
- `customers.operate` gates the new `POST /customers/companies/{company}/restore` route, same
  as create/update/archive; no new permission was introduced.
- UI: 取消归档 is shown only when `canOperate && company.archived_at` is true, in both the
  Company list (any archived row, which includes the Archived-only filtered view) and the
  Company detail page; 编辑/归档 remain hidden on an archived Company as before, and 取消归档
  is never shown for an active Company.
- No route/menu/permission taxonomy change; `/customers` Shell placeholder, 客户→订单
  navigation and `menu/APPROVED_MENU.json` were not touched.

## Restore follow-up verification evidence

All commands were run from the repository root on the US implementation host, after the
restore remediation above.

```text
$ php artisan test --filter=Customers
{"tool":"phpunit","result":"passed","tests":20,"passed":20,"assertions":107,"duration_ms":3202}

$ php artisan test --filter=ModuleArchitectureTest
{"tool":"phpunit","result":"passed","tests":8,"passed":8,"assertions":185,"duration_ms":413,"risky":1}

$ php artisan test --filter=ModuleProviderTest
{"tool":"phpunit","result":"passed","tests":8,"passed":8,"assertions":33,"duration_ms":44}

$ php artisan test --filter=AuthorizationFoundationTest
{"tool":"phpunit","result":"passed","tests":4,"passed":4,"assertions":34,"duration_ms":1174}

$ php artisan test        # full suite
{"tool":"phpunit","result":"passed","tests":185,"passed":183,"assertions":2387,"duration_ms":23192,"skipped":2,"risky":1}

$ npm test                # full Vitest suite
Test Files  14 passed (14)
Tests  86 passed (86)

$ npm run typecheck       # vue-tsc --noEmit
(clean exit, no output)

$ npm run build           # vite build
✓ built in 2.59s

$ npm run typecheck:e2e   # tsc --noEmit -p tsconfig.e2e.json
(clean exit, no output)

$ PLAYWRIGHT_BROWSERS_PATH=<external-versioned-cache>/1.62.1 npm run test:e2e
22 passed (47.0s)          # full repository Browser E2E, including the extended
                            # customers/company.spec.ts archive -> restore lifecycle test
                            # (visibility rules: 取消归档 only on archived rows/detail, never
                            # on active; state transition: Company reappears in default list
                            # and disappears from Archived-only list after restore)
E2E_CLEANUP=PASS

$ git diff --check
(clean exit, no output — no whitespace errors)

$ git status --short
 M app/Modules/Customers/Application/CompanyService.php
 M app/Modules/Customers/Experience/Controllers/CompanyController.php
 M app/Modules/Customers/Experience/Routes/web.php
 M resources/js/Pages/Customers/Companies/Index.test.ts
 M resources/js/Pages/Customers/Companies/Index.vue
 M resources/js/Pages/Customers/Companies/Show.vue
 M tests/Architecture/ModuleArchitectureTest.php
 M tests/Feature/Customers/CompanyManagementTest.php
 M tests/e2e/customers/company.spec.ts
 M docs/60_Codex_Operations/work-packages/ERP-INQUIRY-M1.1-COMPANY-001.md
 M docs/60_Codex_Operations/logs/2026-08-23_ERP-INQUIRY-M1.1-COMPANY-001.md
```

One transient fact observed and resolved within this remediation (not a pre-existing/
out-of-scope failure): the first `npm run test:e2e` run after adding the 取消归档 button
failed one assertion (`tests/e2e/customers/company.spec.ts:44`) because Playwright's
`getByRole('button', { name: '归档' })` performs a substring match by default, so it also
matched the new `取消归档` button. Fixed by adding `{ exact: true }` to the two assertions in
that spec that must distinguish `归档` from `取消归档`; rerun passed 22/22. The Playwright
Chromium browser for `1.62.1` was reinstalled into a fresh disposable, version-pinned,
repository-external cache directory for this session (not committed, not a repository asset),
consistent with the original M1.1 verification evidence.

## Final gate

```text
CLAUDE_IMPLEMENTATION = COMPLETE (bounded M1.1 Company scope; restore/取消归档 UAT follow-up
                          included per Owner authorization on PR #89, 2026-08-23)
US_VERIFICATION = PASS (initial scope and restore follow-up; see Verification evidence and
                          Restore follow-up verification evidence above; 2 pre-existing/
                          out-of-scope facts noted from the initial scope, not fixed)
CHINA_VERIFICATION = NOT RUN
EXISTING_ARCHIVE_OWNER_UAT = PASS
RESTORE_OWNER_RE_UAT = PENDING
MERGE = NOT AUTHORIZED
DEPLOYMENT = NOT AUTHORIZED
```
