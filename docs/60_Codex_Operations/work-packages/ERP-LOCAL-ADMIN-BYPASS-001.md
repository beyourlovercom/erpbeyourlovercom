---
type: byl-erp-work-package
project: BYL ERP
work_package: ERP-LOCAL-ADMIN-BYPASS-001
status: preflight-complete-pending-independent-review
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.1
---

# ERP-LOCAL-ADMIN-BYPASS-001 — Local-Only Administrator Bypass

## Work Package

```text
ID: ERP-LOCAL-ADMIN-BYPASS-001
TITLE: Local-Only Administrator Bypass
STATUS: PREFLIGHT COMPLETE / PENDING INDEPENDENT REVIEW / IMPLEMENTATION NOT AUTHORIZED
AUTHORIZED_BASE: 28d6e99b952c1afff42cf4defb93008d3c618844
SOURCE_BRANCH: feature/erp-arch-migration-001e-a-shared-ui-design-tokens
PREFLIGHT_BRANCH: docs/erp-local-admin-bypass-001-preflight
AUTHORIZATION_TYPE: PREFLIGHT ONLY
MODE: LOCAL_ADMIN_BYPASS
DEFAULT: OFF
ENVIRONMENT: local ONLY
LOCAL_EMAIL_DEFAULT: test@example.com
DATABASE_MUTATION: NONE
USER_CREATION: NONE
ROLE_CHANGE: NONE
LOGIN_EVENT: NONE
LOGOUT_EVENT: NONE
AUDIT_WRITE_FOR_BYPASS_ENTRY_OR_EXIT: NONE
ALL_PERMISSIONS: YES / ACTIVE LOCAL BYPASS REQUEST ONLY
CODE_CHANGE: NO
CONFIG_CHANGE: NO
ENV_CHANGE: NO
MIGRATION: NO
PR: NO
MERGE: NO
UAT: NO
DEPLOYMENT: NO
```

This Preflight freezes a local HTTP-request identity override. It does not replace authentication, create a durable login session or alter any user, role, permission or audit record.

## Preserved Security Baseline

The following remain mandatory and unchanged:

```text
Laravel Fortify
GET /login and POST /login
POST /logout
auth and guest middleware
Gate permissions and existing super-admin bypass
AuditService and auth audit listeners
authentication and authorization regression tests
Production authentication requirements
```

No implementation change is proposed for `routes/web.php`, `FortifyServiceProvider`, `AuthController`, `User`, `AuditService`, database schema or existing authentication routes.

## Repository Evidence

### Current web and route middleware order

Laravel's default `web` group is assembled in this order:

```text
1. EncryptCookies
2. AddQueuedCookiesToResponse
3. StartSession
4. ShareErrorsFromSession
5. PreventRequestForgery
6. SubstituteBindings
7. HandleInertiaRequests               current application append
8. route middleware                    guest/auth/can/throttle as declared
```

Current route evidence:

```text
GET /login:  web → guest
POST /login: web → guest:web → throttle:login → Fortify store
POST /logout: web → auth:web → Fortify destroy
GET /dashboard: web → auth → can:dashboard.view
```

The bypass must be appended to the `web` group immediately before `HandleInertiaRequests`, producing:

```text
1–6. unchanged Laravel web middleware
7. ApplyLocalAdminBypass
8. HandleInertiaRequests
9+. route middleware: guest/auth/can/throttle
```

This position is deliberate:

- session and CSRF protections already ran before bypass or logout interception;
- the selected User exists before Inertia shared data is built;
- the selected User exists before `guest`, `auth` and `can` middleware;
- no route alias or route definition changes are required.

The middleware must be registered with:

```php
$middleware->web(append: [
    App\Http\Middleware\ApplyLocalAdminBypass::class,
    App\Http\Middleware\HandleInertiaRequests::class,
]);
```

It must not be prepended ahead of cookies, session or CSRF middleware.

## Configuration Contract

New `config/erp_local.php` is the only configuration reader for the two environment variables:

```php
return [
    'admin_bypass' => [
        'enabled' => env('ERP_LOCAL_ADMIN_BYPASS', false) === true,
        'email' => (string) env('ERP_LOCAL_ADMIN_EMAIL', 'test@example.com'),
    ],
];
```

Frozen keys:

```text
config('erp_local.admin_bypass.enabled')
config('erp_local.admin_bypass.email')
```

Strict comparison means only Laravel's normalized Boolean `true` enables the mode. Missing, `false`, `(false)`, empty or arbitrary string values remain disabled.

`.env.example` must be modified during a separately authorized implementation to document safe defaults:

```dotenv
ERP_LOCAL_ADMIN_BYPASS=false
ERP_LOCAL_ADMIN_EMAIL=test@example.com
```

The real `.env` must never be committed or modified by Codex. `.env.example` is required because the default-off operator contract must be visible and reproducible; omitting it would leave an undocumented security switch.

### Configuration cache behavior

- `env()` is called only inside `config/erp_local.php`;
- `config:cache` freezes the enabled flag, email and `app.env` into cached configuration;
- when cached configuration exists, Laravel does not load `.env` and loads the cached values directly;
- changing `.env` alone therefore has no runtime effect until local configuration cache is cleared or rebuilt;
- a production/staging build must create its own configuration cache with its actual environment and must never promote a local cached configuration artifact;
- implementation tests must use an external temporary `APP_CONFIG_CACHE` path and delete it afterward; repository `bootstrap/cache/config.php` must not be created or changed.

## Proposed Middleware Contract

New `ApplyLocalAdminBypass` is a dedicated web middleware. It is the only component permitted to activate the request marker.

```text
REQUEST_MARKER: erp.local_admin_bypass.active
GUARD: web
RUNTIME_USER_SOURCE: existing users row selected by normalized configured email
SESSION_LOGIN: NO
REMEMBER_COOKIE: NO
USER / ROLE MUTATION: NO
```

Request algorithm:

1. If `erp_local.admin_bypass.enabled` is false, call the next middleware without any side effect.
2. If enabled and `app()->environment('local')` is false, return an explicit 503 before looking up or setting a User.
3. Normalize and validate the configured email. Invalid or empty configuration returns the same generic unavailable 503 as a missing user.
4. Read the existing User by email. Do not create or update anything.
5. Missing or inactive User returns `503 Local administrator bypass is unavailable.`
6. Call `Auth::guard('web')->setUser($user)` and set the request marker.
7. For `POST /logout` / route `logout`, return a redirect to `/dashboard` without entering Fortify's logout controller.
8. Otherwise call the next middleware.
9. In a `finally` block, call `forgetUser()` and remove the request marker to avoid leaking request identity in long-running workers.

The middleware may not write the session authentication key, regenerate the session, set a remember cookie, dispatch Login/Logout/Failed explicitly, call AuditService or mutate the database.

## Auth Set-User Evidence

`Auth::guard('web')->setUser($user)` is suitable for the bounded target with an explicit event limitation:

```text
SESSION UPDATE: NO
LOGIN EVENT: NO
AUTHENTICATED EVENT: YES — Laravel SessionGuard behavior
LOGOUT EVENT: NO
REQUEST user(): YES — existing Request resolver delegates to Auth manager
auth MIDDLEWARE: PASS — guard check sees the set User
```

Laravel's `SessionGuard::login()` updates the session and fires `Login`; `SessionGuard::setUser()` only assigns the in-memory guard User and fires `Authenticated`. The current application listens to `Login`, `Logout` and `Failed`, but not `Authenticated`, so bypass entry does not call existing authentication AuditService listeners. This Preflight does not claim that no authentication-family event exists; it specifically proves `Login` and `Logout` are absent as required.

The Request already resolves its user through the Auth manager, so no replacement request resolver is needed. `request()->user()`, `auth()->user()`, `auth` middleware and `HandleInertiaRequests` safe User props all resolve the same in-memory User.

## Gate and Navigation Bypass

`AppServiceProvider` must preserve the current super-admin callback and add the local request condition to the same `Gate::before` decision:

```text
ALLOW TRUE only when all are true:
- application environment is exactly local;
- config enabled is true;
- request marker is exactly true;
- request User is the same model identity as the Gate User.

OTHERWISE:
- retain existing super_admin behavior;
- return null for normal Gate policy evaluation.
```

The request marker is essential: `local + config enabled` alone must not grant CLI, queue, console or non-web authorization.

### Required navigation alignment

Current `ErpShellNavigation::for()` reads roles and permissions directly and does not call Gate. A User with no role would pass routes through `Gate::before` but receive an empty navigation list. Therefore the implementation must modify `ErpShellNavigation` to filter items through `Gate::forUser($user)->allows($item['permission'])` while preserving normal and super-admin behavior. This makes Gate the single permission decision for both routes and Inertia navigation.

No role is attached and `User::hasPermission()` remains unchanged.

## Login, Root and Logout Behavior

```text
BYPASS OFF:
- /, /login, POST /login and POST /logout remain current Fortify behavior.

LOCAL + ENABLED + ACTIVE USER:
- GET / sees request User and redirects to /dashboard.
- GET /login reaches guest after bypass and redirects to /dashboard.
- POST /login reaches guest:web after bypass and redirects to /dashboard before Fortify authentication.
- protected routes pass auth and can middleware.
- POST /logout is intercepted after CSRF and before auth:web/Fortify destroy, then redirects to /dashboard.
- the next request applies the same local request identity again.
```

The bypass is request-scoped, not a durable session. Manual login remains present but is not exercised while local bypass is enabled.

## Events and Audit Contract

| Path | Login | Logout | Failed | Authenticated | Audit write |
|---|---|---|---|---|---|
| Local bypass entry | no | no | no | yes, from `setUser()` | none |
| Local bypass GET/POST `/login` | no | no | no | yes | none |
| Local bypass POST `/logout` | no | no | no | yes | none |
| Bypass off, valid Fortify login | preserved | n/a | no | preserved | `auth.login` preserved |
| Bypass off, Fortify logout | n/a | preserved | n/a | n/a | `auth.logout` preserved |

No new Audit action is proposed. The local bypass does not call AuditService. Ordinary security-critical Audit failure behavior remains unchanged when bypass is off.

## Environment Fail-Closed Matrix

| Environment | Config disabled | Config enabled |
|---|---|---|
| `local` | normal authentication | bypass may activate only with valid active User |
| `development` / Dev | normal authentication | explicit 503 |
| `testing` | normal authentication | explicit 503 |
| `staging` | normal authentication | explicit 503 |
| `production` | normal authentication | explicit 503 |

The non-local result must not silently ignore the bad switch. It must not set a User, mark the request, query the configured bypass User, write Audit, create a User or proceed to the requested route.

## Missing and Inactive User Behavior

```text
MISSING USER: 503 / generic explicit unavailable message
INACTIVE USER: 503 / same generic explicit unavailable message
INVALID EMAIL CONFIG: 503 / same generic explicit unavailable message
USER CREATION: NO
USER UPDATE: NO
ROLE OR PERMISSION CHANGE: NO
AUDIT WRITE: NO
```

Using the same response for missing, invalid and inactive cases prevents local HTTP output from disclosing account state. Server-side tests distinguish the fixtures and prove no mutation.

## Exact Proposed File Boundary

```text
NEW:
config/erp_local.php
app/Http/Middleware/ApplyLocalAdminBypass.php
tests/Feature/Auth/LocalAdminBypassTest.php

MODIFIED:
bootstrap/app.php
app/Providers/AppServiceProvider.php
app/Support/ErpShellNavigation.php
.env.example
docs/60_Codex_Operations/work-packages/ERP-LOCAL-ADMIN-BYPASS-001.md
docs/60_Codex_Operations/logs/<implementation-date>_ERP-LOCAL-ADMIN-BYPASS-001.md

UNCHANGED:
routes/web.php
app/Providers/FortifyServiceProvider.php
app/Models/User.php
app/Http/Controllers/AuthController.php
app/Support/Audit/**
existing Migrations
real .env
dependencies and lock files
workflows
001E-B and 001F files
```

Any additional application path requires a new Owner decision.

## Exact Feature Test Contract

Proposed `tests/Feature/Auth/LocalAdminBypassTest.php` methods:

1. `test_bypass_is_disabled_by_default_and_guest_contract_is_unchanged`
   - environment local, config default false;
   - `/dashboard` redirects `/login`; `/login` renders the Login page.
2. `test_local_enabled_root_redirects_to_dashboard_for_existing_active_user`
   - existing active `test@example.com`; `/` redirects to `/dashboard`.
3. `test_local_enabled_login_get_and_post_never_render_or_execute_login`
   - GET and POST `/login` redirect `/dashboard`; no Login/Failed events or Audit.
4. `test_local_enabled_user_passes_auth_and_is_shared_safely_with_inertia`
   - roleless active User reaches dashboard; shared id/name/email present; password and remember token absent.
5. `test_local_enabled_user_passes_every_defined_gate_and_receives_full_navigation`
   - all dotted `AuthorizationKeys` allow inside a bypassed web request; all formal navigation items present.
6. `test_local_enabled_entry_does_not_write_session_login_event_or_audit`
   - Login event not dispatched; session guard key absent; Audit count unchanged.
7. `test_local_enabled_logout_is_intercepted_without_logout_event_or_audit`
   - POST `/logout` redirects dashboard; Logout event absent; `auth.logout` absent; next request still bypasses.
8. `test_missing_configured_user_returns_503_without_mutation`
   - users, roles, role_user and audit counts unchanged.
9. `test_inactive_configured_user_returns_503_without_state_disclosure_or_mutation`
   - same generic message and unchanged row/relations/Audit.
10. `test_invalid_configured_email_returns_503_without_query_side_effects`
    - generic failure and no mutation.
11. `test_enabled_bypass_fails_closed_outside_local`
    - loop `development`, `testing`, `staging`, `production`; every request returns 503 before identity assignment.
12. `test_disabling_bypass_preserves_real_fortify_login_logout_and_audit`
    - real password login/logout, Login and Logout events, session behavior and `auth.login`/`auth.logout` records remain intact.
13. `test_request_marker_is_removed_and_guard_user_is_forgotten_after_response`
    - proves long-running process cleanup contract.
14. `test_cached_configuration_uses_frozen_values_and_requires_rebuild`
    - external temporary `APP_CONFIG_CACHE`; cache built with production + enabled still returns 503; cleanup verified; repository cache untouched.

Tests use `RefreshDatabase` only against Laravel's isolated test database and may create fixtures there. Runtime middleware tests must assert no mutation caused by the request. No Dev, shared, Staging or Production database is authorized.

Existing authentication, audit, authorization, App Shell and full PHP suites remain required regressions.

## Rollback

```text
R0: authorized base 28d6e99b952c1afff42cf4defb93008d3c618844
R1: config + .env.example only; default OFF
R2: middleware + bootstrap order; Gate/navigation not yet changed
R3: Gate and navigation alignment
R4: isolated Feature tests and full validation
R5: reviewed implementation commit
```

Rollback order:

1. set local `ERP_LOCAL_ADMIN_BYPASS=false` and clear/rebuild only the local config cache;
2. revert Gate/navigation alignment;
3. remove middleware from the web group and delete it;
4. remove the config and `.env.example` entries;
5. retain Fortify, routes, authentication tests and all database state unchanged.

The immediate operational kill switch is default-off configuration. Code rollback uses an ordinary reviewed Git revert; no database rollback exists.

## Risks

- `setUser()` fires Laravel's `Authenticated` event even though it does not fire `Login`; future listeners on `Authenticated` must be reviewed before implementation or later addition.
- a locally generated cached configuration artifact must never be promoted to another environment;
- inserting middleware before CSRF would weaken the logout path, so append order is a hard contract;
- forgetting the request marker check in `Gate::before` could grant non-web local processes;
- failing to clear the in-memory guard could leak identity under long-running workers;
- Gate-only route changes without `ErpShellNavigation` alignment would create hidden navigation and inconsistent authorization;
- an inactive or missing configured User can make the local UI unavailable by design;
- the bypass intentionally suppresses login/logout Audit evidence locally and must never be used for UAT or production verification;
- tests that alter application environment or config must restore state per test and never write the repository config cache.

## Estimate

```text
CONFIG_AND_MIDDLEWARE: 1.5–2.5h; planning value 2h
GATE_AND_NAVIGATION_ALIGNMENT: 1–2h; planning value 1.5h
FEATURE_TESTS: 3–4.5h; planning value 3.5h
FULL_REGRESSION_AND_REMEDIATION: 2.5–3h; planning value 3h
ESTIMATED_EFFECTIVE_HOURS: 10h
ESTIMATE_RANGE: 8–12 effective hours
OWNER_REVIEW: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```

## Preflight Verdict

```text
PROPOSED_ARCHITECTURE: ACCEPTABLE WITH FROZEN GATE/NAVIGATION ALIGNMENT AND REQUEST CLEANUP
ARCHITECTURE_CONFLICT: NONE AFTER INCLUDING ErpShellNavigation IN THE FILE BOUNDARY
IMPLEMENTATION_AUTHORIZATION: NO
FINAL_STATUS: ERP_LOCAL_ADMIN_BYPASS_001_PREFLIGHT_COMPLETE_PENDING_INDEPENDENT_REVIEW
```

## PROJECT POSITION

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Manual Fulfillment / Tencent Docs Replacement → later approved program horizons
CURRENT_TRACK_PHASE_MAP: 001A–001D MERGED / PASS → 001E-A IMPLEMENTATION REMEDIATION COMPLETE / PENDING INDEPENDENT REVIEW → 001E-B WAITING → 001E-C WAITING → 001F WAITING
COMPLETED_PHASES: 001A, 001B, 001C, 001D
CURRENT_PHASE: local development support Preflight alongside 001E-A review gate
CURRENT_WORK_PACKAGE: ERP-LOCAL-ADMIN-BYPASS-001
PACKAGE_STATUS: PREFLIGHT COMPLETE / PENDING INDEPENDENT REVIEW / IMPLEMENTATION AUTHORIZATION NO
WAITING_PHASES: local bypass implementation, 001E-A review/visual check, 001E-B, 001E-C, 001F
BASELINE: 28d6e99b952c1afff42cf4defb93008d3c618844
GITHUB_STATE: documentation-only Preflight branch; no PR; no implementation branch or PR
SOURCE_CONFLICTS: current navigation bypasses Gate and requires the frozen ErpShellNavigation alignment; otherwise none with evidence
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: Independent Preflight review, then separate Owner implementation authorization if accepted
EVIDENCE_AS_OF: 2026-08-02; repository middleware/routes/providers/models/config/tests and installed Laravel framework source
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: 10h planning value; 8–12h implementation range
KNOWN_CURRENT_TRACK_REMAINING: unchanged; this local-only support package does not advance 001E-B or 001F
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE; 001E and 001F remain incomplete
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; unchanged by this Preflight
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; unchanged by this Preflight
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; project-wide accepted ledger remains incomplete
ESTIMATE_CONFIDENCE: HIGH for bounded local bypass implementation; LOW for remaining Architecture Gate
ESTIMATE_AS_OF: 2026-08-02
ESTIMATE_METHOD: exact middleware/config/Gate/navigation/test file boundary with security remediation allowance
```

## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: 0.4–0.7h; planning value 0.5h
CORE_EXECUTION: 0.6–1h; planning value 0.8h
TEST_AND_VALIDATION: 0.3–0.7h; planning value 0.5h
REMEDIATION_BUFFER: 0.1–0.3h; planning value 0.2h
ESTIMATED_EFFECTIVE_HOURS: 2h
ESTIMATE_RANGE: 1.4–2.7 effective hours
ESTIMATE_BASIS: read-only repository/framework audit, architecture freeze and two governance records
ACTUAL_EFFECTIVE_HOURS: 1.6h
ACTUAL_HOURS_BREAKDOWN: repository and middleware evidence 0.4h; event/config-cache analysis 0.3h; Gate/navigation conflict analysis 0.3h; test/file/rollback contract 0.4h; governance validation 0.2h
VARIANCE: -0.4h / -20% against 2h planning value
VARIANCE_REASON: framework source and existing authentication tests provided direct evidence; no implementation or environment mutation was authorized
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```
