# MVP1 UAT Remediation: Auth and Home

## Work Package

- Work Package: `MVP1_UAT_REMEDIATION_AUTH_HOME_001`
- Contract: `BYL-ERP-AUTH-HOME-001`
- Contract Status: `OWNER_APPROVED / IMPLEMENTED / OWNER_UAT_PASS`
- Baseline Branch: `main`
- Baseline SHA: `f5d028ae45620b7b476aca97c363197b265cfa24`
- Working Branch: `feature/mvp1-uat-remediation-auth-home-001`
- CURRENT_PHASE: `OWNER_UAT_COMPLETE`
- PACKAGE_STATUS: `COMPLETE / PASS / READY_FOR_PR`
- LAST_COMPLETED_GATE: `LOCAL_RUNTIME_VERIFICATION`
- OWNER_UAT_STATUS: `PASS`
- OWNER_UAT_RESULT: `PASS`
- OWNER_UAT_DATE: `2026-08-07`
- LOCAL_REMEDIATION_STATUS: `CLOSED_PASS`
- PR_STATUS: `NOT_CREATED`
- PUSH_STATUS: `NOT_ATTEMPTED`
- NEXT_PHASE: `READY_FOR_OWNER_AUTHORIZED_PUSH_AND_DRAFT_PR`
- NEXT_ACTION: `Await Owner authorization to push the remediation branch and create a Draft PR.`

## Frozen Contract

`BYL-ERP-AUTH-HOME-001` is owner-approved and frozen. This work package uses the existing Laravel web guard and Fortify flow only. It introduces no public registration, no second authentication system, no schema change, and no dependency change.

## Read-Only Audit

- `/` is the single `AuthController::root` entry: guests render `Auth/Login`; authenticated users redirect to `dashboard`.
- `/login` uses `guest` middleware and the same `Auth/Login` Inertia component; authenticated users redirect to `dashboard`.
- `/dashboard` is protected by `web`, `auth`, and `can:dashboard.view` middleware.
- Fortify supplies `POST /login` and `POST /logout`; `fortify.home` is `/dashboard`; no `register` route is registered because Fortify features are empty.
- `IdentityFortify::authenticateUsing` already rejects `is_active = false`, uses generic Fortify failure behavior, retains five-per-minute login limiting, and records login, failure, and logout audits without credentials.
- `resources/js/Pages/Auth/Login.vue` renders no remember control or public registration link, but its Inertia payload still includes `remember: false`.
- The web guard is the Laravel `session` guard with the Eloquent user provider. Session configuration is database-backed locally, `HttpOnly`, `SameSite=Lax`, root path, and non-expiring-on-close; remember-cookie duration was not explicitly configured.
- Local `http://erp.b` responds successfully. Its running configuration reports database sessions, a local Redis endpoint, `HttpOnly=true`, and `SameSite=lax`.
- Existing browser E2E uses disposable SQLite and loopback-only runtime; credentials are generated outside the repository and are not logged.

## Gap Matrix

| Contract | Current Behavior | Evidence | Gap | Planned Change |
|---|---|---|---|---|
| Guest `/` and `/login` | Same `Auth/Login` page | Identity routes and controller | None | Cover explicitly |
| Authenticated `/` and `/login` | Redirects to dashboard | Root controller and guest middleware | None | Cover explicitly |
| Public registration disabled | No register feature or route | `config/fortify.php`, route list | None | Retain and cover |
| Default persistence | Fortify honors request `remember`; UI payload sends false | Fortify pipeline and Login form | Login is not persistent by default | Force existing guard login to remember and configure 30 days |
| Remember UI | No checkbox rendered | Login component | Stale false payload remains | Remove field and assert absence |
| Logout invalidation | Fortify logout invalidates session, regenerates CSRF, rotates remember token | Fortify controller and SessionGuard | Need regression coverage for persistent credential | Add focused and browser replay coverage |
| Inactive login | Rejected generically | `IdentityFortify` | None | Retain and cover |
| Inactive existing session | Not globally revalidated after login | Existing middleware order | Existing session can continue | End inactive sessions in web middleware |
| Client credential storage | No persistent JS password state | Login component and guards | Need regression coverage | Add browser storage assertions |

## Implementation Plan

1. Configure the existing web guard's remember-cookie duration as 30 days.
2. Use a Fortify login pipeline action that always invokes the existing guard with persistence enabled after the existing active-user credential callback succeeds.
3. Add web middleware that logs out and invalidates an authenticated user that has become inactive.
4. Remove the non-controlling `remember` client payload field.
5. Add focused backend, frontend, and browser coverage; execute the required local validation gates.

## Actual Modified Files

- `.env.example`
- `app/Modules/Identity/Experience/Fortify/AttemptPersistentAuthentication.php`
- `app/Modules/Identity/Experience/Fortify/IdentityFortify.php`
- `app/Modules/Identity/Experience/Middleware/EnsureActiveAuthenticatedUser.php`
- `bootstrap/app.php`
- `config/auth.php`
- `resources/js/Pages/Auth/Login.vue`
- `resources/js/Pages/Auth/Login.test.ts`
- `tests/Feature/Auth/AuthHomeContractTest.php`
- `tests/e2e/auth/login.spec.ts`
- `tests/e2e/support/auth-helpers.ts`

## Test Evidence

- PHP syntax validation: PASS for all changed PHP files.
- Focused backend tests: PASS, 17 tests and 204 assertions (`AuthHomeContractTest`, `AuthAuditTest`, `LogoutSessionTest`, `AppShellNavigationTest`).
- Focused frontend test: PASS, 5 Login tests.
- TypeScript E2E typecheck: PASS.
- Vue typecheck: PASS.
- Vite production build: PASS.
- Runtime configuration inspection: PASS; local `http://erp.b` returns HTTP 200, web guard remember duration resolves to 43,200 minutes, database sessions are configured with `HttpOnly=true` and `SameSite=lax`.
- Browser E2E: PASS. `BYL_E2E_BROWSER=chrome npm run test:e2e` completed 18 Chromium scenarios against a disposable SQLite runtime; all tests passed and cleanup passed.
- Playwright precheck: PASS. The repository-local `@playwright/test` CLI exists and reports version `1.62.1`; no package or lock file was changed.
- External cache discovery: no compatible Chromium executable existed in the authorized target or checked standard caches.
- Chromium download route: CANCELLED by Owner decision. The two prior download failures are not retried and no TLS setting was changed.
- System Chrome prerequisite: PASS. `/Applications/Google Chrome.app/Contents/MacOS/Google Chrome` exists and reports `Google Chrome 151.0.7922.76`; repository-local Playwright `1.62.1` launched it with `channel: 'chrome'`.
- Harness contract: PASS. Explicit `BYL_E2E_BROWSER=chrome` skips only the bundled browser-cache precondition; absent/empty mode retains the existing versioned `PLAYWRIGHT_BROWSERS_PATH` requirement, and other values fail closed. CI defaults are unchanged.
- Full PHP regression: PASS, 161 passed, 2 skipped, 1 existing risky test classification.
- Full frontend regression: PASS, 71 tests.
- Full type and build: PASS, Vue typecheck, E2E TypeScript typecheck, and Vite production build.
- E2E harness contract tests: PASS, 16 tests.
- Local `erp.b` runtime: PASS for guest root/login rendering, dashboard-to-login redirect, absent remember control, session configuration (`database`, `HttpOnly`, `SameSite=lax`), and guard persistence duration (`43200` minutes). The only observed browser console resource error was the pre-existing `GET /favicon.ico` 404; it is outside Auth/Home scope. Isolated Chrome E2E observed no console, page, request, or external-network errors.

## Known Limits

- Browser restart and simulated elapsed-time checks can be automated through a fresh browser context and cookie-expiry assertions; they do not claim a completed human next-day test.
- The Owner completed local UAT on `2026-08-07` and confirmed all frozen Auth/Home contract items PASS: guest routes, default dashboard entry, absent Remember Me control, persistent login after complete browser restart, logout invalidation, Back/Refresh protection, inactive-user rejection, and no client plaintext password storage.
- The unrelated local `GET /favicon.ico` 404 remains outside this Work Package.

## Blocker

None.

## Current Commit

- `783c853 fix(auth): default persistent active sessions`
- `f2629bb test(auth): cover default persistent login`
- `5bf841e test(e2e): support local system Chrome`
- `99ecbc7 docs(uat): record auth home handoff`
- `0c87457 docs(uat): finalize auth home evidence`

## Owner UAT Steps

1. Create a separate local Chrome profile with no existing ERP cookies.
2. Visit `http://erp.b/` and confirm the login page is shown.
3. Visit `http://erp.b/login` and confirm it is the same login page.
4. Confirm no Remember Me / automatic-login checkbox and no public registration control are shown.
5. Sign in using an Owner-provided active internal test account; confirm the destination is `/dashboard`.
6. Close every Chrome window, reopen Chrome with the same profile, then visit `http://erp.b/`; confirm the existing login reaches `/dashboard`.
7. Click `退出登录`, then visit `/dashboard`, use browser Back, and refresh; confirm login is required and protected content is unavailable.
8. Confirm a controlled inactive test account receives the same generic login failure as invalid credentials.
9. In Chrome DevTools, inspect cookies, Local Storage, and Session Storage; confirm no password value is present. Do not copy cookie values into UAT evidence.
10. Record Owner actual results and redacted screenshots in the formal UAT process. Do not mark the formal finding closed from this work package.

## Owner UAT Closure

- OWNER_UAT_STATUS: `PASS`
- OWNER_UAT_RESULT: `PASS`
- Owner confirmation date: `2026-08-07`
- Browser runtime: system Google Chrome via repository-local Playwright `1.62.1` using explicit `BYL_E2E_BROWSER=chrome`.
- Schema Delta: `NONE`
- Dependency Delta: `NONE`
- Auth/Home frozen contract: satisfied.
- Formal UAT files and PR #73 remain unchanged. This `CLOSED_PASS` status closes only local remediation and Owner UAT; it does not indicate merge, release, or Production completion.

## Rollback

To roll back locally, switch away from this feature branch and use the baseline SHA or revert the authorized local commits through the normal review process. Do not reset, force-push, or alter PR #73.
