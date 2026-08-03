---
type: byl-erp-work-log
date: 2026-08-03
work_package: ERP-ARCH-MIGRATION-001E-C
status: merged-pass-complete-closed
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.1
---

# ERP-ARCH-MIGRATION-001E-C Work Log

## HISTORICAL RECORD — Authorization, Implementation and Review

```text
AUTHORIZED_BASE: origin/main@4b9fbb0deacdfc02439fb947398b87c148602c75
BRANCH: feature/erp-arch-migration-001e-c-auth-permission-shell-e2e
IMPLEMENTATION: GRANTED / local bounded E2E only
PLAYWRIGHT_CONFIG: testMatch only
DISPOSABLE_SQLITE_MIGRATION: GRANTED / external fresh database only
SYNTHETIC_USERS: GRANTED / existing Provisioner only
DEPENDENCY_CHANGE: NO
PACKAGE_LOCK_CHANGE: NO
PROVISIONER_CHANGE: NO
RUNNER_CHANGE: NO
WORKFLOW_CHANGE: NO
APPLICATION_CHANGE: NO
MENU_IMPACT: NO
MIGRATION_FILE_CHANGE: NO
REAL_DATABASE_CHANGE: NO
```

## Implementation

- added five Chromium scenario suites covering Login, inactive users, Logout/Session replay, permissions/403/404 and App Shell;
- added credential helpers that derive existing Provisioner identities without printing secrets;
- added a fail-closed browser guard that permits only hostname `127.0.0.1` and reports only error counts;
- extended `playwright.config.ts` collection to Foundation, Auth, Authorization and Shell while preserving all 001E-B execution and artifact settings;
- compared the rendered 11-item navigation directly with `menu/APPROVED_MENU.json` and confirmed Level-2 count zero;
- recorded the existing missing field-to-error `aria-describedby` association without modifying application components.

## Real-browser evidence

```text
RUNTIME: fresh owner-only repository-external run root
DATABASE: fresh external database/e2e.sqlite
APP_ENV: testing
ERP_LOCAL_ADMIN_BYPASS: false
SYNTHETIC_USERS: 3
FOUNDATION_SMOKE: PASS / 1 test
001E_C_BROWSER_TESTS: PASS / 15 tests
COMPLETE_BROWSER_RUN: PASS / 16 tests
AUTH_FAILURE_EQUIVALENCE: PASS
LOGOUT_POST: PASS
CURRENT_SESSION_INVALIDATION: PASS
OLD_COOKIE_REPLAY: REJECTED
REAL_403: PASS / dashboard and settings
REAL_404: PASS / unregistered path
APPROVED_MENU_BROWSER_CHECK: PASS
LEVEL_1_MENU_COUNT: 11
LEVEL_2_MENU_COUNT: 0
CONSOLE_ERRORS: 0
PAGE_ERRORS: 0
UNHANDLED_REJECTIONS: 0
REQUEST_FAILURES: 0
EXTERNAL_REQUESTS: 0
SCREENSHOT_TRACE_VIDEO: off / off / off
STORAGE_STATE: disabled
CLEANUP: PASS
```

All cookies and passwords remained process-memory-only. No cookie value, password, APP_KEY, Session value or request payload was logged or persisted. All failed development attempts also completed external runtime cleanup.

## Bounded test correction record

The initial run exposed a locator ambiguity because the password label also matched the show-password button. The input selector was narrowed to the real password control. Expected 403 document navigation also produced browser-generated console errors; authorization status checks were therefore moved to the Playwright request context sharing the same Browser Context cookies. This preserves real Fortify authentication and real HTTP 403/404 verification without suppressing or ignoring browser errors.

## Independent review remediation

```text
INDEPENDENT_REVIEW_RESULT: CHANGES_REQUIRED
REVIEWED_HEAD: bf67138a2509d7227ea01a806e97b5b0a1156760
ISSUE_1: negative aria-describedby assertions would solidify a known accessibility gap
ISSUE_2: the App Shell absence assertion targeted an unchanged Login page rather than the real 403 body
ACCESSIBILITY_REMEDIATION: negative assertions removed; labels, names, keyboard order, focus and alert contracts retained
AUTHORIZATION_REMEDIATION: real dashboard/settings 403 bodies inspected for Shell, menu, Super Admin and sensitive markers
RESPONSE_BODY_OUTPUT: NO / fixed failure messages only
```

The missing field-to-error `aria-describedby` association is documented as a pre-existing non-blocking gap; not asserted as required behavior. The remediation changes no application component.

Unauthorized-response validation now loads the approved menu markers from `menu/APPROVED_MENU.json` and rejects their labels and URLs in each real 403 response. It also rejects App Shell and explicit Super Admin markers. The previous assertion against a Page that had not navigated to the 403 response was removed.

## Validation record

Final repository validation is recorded in the implementation commit and Draft PR evidence. Required gates include Composer validation, full PHP with command-scoped bypass disabled, full Vitest, both TypeScript checks, Build, menu baseline, E2E safety contracts, 16-test browser run, formatting/syntax, diff check and Git fsck.

## Historical Review State

```text
STATUS: MERGED / PASS / COMPLETE / CLOSED
PR: DRAFT / PENDING CREATION AT THIS LOG REVISION
MARK_READY: NO
MERGE: NO
001F: WAITING (HISTORICAL)
MVP1: NOT STARTED
UAT: NO
DEPLOYMENT: NO
GLOBAL_ARCHITECTURE_GATE: FAIL
```

## CURRENT CLOSED STATUS

PR #22 is MERGED / PASS / COMPLETE / CLOSED. Accepted Head: `2844d8f2f9214a3fb058e546eb92751e49cbe5af`; Main Squash Commit: `3218473b1cfc93f098a2e9607aabe9adb16bcf0d`; 001F is ACTIVE / PREFLIGHT REVIEWED.

## PROJECT POSITION

```text
PHASE_MAP: 001A MERGED / PASS → 001B MERGED → 001C MERGED → 001D COMPLETE / PASS → 001E-A MERGED / PASS → 001E-B MERGED / PASS / CLOSED → 001E-C MERGED / PASS / CLOSED → 001F ACTIVE / PREFLIGHT REVIEWED
CURRENT_PHASE: 001F Architecture Re-audit
CURRENT_WORK_PACKAGE: ERP-ARCH-MIGRATION-001F-A
WAITING_PHASES: 001F
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
```

## Current closure

PR #22 merged at accepted Head `2844d8f2f9214a3fb058e546eb92751e49cbe5af` with main squash commit `3218473b1cfc93f098a2e9607aabe9adb16bcf0d`. Local Chromium evidence passed: Foundation Smoke 1, 001E-C Browser Tests 15 and Complete Browser Run 16. GitHub Actions Browser E2E was not implemented or run. Earlier remediation status is historical evidence only.

## ROADMAP HOURS

```text
001E_C_CONFIRMED_ESTIMATE: 12–20 effective hours; planning value 16h
ARCHITECTURE_GATE_REMAINING: 12–24h planning estimate for 001F-B through 001F-F; actual hours unknown with evidence
MVP1_REMAINING: UNKNOWN
PROGRAM_REMAINING: UNKNOWN
```

## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: included in reviewed Preflight
CORE_EXECUTION: 6–10h
TEST_AND_VALIDATION: 4–7h
REMEDIATION_BUFFER: 2–3h
ESTIMATED_EFFECTIVE_HOURS: 12–20h
ESTIMATE_RANGE: 12–20 effective hours
ESTIMATE_BASIS: authorized browser scenarios, isolation/security helpers, disposable runtime verification and evidence recording
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```
