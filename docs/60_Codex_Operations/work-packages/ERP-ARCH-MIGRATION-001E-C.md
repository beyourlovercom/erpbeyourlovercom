---
type: byl-erp-work-package
work_package: ERP-ARCH-MIGRATION-001E-C
title: Auth / Permission / App Shell Browser E2E
status: merged-pass-complete-closed
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.1
---

# ERP-ARCH-MIGRATION-001E-C — Auth / Permission / App Shell Browser E2E

## CURRENT CLOSED STATUS

PR #22 is MERGED / PASS / COMPLETE / CLOSED. Accepted Head: `2844d8f2f9214a3fb058e546eb92751e49cbe5af`; Main Squash Commit: `3218473b1cfc93f098a2e9607aabe9adb16bcf0d`; 001F is ACTIVE / PREFLIGHT REVIEWED.

## HISTORICAL RECORD — Position, Authorization, Implementation and Review

```text
AUTHORIZED_BASE: origin/main@4b9fbb0deacdfc02439fb947398b87c148602c75
BRANCH: feature/erp-arch-migration-001e-c-auth-permission-shell-e2e
IMPLEMENTATION_AUTHORIZATION: GRANTED / bounded local Browser E2E only
PLAYWRIGHT_CONFIG_CHANGE: GRANTED / testMatch only
DISPOSABLE_SQLITE_MIGRATION_EXECUTION: GRANTED / fresh external SQLite only
SYNTHETIC_USER_CREATION: GRANTED / disposable external SQLite only
MERGE_AUTHORIZATION: NOT GRANTED (HISTORICAL)
WORKFLOW_CHANGE: NO
UAT: NO
DEPLOYMENT: NO
```

This package extends the accepted 001E-B local Chromium foundation with real authentication, authorization and App Shell scenarios. It changes no application behavior, route, permission, menu, dependency, Migration or Workflow.

## Frozen implementation boundary

Added:

```text
tests/e2e/auth/login.spec.ts
tests/e2e/auth/logout.spec.ts
tests/e2e/auth/inactive-user.spec.ts
tests/e2e/authorization/permissions.spec.ts
tests/e2e/shell/app-shell.spec.ts
tests/e2e/support/auth-helpers.ts
tests/e2e/support/browser-guards.ts
docs/60_Codex_Operations/work-packages/ERP-ARCH-MIGRATION-001E-C.md
docs/60_Codex_Operations/logs/2026-08-03_ERP-ARCH-MIGRATION-001E-C.md
```

Modified:

```text
playwright.config.ts / testMatch only
```

Explicitly unchanged:

```text
tests/e2e/support/provision-users.php
tests/e2e/support/run-e2e.mjs
tests/e2e/support/e2e-environment.mjs
tests/e2e/support/artifact-scan.mjs
package.json
package-lock.json
menu/APPROVED_MENU.json
.github/workflows/**
application code, routes, permissions and Migrations
```

## Synthetic users

The accepted 001E-B Provisioner remains the single source. Emails are derived from the sanitized per-run `BYL_E2E_RUN_ID`; passwords remain process-only environment secrets and are never logged.

| User | State | Authorization | Expected result |
|---|---|---|---|
| `authorized-<run-id>@byl-e2e.invalid` | active | `super_admin` role | Login succeeds; all formal permissions; 11 approved Level-1 menus |
| `unauthorized-<run-id>@byl-e2e.invalid` | active | no role or permission | Fortify authenticates; Dashboard and Settings return real 403 |
| `inactive-<run-id>@byl-e2e.invalid` | inactive | none | Login fails with the same generic message as other credential failures |

An unprovisioned `missing-<run-id>@byl-e2e.invalid` address proves the unknown-account path without changing the database.

## Browser safety and isolation contract

- every Playwright test receives a fresh default Browser Context;
- no test switches users within one Context;
- one fresh external disposable SQLite database is used per complete E2E run;
- `APP_ENV=testing` and `ERP_LOCAL_ADMIN_BYPASS=false` remain enforced by the accepted Runner and Laravel runtime preflight;
- only requests whose hostname is exactly `127.0.0.1` are allowed;
- console errors, page errors, unhandled rejections, unexplained request failures and external requests fail the test using count-only reports;
- no error report contains URLs, request bodies, cookies, passwords, APP_KEY or Session values;
- screenshot, trace and video remain off; Storage State is not generated;
- success and failure remove the external database, synthetic secrets, reports and run root.

The Logout test retains the pre-logout Session cookie only in process memory. It proves the normal Context is unauthenticated after POST `/logout`, then places the old cookie in a separate fresh Context and proves it cannot recover access to `/dashboard`. The cookie value is never printed, attached, persisted or hashed into a report.

## Scenario contract

### Authentication

- Guest `/dashboard` redirects to `/login`.
- Active Super Admin authenticates through the visible login form and reaches `/dashboard`.
- Wrong password, missing account and inactive account show the identical generic alert without state disclosure.
- An authenticated user cannot render `/login` and is redirected to `/dashboard`.
- Account Dock Logout issues a real POST `/logout`.
- Logout invalidates the current Session and rejects replay of the old Session cookie.
- A fresh test Context never inherits authentication.

### Authorization and errors

- Super Admin passes the real `dashboard.view` gate.
- Active Unauthorized User authenticates through the real Fortify endpoint but receives 403 from `/dashboard` and `/settings`.
- The Unauthorized User receives no App Shell navigation or Super Admin capability.
- `/__e2e-not-found__` returns a real 404.
- 403 and 404 response bodies exclude SQLSTATE, exception/file-path disclosure, stack traces, APP_KEY and database credentials.

Expected 403/404 responses are inspected through the Playwright request context that shares the browser Context's cookie jar. This proves the real HTTP status while avoiding browser-generated console errors caused solely by intentionally loading an error document.

### App Shell and approved menu

- Login lands on Dashboard and loads Authenticated Layout, Global Navigation and Account Dock.
- Synthetic user name and email are shown in the Account Dock.
- Browser navigation is compared directly with `menu/APPROVED_MENU.json`.
- All 11 Level-1 entries must match approved order, Emoji, label and URL; Emoji stays hidden from the accessible name.
- Approved Level-2 count remains zero and no module link renders.
- Refresh preserves the real Session.
- Account Dock supports mouse toggle, Enter opening, Escape close and trigger-focus restoration.

## Accessibility contract and known gap

Browser tests prove login labels/accessibility names, the basic Tab sequence, visible focus, live alert semantics, navigation landmark, menu accessible names and Account Dock keyboard behavior without adding an accessibility dependency.

The existing login error is exposed as an assertive `role="alert"`, but it is not directly attached to the email or password control through `aria-describedby`. This is documented as a pre-existing non-blocking gap; not asserted as required behavior. It is not an 001E-C failure and no application component was changed.

## Independent review remediation

```text
INDEPENDENT_REVIEW_RESULT: CHANGES_REQUIRED
REVIEWED_HEAD: bf67138a2509d7227ea01a806e97b5b0a1156760
ISSUE_1: accessibility gap was incorrectly frozen by negative browser assertions
ISSUE_2: unauthorized navigation absence was asserted against a page that had not rendered the 403 response
REMEDIATION: bounded tests and evidence only
```

The negative `aria-describedby` assertions were removed while label, accessible-name, Tab-order, visible-focus and alert checks remain. The gap remains documentation only, allowing a future accessibility fix without breaking 001E-C.

The Unauthorized User test now inspects the actual `/dashboard` and `/settings` 403 response bodies. Both bodies must exclude App Shell markers, every approved menu label and URL loaded from `menu/APPROVED_MENU.json`, explicit Super Admin markers and the existing sensitive-disclosure markers. Failures use fixed messages and never print or persist the response body.

## Implementation evidence

```text
PLAYWRIGHT: exact @playwright/test@1.62.1
PROJECT: chromium only
WORKERS: 1
RETRIES: 0
FOUNDATION_SMOKE: PASS / 1 test
001E_C_BROWSER_TESTS: PASS / 15 tests
COMPLETE_BROWSER_RUN: PASS / 16 tests
CONSOLE_ERRORS: 0
PAGE_ERRORS: 0
UNHANDLED_REJECTIONS: 0
REQUEST_FAILURES: 0
EXTERNAL_REQUESTS: 0
LEVEL_1_MENU_COUNT: 11
LEVEL_2_MENU_COUNT: 0
CLEANUP: PASS
```

The first implementation run failed safely because the broad password-label locator also matched the show-password button. The next bounded attempt exposed browser-generated errors from expected 403 document loads. The test-only implementation was narrowed to the password input and to status inspection through the shared Browser Context request client. Every failed run removed its external runtime; the final run passed all 16 tests.

## Rollback

Revert this package's single implementation commit. That removes the new specifications and helpers and restores the Foundation-only `testMatch`; the accepted 001E-B Runner, Provisioner, dependency, browser cache and database safety contracts remain intact.

## Historical Review Gates

```text
CURRENT_STATUS: MERGED / PASS / COMPLETE / CLOSED (historical snapshot)
MARK_READY: NOT AUTHORIZED
MERGE: NOT AUTHORIZED
WORKFLOW: NOT AUTHORIZED
001F: WAITING (HISTORICAL)
MVP1: NOT STARTED
UAT: NO
DEPLOYMENT: NO
GLOBAL_ARCHITECTURE_GATE: FAIL
```

## CURRENT PROJECT POSITION

```text
PHASE_MAP: 001A MERGED / PASS → 001B MERGED → 001C MERGED → 001D COMPLETE / PASS → 001E-A MERGED / PASS → 001E-B MERGED / PASS / CLOSED → 001E-C MERGED / PASS / CLOSED → 001F ACTIVE / PREFLIGHT REVIEWED
CURRENT_PHASE: 001F Architecture Re-audit
DOCUMENTED_PACKAGE: ERP-ARCH-MIGRATION-001E-C
DOCUMENTED_PACKAGE_STATUS: MERGED / PASS / COMPLETE / CLOSED
CURRENT_ACTIVE_PACKAGE: ERP-ARCH-MIGRATION-001F-A
CURRENT_ACTIVE_PACKAGE_STATUS: IMPLEMENTED / PENDING INDEPENDENT REVIEW
PR: #22
ACCEPTED_HEAD: 2844d8f2f9214a3fb058e546eb92751e49cbe5af
MAIN_SQUASH_COMMIT: 3218473b1cfc93f098a2e9607aabe9adb16bcf0d
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
```

## ROADMAP HOURS

```text
001E_C_CONFIRMED_ESTIMATE: 12–20 effective hours; planning value 16h
ARCHITECTURE_GATE_REMAINING: 12–24h planning estimate for 001F-B through 001F-F; actual hours unknown with evidence
MVP1_REMAINING: UNKNOWN
PROGRAM_REMAINING: UNKNOWN
```

## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: included in the reviewed Preflight
CORE_EXECUTION: 6–10h
TEST_AND_VALIDATION: 4–7h
REMEDIATION_BUFFER: 2–3h
ESTIMATED_EFFECTIVE_HOURS: 12–20h
ESTIMATE_RANGE: 12–20 effective hours
ESTIMATE_BASIS: five browser scenario suites, two safety helpers, real disposable runtime validation and governance evidence
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```
