---
type: work-package
project: BYL ERP
work_package_id: ERP-ARCH-MIGRATION-001F-B5
title: Shell / Dashboard Boundary & Compatibility Cleanup
status: implementation-complete-pass-merged-post-merge-closure-in-progress
updated: 2026-08-05
---

# ERP-ARCH-MIGRATION-001F-B5 — Shell / Dashboard Boundary & Compatibility Cleanup

## Authorization and Baseline

```text
OWNER_CONTINUOUS_AUTHORIZATION_ID: ERP-ARCH-MIGRATION-001F-CONTINUOUS-20260805
BASE_BRANCH: main
BASE_SHA: 26e22150f4f729aaf321a71e052a3602df9aec61
STANDARD: BYL-ERP-ARCH-001 v1.1
MENU_IMPACT: NO
APPROVED_MENU_CHECK: PASS
GLOBAL_ARCHITECTURE_GATE: FAIL
MVP1: NOT STARTED / 0%
MIGRATION: NOT AUTHORIZED FOR B5
UAT: NO
DEPLOYMENT: NO
```

## Read-only Preflight Findings

```text
CURRENT_SHELL_OWNERS: App\Http\Controllers\ErpShellController; App\Support\ErpShellNavigation; App\Support\LegacyErpNavigationPaths; routes/web.php closures; resources/js/Layouts and Components/AppShell
CURRENT_DASHBOARD_OWNER: App\Http\Controllers\DashboardController; /dashboard route; resources/js/Pages/Dashboard/Index.vue
CURRENT_ROUTE_CONTRACT: all URLs, route names, auth middleware, permission keys and approved level-1 navigation values are frozen
LEGACY_PROTOTYPE: /kol/orders and legacy erp.shell composition remain compatibility-only; no Orders or Logistics module may be created
APP_SHARED: absent and not required
WRAPPERS: B3/B4 compatibility wrappers remain until public callers are migrated or a removal condition is proven
MENU_BASELINE: 11 level-1 items and approved values read from menu/APPROVED_MENU.json; unchanged
```

## Implementation Scope

Create only required canonical boundaries:

```text
app/Modules/Shell/Application/
app/Modules/Shell/Experience/
app/Modules/Shell/Providers/
app/Modules/Dashboard/Application/
app/Modules/Dashboard/Experience/
app/Modules/Dashboard/Providers/
```

- Move durable Shell/Dashboard controller, route-registration and navigation composition ownership out of app-level files.
- Keep `routes/web.php` limited to bounded composition and compatibility registration.
- Preserve existing Vue layout and AppShell component contracts; do not create a second frontend navigation source.
- Keep legacy prototype rendering and old public controller/support symbols as thin wrappers where callers still require them.
- Delete a B3/B4 wrapper only after proving no public compatibility contract needs it; otherwise record its canonical owner and removal condition.

## Frozen Contracts

```text
ROUTES: dashboard; shell.*; kol.orders; kol.orders.show; architecture.foundation
MIDDLEWARE_AND_PERMISSIONS: unchanged
MENU: menu/APPROVED_MENU.json and config/erp_shell_navigation.php unchanged
SHELL_UI: no visual or accessibility regression in authenticated layout, navigation or account dock
DATABASE_AND_MIGRATIONS: no schema, migration file or migration execution
RESERVED_SCOPE: no App\Modules\Orders, no App\Modules\Logistics, no business persistence, no external sync
```

## Validation

```text
ARCHITECTURE: php artisan test --testsuite=Architecture
PROVIDER_AND_ROUTE: tests/Architecture tests/Feature/AppShellNavigationTest.php tests/Feature/ErpNavigationTest.php
MENU: php artisan test tests/Feature/ApprovedMenuBaselineTest.php
FRONTEND: npm test; npm run typecheck; npm run typecheck:e2e; npm run build
BROWSER: PLAYWRIGHT_BROWSERS_PATH=<external-versioned-cache> npm run test:e2e
FULL_PHPUNIT: ERP_LOCAL_ADMIN_BYPASS=false php artisan test
COMPOSER: composer validate --strict
INVARIANTS: git diff --check; git fsck --full; no migration/menu delta
```

## Risks and Rollback

- Route registration order may change URL/name/middleware behavior; retain route inventory tests.
- Navigation composition may leak unauthorized entries; retain menu and permission tests plus Browser E2E.
- Legacy prototype fallback may drift; retain exact `/kol/orders` and legacy path checks.
- Wrapper cleanup may break an external caller; retain aliases until an explicit removal condition passes.
- B5 must not turn placeholder/prototype routes into Orders or Logistics implementation.

Rollback is a revert of the B5 implementation squash commit. No database rollback is required.

## Acceptance Criteria

1. Shell and Dashboard canonical ownership exists under approved module boundaries without empty speculative layers.
2. Route names, URLs, middleware, permission keys, approved menu values and App Shell behavior remain unchanged.
3. Legacy prototype compatibility remains bounded and no Orders/Logistics module exists.
4. No duplicate navigation or durable application logic remains in `routes/web.php` or wrappers.
5. Architecture, route, menu, frontend, full PHP and Browser E2E validation report no new regression.
6. No migration, database, UAT, deployment or MVP1 work occurs.

## Project Position

```text
CURRENT_PHASE: ERP-ARCH-MIGRATION-001F-B5 POST-MERGE CLOSURE
PACKAGE_STATUS: IMPLEMENTATION COMPLETE / PASS / MERGED / POST-MERGE CLOSURE IN PROGRESS
WORK_PACKAGE_PR: #35; MAIN_SQUASH: 330f23ecc145d6205b786edfbfb57b5979ac074a
IMPLEMENTATION_PR: #36; ACCEPTED_HEAD: 010b62d8601843d406acc322a93a4a7f4e290bd9; MAIN_SQUASH: 1e876120285e988a46123742928c4307bcde934b
NEXT_DEPENDENCY: B5 post-merge closure review and merge; then 001F-C
GLOBAL_GATE: FAIL
MVP1: NOT STARTED / 0%
```

## Implementation Completion

```text
B5_IMPLEMENTATION_PR: #36
B5_ACCEPTED_HEAD: 010b62d8601843d406acc322a93a4a7f4e290bd9
B5_MAIN_SQUASH_COMMIT: 1e876120285e988a46123742928c4307bcde934b
B5_MERGED_AT: 2026-08-05T01:12:52Z
FINAL_INDEPENDENT_REVIEW: PASS at 010b62d8601843d406acc322a93a4a7f4e290bd9
VALIDATION: Architecture 16/177 PASS; route/menu 23/484 PASS; full PHPUnit 150/1499 PASS; Vitest 71 PASS; typechecks/build PASS; Browser E2E 16 PASS with external disposable SQLite cleanup PASS
MIGRATION: NO FILE CHANGE / no real database operation
MENU_IMPACT: NO
UAT: NO
DEPLOYMENT: NO
```

FINAL_STATUS: `B5_IMPLEMENTATION_COMPLETE_PASS_MERGED_POST_MERGE_CLOSURE_IN_PROGRESS`
