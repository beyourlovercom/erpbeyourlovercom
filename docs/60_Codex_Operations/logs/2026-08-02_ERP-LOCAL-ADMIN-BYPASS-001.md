---
type: byl-erp-work-log
date: 2026-08-02
work_package: ERP-LOCAL-ADMIN-BYPASS-001
status: merged-pass
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.1
---

# ERP-LOCAL-ADMIN-BYPASS-001 Preflight Log

## Current Accepted Status

```text
STATUS: MERGED / PASS
PR: #16
ACCEPTED_HEAD: 6b63229b7d776104a5736334de639d9b20fb69f3
MAIN_SQUASH_COMMIT: 55ba7320a7046deb04a03abd99053ccd7b7ed11e
MERGED_AT: 2026-08-03T04:41:37Z
MIGRATION: NO
DATABASE_MUTATION: NO
UAT: NO
DEPLOYMENT: NO
```

Earlier entries remain the historical evidence as recorded at their original review gates.

## Authorization

```text
AUTHORIZED_BASE: 28d6e99b952c1afff42cf4defb93008d3c618844
REMEDIATION_BASE_HEAD: d2b24d62e123f2a0df108d7104a2e05422a3b32f
AUTHORIZED_IMPLEMENTATION_HEAD: fb3199c5418b8bd94d1b4bcb17619aaaaccc380c
SOURCE_BRANCH: feature/erp-arch-migration-001e-a-shared-ui-design-tokens
PREFLIGHT_BRANCH: docs/erp-local-admin-bypass-001-preflight
IMPLEMENTATION_BRANCH: feature/erp-local-admin-bypass-001
AUTHORIZATION_TYPE: BOUNDED IMPLEMENTATION ONLY
CODE_CHANGE: YES / BOUNDED
CONFIG_CHANGE: YES / BOUNDED
ENV_EXAMPLE_CHANGE: YES
REAL_ENV_CHANGE: NO
DATABASE_MUTATION: NO
USER_CREATION: NO
ROLE_CHANGE: NO
MIGRATION: NO
PR: NO
MERGE: NO
UAT: NO
DEPLOYMENT: NO
```

## Audited Evidence

```text
BOOTSTRAP: bootstrap/app.php
ROUTES: routes/web.php and effective route middleware labels
AUTH: FortifyServiceProvider, AuthController, config/auth.php, config/fortify.php
AUTHORIZATION: AppServiceProvider, User, AuthorizationKeys, ErpShellNavigation
INERTIA: HandleInertiaRequests
CONFIG: config/** env usage, config cache framework bootstrap
EVENTS: SessionGuard login/setUser/logout behavior and current app listeners
TESTS: Auth, AuthorizationFoundation, AppShell, Audit and related Feature tests
ENV EXAMPLE: .env.example
```

`tests/Feature/Authorization/` does not exist. Current authorization coverage is located at `tests/Feature/AuthorizationFoundationTest.php`, `tests/Feature/AppShellNavigationTest.php` and related Audit/Runtime tests.

## Findings

```text
AUTH_SET_USER_METHOD: Auth::guard('web')->setUser(existing active User)
LOGIN_EVENT: NOT FIRED
AUTHENTICATED_EVENT: FIRED BY LARAVEL / NO CURRENT AUDIT LISTENER
LOGOUT_EVENT: NOT FIRED WHEN POST /logout IS INTERCEPTED
SESSION_LOGIN_WRITE: NONE
REQUEST_USER_AND_AUTH_MIDDLEWARE: RECOGNIZED
INERTIA_SAFE_USER: RECOGNIZED
GATE_BYPASS: REQUEST-MARKER + local + config + same User ONLY
NAVIGATION_CONFLICT: FOUND / ErpShellNavigation DOES NOT USE GATE
NAVIGATION_RESOLUTION: MODIFY ErpShellNavigation TO USE Gate::forUser
MISSING_OR_INACTIVE_USER: GENERIC EXPLICIT 503
NON_LOCAL_ENABLED: EXPLICIT 503
AUDIT_WRITE: NONE FOR BYPASS ENTRY, LOGIN SHORT-CIRCUIT OR LOGOUT INTERCEPT
```

## Frozen Architecture

```text
CONFIG: config/erp_local.php
MIDDLEWARE: app/Http/Middleware/ApplyLocalAdminBypass.php
MIDDLEWARE_POSITION: web append after SubstituteBindings and before HandleInertiaRequests
BOOTSTRAP: append ApplyLocalAdminBypass then HandleInertiaRequests
GATE: preserve super_admin; add active local request marker allow
NAVIGATION: use Gate decisions; no role mutation
ROOT: request User redirects dashboard
LOGIN GET/POST: guest middleware redirects dashboard before Fortify login
LOGOUT POST: middleware intercept after CSRF before Fortify destroy
CLEANUP: forget guard User and remove marker in finally
```

## Governance Remediation

The request-level boundary is now frozen as follows:

```text
APP_ENV: exact local
CONFIG_ENABLED: true
ALLOWED_HOSTS: erp.b, localhost, 127.0.0.1, ::1
CLIENT_IP_SOURCE: direct server peer REMOTE_ADDR; forwarded headers excluded
CLIENT_IP_ALLOWED: 127.0.0.1 or ::1 only
ACTIVE_EXISTING_USER: required
BOUNDARY_ORDER: config → environment → Host → direct peer IP → existing auth state → email → User query
```

When bypass is enabled, any failed environment, Host, direct peer, preexisting authentication-state or identity condition returns 503 without route continuation. Environment, Host, peer and authentication-state failures occur before any `users` query, guard assignment, request marker or Audit write. Active existing identity is necessarily determined by one bounded User query after all pre-query boundaries pass; missing or inactive then fails without guard assignment, marker, Audit or route continuation. When bypass is disabled, normal authentication behavior continues unchanged.

Host matching is normalized then exact; wildcard and suffix matching are prohibited. The loopback decision ignores proxy-derived forwarding headers. Together these boundaries prevent a cached local `app.env=local` plus enabled bypass configuration from activating after accidental promotion to a production Host or non-loopback request. Promotion itself remains prohibited.

Gate local-bypass evaluation must first prove `app()->bound('request')` and that the resolved object is an `Illuminate\Http\Request`. Without both, the local branch returns `null`, does not throw and grants nothing to CLI, Queue, Scheduler, Command or request-less test execution.

An existing `web` guard authentication session key or recaller cookie returns 503 before User resolution. The middleware does not delete or invalidate the session, delete cookies, dispatch Logout, write Audit, override the User or set the marker. The Owner clears the `erp.b` browser cookie manually before bypass use.

## Configuration Decision

```text
ERP_LOCAL_ADMIN_BYPASS=false
ERP_LOCAL_ADMIN_EMAIL=test@example.com
.env.example UPDATE REQUIRED: YES
REAL .env CHANGE: NO
CONFIG CACHE: values frozen until local cache clear/rebuild
LOCAL CONFIG CACHE PROMOTION: PROHIBITED
```

## Implemented Boundary

```text
NEW: config/erp_local.php
NEW: app/Http/Middleware/ApplyLocalAdminBypass.php
NEW: tests/Feature/Auth/LocalAdminBypassTest.php
MODIFY: bootstrap/app.php
MODIFY: app/Providers/AppServiceProvider.php
MODIFY: app/Support/ErpShellNavigation.php
MODIFY: .env.example
NO CHANGE: routes, Fortify, User, AuthController, Audit, schema, dependencies, workflows
```

## Test Contract

Exactly 20 independent Feature tests are frozen. The original 14 cover default-off behavior, root/login/auth/Gate/Inertia/navigation behavior, Login/Logout/Audit absence, missing/inactive/invalid identity, four non-local environment cases, normal Fortify regression, request cleanup and external temporary config-cache behavior.

The six remediation tests are:

```text
test_enabled_bypass_fails_closed_for_unapproved_host_before_user_query
test_enabled_bypass_fails_closed_for_non_loopback_client_before_user_query
test_locally_cached_enabled_configuration_fails_closed_on_production_host
test_gate_without_http_request_returns_null_without_exception_or_local_authorization
test_existing_web_auth_session_fails_closed_without_override_logout_or_audit
test_guard_user_and_request_marker_are_cleared_when_downstream_throws
```

## Implementation Result

```text
NEW: config/erp_local.php
NEW: app/Http/Middleware/ApplyLocalAdminBypass.php
NEW: tests/Feature/Auth/LocalAdminBypassTest.php
MODIFIED: bootstrap/app.php
MODIFIED: app/Providers/AppServiceProvider.php
MODIFIED: app/Support/ErpShellNavigation.php
MODIFIED: .env.example
MIDDLEWARE_ORDER: Session/CSRF → ApplyLocalAdminBypass → auth/Gate; HandleInertia remains in web append
HOST_BOUNDARY: exact erp.b, localhost, 127.0.0.1, ::1
LOOPBACK_BOUNDARY: direct REMOTE_ADDR exact 127.0.0.1 or ::1; forwarded headers ignored
SESSION_BOUNDARY: existing web guard key or recaller cookie → 503 before User query
AUTH_SET_USER: setUser only inside try/finally; no session or remember mutation
LOGOUT_INTERCEPTION: POST /logout redirects dashboard without Fortify Logout or auth.logout Audit
GATE_BYPASS: HTTP Request + local + enabled config + request marker + identical User
NAVIGATION_ALIGNMENT: Gate::forUser(...)->allows(...)
```

## Risks Retained

- `setUser()` fires `Authenticated`, though not `Login`;
- local cached configuration must never be deployed elsewhere;
- approved Host and direct peer loopback checks must precede User lookup and must not trust forwarded headers;
- promoted local cache remains prohibited and is additionally blocked by the request-level Host/IP boundary;
- middleware must remain after CSRF and before Inertia/route authentication;
- existing real authentication state must fail closed without resolution, deletion, invalidation or override;
- request-less Gate calls must return `null` from the local branch;
- request marker and guard must be cleared for long-running workers;
- local bypass Audit suppression makes the mode invalid for UAT or production evidence.

## Validation

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
CODE_CHANGE: YES / BOUNDED
CONFIG_CHANGE: YES / BOUNDED
ENV_EXAMPLE_CHANGE: YES
REAL_ENV_CHANGE: NO
DATABASE_MUTATION: NO
MIGRATION: NO
DEPENDENCY_CHANGE: NO
LOCK_FILE_CHANGE: NO
WORKFLOW_CHANGE: NO
PR: NO
```

## Verdict

```text
FINAL_STATUS: ERP_LOCAL_ADMIN_BYPASS_001_IMPLEMENTATION_COMPLETE_PENDING_INDEPENDENT_REVIEW
NEXT_GATE: independent implementation review; PR and merge remain separately unauthorized
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
SOURCE_CONFLICTS: ErpShellNavigation direct permission filtering requires frozen Gate alignment; otherwise none with evidence
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: Independent implementation review; PR remains separately unauthorized
EVIDENCE_AS_OF: 2026-08-03; bounded implementation and complete automated validation
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: 10h planning value; 8–12h implementation range
KNOWN_CURRENT_TRACK_REMAINING: unchanged; local-only support does not advance 001E-B or 001F
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; project-wide accepted ledger remains incomplete
ESTIMATE_CONFIDENCE: HIGH for bounded local bypass; LOW for remaining Architecture Gate
ESTIMATE_AS_OF: 2026-08-02
ESTIMATE_METHOD: exact middleware/config/Gate/navigation/test boundary and security risk allowance
```

```text
ACTUAL_IMPLEMENTATION_EFFECTIVE_HOURS: 4.3h
ACTUAL_IMPLEMENTATION_BREAKDOWN: configuration and middleware 0.7h; Gate/navigation 0.5h; 20 Feature tests 1.8h; middleware-priority and test-harness remediation 0.7h; full validation and governance 0.6h
IMPLEMENTATION_ESTIMATE_RANGE: 8–12 effective hours
IMPLEMENTATION_PLANNING_VALUE: 10h
IMPLEMENTATION_VARIANCE: -5.7h / -57% against 10h planning value
IMPLEMENTATION_VARIANCE_REASON: existing foundations allowed bounded implementation without dependencies, schema work or route changes
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
