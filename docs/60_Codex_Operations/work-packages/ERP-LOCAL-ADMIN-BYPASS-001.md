---
type: byl-erp-work-package
project: BYL ERP
work_package: ERP-LOCAL-ADMIN-BYPASS-001
status: merged-pass
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.1
---

# ERP-LOCAL-ADMIN-BYPASS-001 — Local-Only Administrator Bypass

## Current Accepted Status

```text
STATUS: MERGED / PASS
PR: #16
ACCEPTED_HEAD: 6b63229b7d776104a5736334de639d9b20fb69f3
MAIN_SQUASH_COMMIT: 55ba7320a7046deb04a03abd99053ccd7b7ed11e
MERGED_AT: 2026-08-03T04:41:37Z
DEFAULT: OFF
ENVIRONMENT: local ONLY
DATABASE_MUTATION: NO
MIGRATION: NO
UAT: NO
DEPLOYMENT: NO
```

The bounded preflight and implementation record below is retained unchanged as historical evidence.

## Work Package

```text
ID: ERP-LOCAL-ADMIN-BYPASS-001
TITLE: Local-Only Administrator Bypass
STATUS: IMPLEMENTATION COMPLETE / PENDING INDEPENDENT REVIEW / MERGE NOT AUTHORIZED
AUTHORIZED_BASE: 28d6e99b952c1afff42cf4defb93008d3c618844
REMEDIATION_BASE_HEAD: d2b24d62e123f2a0df108d7104a2e05422a3b32f
AUTHORIZED_IMPLEMENTATION_HEAD: fb3199c5418b8bd94d1b4bcb17619aaaaccc380c
SOURCE_BRANCH: feature/erp-arch-migration-001e-a-shared-ui-design-tokens
PREFLIGHT_BRANCH: docs/erp-local-admin-bypass-001-preflight
IMPLEMENTATION_BRANCH: feature/erp-local-admin-bypass-001
AUTHORIZATION_TYPE: BOUNDED IMPLEMENTATION ONLY
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
CODE_CHANGE: YES / BOUNDED
CONFIG_CHANGE: YES / BOUNDED
ENV_EXAMPLE_CHANGE: YES
REAL_ENV_CHANGE: NO
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

No implementation change was made to `routes/web.php`, `FortifyServiceProvider`, `AuthController`, `User`, `AuditService`, database schema or existing authentication routes.

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

`.env.example` documents the implemented safe defaults:

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

## Request-Level Local Boundary

The bypass remains inert when `erp_local.admin_bypass.enabled` is false. Normal Fortify, guest, auth, Gate and route behavior continues unchanged in that state. Once the operator requests bypass by setting the frozen configuration flag to Boolean `true`, every condition below is mandatory:

```text
APP_ENV: exact local
CONFIG_ENABLED: true
ALLOWED_HOSTS: erp.b, localhost, 127.0.0.1, ::1
CLIENT_IP: direct peer loopback 127.0.0.1 or ::1 only
ACTIVE_EXISTING_USER: required
```

Host comparison is case-insensitive after lowercasing and removal of IPv6 URL brackets, then exact against the allowlist. Subdomains, suffix matches, wildcard hosts and arbitrary ports do not expand the allowlist. The client boundary uses the direct server peer address from `REMOTE_ADDR`; `X-Forwarded-For`, `Forwarded` and other proxy-derived headers may not establish loopback status for this security decision.

The middleware must evaluate the enabled flag, exact environment, Host, direct peer IP and preexisting authentication-state boundary before validating the email or querying `users`. If any of those pre-query boundaries fails after bypass has been requested:

```text
HTTP_STATUS: 503
USER_QUERY: NO
GUARD_SET_USER: NO
REQUEST_MARKER: NO
AUDIT_WRITE: NO
ROUTE_CONTINUATION: NO
```

`ACTIVE_EXISTING_USER` is necessarily established by the one bounded identity lookup after every pre-query boundary passes. Missing or inactive is therefore a post-query failure, but still produces 503 with no guard assignment, marker, Audit write or route continuation. The contract must not claim that database-backed identity existence was proven without a query.

The exact Host and direct-loopback checks are defense-in-depth against a locally generated cached configuration artifact being promoted into another runtime with `app.env=local` and bypass enabled. They do not authorize promotion of such an artifact; promotion remains prohibited.

## Preexisting Web Authentication Session

The bypass may not silently replace an existing real web authentication state. Because the middleware runs after `StartSession`, it must check for the `web` guard authentication session key and recaller cookie before any User lookup or `setUser()` call. The guard key and recaller name may be inspected, but the guard User must not be resolved during this check because resolution could query the database.

If either authentication session state or a recaller cookie exists:

```text
PREEXISTING_WEB_AUTH_SESSION: 503 / FAIL CLOSED
SESSION_DELETE: NO
SESSION_INVALIDATE: NO
COOKIE_DELETE: NO
LOGOUT_EVENT: NO
AUDIT_WRITE: NO
USER_QUERY: NO
USER_OVERRIDE: NO
REQUEST_MARKER: NO
ROUTE_CONTINUATION: NO
```

The Owner must clear the `erp.b` browser authentication cookie before entering bypass mode. The middleware must not automate that destructive transition.

## Implemented Middleware Contract

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
2. If enabled and `app()->environment('local')` is false, return an explicit 503 before any User query.
3. Normalize and compare the request Host against the exact allowlist; return 503 before any User query on failure.
4. Read the direct peer address from `REMOTE_ADDR` and require exact `127.0.0.1` or `::1`; return 503 before any User query on failure.
5. Inspect the `web` guard session-key presence and recaller-cookie presence without resolving the guard User; return 503 before any User query if either exists.
6. Normalize and validate the configured email. Invalid or empty configuration returns the same generic unavailable 503 as a missing user.
7. Read the existing User by email. Do not create or update anything.
8. Missing or inactive User returns `503 Local administrator bypass is unavailable.`
9. Call `Auth::guard('web')->setUser($user)` and set the request marker.
10. For `POST /logout` / route `logout`, return a redirect to `/dashboard` without entering Fortify's logout controller.
11. Otherwise call the next middleware.
12. In a `finally` block, call `forgetUser()` and remove the request marker on normal responses and downstream exceptions to avoid leaking request identity in long-running workers.

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

Before inspecting the local marker, `Gate::before` must establish an HTTP request context without invoking the global `request()` helper prematurely:

```text
1. app()->bound('request') === true
2. app('request') instanceof Illuminate\Http\Request
3. only then inspect the request marker and request User
4. otherwise return null
```

This preserves normal Gate evaluation and prevents CLI, Queue, Scheduler, Command or request-less test processes from throwing or receiving local bypass authorization. The existing super-admin decision remains available independently of this local-request branch.

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

No new Audit action was added. The local bypass does not call AuditService. Ordinary security-critical Audit failure behavior remains unchanged when bypass is off.

## Environment Fail-Closed Matrix

| Environment | Config disabled | Config enabled |
|---|---|---|
| `local` | normal authentication | bypass may activate only with approved Host, direct loopback peer, no preexisting web authentication state and valid active User |
| `development` / Dev | normal authentication | explicit 503 |
| `testing` | normal authentication | explicit 503 |
| `staging` | normal authentication | explicit 503 |
| `production` | normal authentication | explicit 503 |

The non-local, unapproved-Host, non-loopback and preexisting-session results must not silently ignore the bad switch. They must not set a User, mark the request, query the configured bypass User, write Audit, create a User or proceed to the requested route.

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

## Exact Implemented File Boundary

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

Implemented `tests/Feature/Auth/LocalAdminBypassTest.php` contains exactly 20 independent methods:

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
15. `test_enabled_bypass_fails_closed_for_unapproved_host_before_user_query`
    - enabled local request on an unapproved Host returns 503; a query listener proves `users` was not queried; guard and marker remain unset.
16. `test_enabled_bypass_fails_closed_for_non_loopback_client_before_user_query`
    - approved Host with a non-loopback direct peer returns 503 before any `users` query, identity assignment, marker or route continuation.
17. `test_locally_cached_enabled_configuration_fails_closed_on_production_host`
    - an external cached configuration containing `app.env=local` and enabled bypass still returns 503 on a production Host and performs no identity work.
18. `test_gate_without_http_request_returns_null_without_exception_or_local_authorization`
    - an unbound/non-HTTP request context does not throw, returns `null` from the local bypass branch and preserves ordinary Gate evaluation.
19. `test_existing_web_auth_session_fails_closed_without_override_logout_or_audit`
    - a pre-seeded web guard session key or recaller cookie returns 503 unchanged, without User resolution, invalidation, Logout event, Audit, marker or override.
20. `test_guard_user_and_request_marker_are_cleared_when_downstream_throws`
    - a test-only downstream exception path proves `finally` forgets the in-memory guard User and removes the marker before the exception leaves the middleware.

Tests use `RefreshDatabase` only against Laravel's isolated test database and may create fixtures there. Runtime middleware tests must assert no mutation caused by the request. No Dev, shared, Staging or Production database is authorized.

Existing authentication, audit, authorization, App Shell and full PHP suites remain required regressions.

## Implementation Evidence

```text
MIDDLEWARE_ORDER: web append ApplyLocalAdminBypass then HandleInertiaRequests; ApplyLocalAdminBypass explicitly prioritized before AuthenticatesRequests
SESSION_AND_CSRF: preserved ahead of the bypass middleware
HOST_ALLOWLIST: exact erp.b, localhost, 127.0.0.1, ::1
CLIENT_ADDRESS: direct REMOTE_ADDR exact 127.0.0.1 or ::1; proxy headers ignored
PREEXISTING_AUTH: web guard session key or recaller cookie returns 503 before User query
IDENTITY: existing active User only; setUser inside try/finally; no session or remember write
LOGOUT: POST /logout intercepted after CSRF and before Fortify logout
GATE: existing super_admin preserved; local branch requires HTTP Request, exact local, config true, marker true and identical User
NAVIGATION: Gate::forUser(...)->allows(...) is the single filter
DATABASE_MUTATION_BY_BYPASS: NONE
MIGRATION: NONE
```

Validation evidence at the implementation Head before commit:

```text
FOCUSED_LOCAL_BYPASS: 20 tests / 103 assertions / PASS
AUTH_AUTHORIZATION_SHELL_REGRESSION: 37 tests / 346 assertions / PASS
FULL_PHP: 130 tests / 1109 assertions / PASS
FRONTEND: 10 files / 57 tests / PASS
COMPOSER_VALIDATE: PASS
ROUTE_LIST: PASS / 59 routes
TYPECHECK: PASS
BUILD: PASS
AUTHORIZED_PINT: PASS
MIGRATION_EXECUTION: NOT RUN
```

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
- Host and direct-peer checks must remain exact; proxy headers must never manufacture loopback eligibility;
- the boundary is defense-in-depth against promoted local cache, not permission to deploy a local cache;
- inserting middleware before CSRF would weaken the logout path, so append order is a hard contract;
- resolving the guard User while checking preexisting session state could query or override identity and is prohibited;
- request-less Gate execution must return `null` from the local branch before touching request state;
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

## Implementation Verdict

```text
IMPLEMENTED_ARCHITECTURE: FROZEN HOST/LOOPBACK/SESSION BOUNDARIES, REQUEST-SAFE GATE, NAVIGATION ALIGNMENT AND CLEANUP
ARCHITECTURE_CONFLICT: NONE AFTER INCLUDING ErpShellNavigation IN THE FILE BOUNDARY
IMPLEMENTATION_AUTHORIZATION: COMPLETE
MERGE_AUTHORIZATION: NO
FINAL_STATUS: ERP_LOCAL_ADMIN_BYPASS_001_IMPLEMENTATION_COMPLETE_PENDING_INDEPENDENT_REVIEW
```

## PROJECT POSITION

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Manual Fulfillment / Tencent Docs Replacement → later approved program horizons
CURRENT_TRACK_PHASE_MAP: 001A–001D MERGED / PASS → 001E-A IMPLEMENTATION REMEDIATION COMPLETE / PENDING INDEPENDENT REVIEW → 001E-B WAITING → 001E-C WAITING → 001F WAITING
COMPLETED_PHASES: 001A, 001B, 001C, 001D
CURRENT_PHASE: local development support implementation review alongside 001E-A review gate
CURRENT_WORK_PACKAGE: ERP-LOCAL-ADMIN-BYPASS-001
PACKAGE_STATUS: IMPLEMENTATION COMPLETE / PENDING INDEPENDENT REVIEW / MERGE AUTHORIZATION NO
WAITING_PHASES: local bypass independent review, 001E-A review/visual check, 001E-B, 001E-C, 001F
BASELINE: fb3199c5418b8bd94d1b4bcb17619aaaaccc380c
GITHUB_STATE: implementation branch prepared for independent review; no PR
SOURCE_CONFLICTS: current navigation bypasses Gate and requires the frozen ErpShellNavigation alignment; otherwise none with evidence
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: Independent implementation review; PR remains separately unauthorized
EVIDENCE_AS_OF: 2026-08-03; bounded implementation and complete automated validation
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

Implementation execution remained inside the frozen range:

```text
ACTUAL_IMPLEMENTATION_EFFECTIVE_HOURS: 4.3h
ACTUAL_IMPLEMENTATION_BREAKDOWN: configuration and middleware 0.7h; Gate/navigation 0.5h; 20 Feature tests 1.8h; middleware-priority and test-harness remediation 0.7h; full validation and governance 0.6h
IMPLEMENTATION_ESTIMATE_RANGE: 8–12 effective hours
IMPLEMENTATION_PLANNING_VALUE: 10h
IMPLEMENTATION_VARIANCE: -5.7h / -57% against 10h planning value
IMPLEMENTATION_VARIANCE_REASON: existing Fortify, Audit, Gate, Inertia and isolated test foundations enabled a bounded implementation without dependencies, schema work or application-route changes
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
ACTUAL_EFFECTIVE_HOURS: 2h
ACTUAL_HOURS_BREAKDOWN: repository and middleware evidence 0.4h; event/config-cache analysis 0.3h; Gate/navigation conflict analysis 0.3h; original test/file/rollback contract 0.4h; request Host/IP/session/Gate remediation 0.4h; governance validation 0.2h
VARIANCE: 0h / 0% against 2h planning value
VARIANCE_REASON: remediation used the frozen middleware order and Laravel guard/request contracts; no implementation or environment mutation was authorized
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```
