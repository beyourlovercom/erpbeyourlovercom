---
type: byl-erp-work-log
date: 2026-08-02
work_package: ERP-LOCAL-ADMIN-BYPASS-001
status: preflight-complete-pending-independent-review
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.1
---

# ERP-LOCAL-ADMIN-BYPASS-001 Preflight Log

## Authorization

```text
AUTHORIZED_BASE: 28d6e99b952c1afff42cf4defb93008d3c618844
SOURCE_BRANCH: feature/erp-arch-migration-001e-a-shared-ui-design-tokens
PREFLIGHT_BRANCH: docs/erp-local-admin-bypass-001-preflight
AUTHORIZATION_TYPE: PREFLIGHT ONLY
CODE_CHANGE: NO
CONFIG_CHANGE: NO
ENV_CHANGE: NO
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

## Configuration Decision

```text
ERP_LOCAL_ADMIN_BYPASS=false
ERP_LOCAL_ADMIN_EMAIL=test@example.com
.env.example UPDATE REQUIRED: YES
REAL .env CHANGE: NO
CONFIG CACHE: values frozen until local cache clear/rebuild
LOCAL CONFIG CACHE PROMOTION: PROHIBITED
```

## Proposed Boundary

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

Fourteen independent Feature tests are frozen for default-off behavior, root/login/auth/Gate/Inertia/navigation behavior, Login/Logout/Audit absence, missing/inactive/invalid identity, four non-local environment cases, normal Fortify regression, request cleanup and external temporary config-cache behavior.

## Risks Retained

- `setUser()` fires `Authenticated`, though not `Login`;
- local cached configuration must never be deployed elsewhere;
- middleware must remain after CSRF and before Inertia/route authentication;
- request marker and guard must be cleared for long-running workers;
- local bypass Audit suppression makes the mode invalid for UAT or production evidence.

## Validation

```text
CHANGED_FILES: EXACTLY TWO GOVERNANCE MARKDOWN FILES
CODE_CHANGE: NO
CONFIG_CHANGE: NO
ENV_CHANGE: NO
DATABASE_MUTATION: NO
MIGRATION: NO
DIFF_CHECK: PASS
FSCK: PASS
WORKTREE: CLEAN AFTER COMMIT
PR: NO
```

## Verdict

```text
FINAL_STATUS: ERP_LOCAL_ADMIN_BYPASS_001_PREFLIGHT_COMPLETE_PENDING_INDEPENDENT_REVIEW
NEXT_GATE: independent Preflight review, then separate Owner implementation authorization
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
SOURCE_CONFLICTS: ErpShellNavigation direct permission filtering requires frozen Gate alignment; otherwise none with evidence
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: Independent Preflight review, then separate Owner implementation authorization
EVIDENCE_AS_OF: 2026-08-02; repository and installed Laravel framework source
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
VARIANCE_REASON: framework source and existing tests provided direct evidence; no implementation or environment mutation was authorized
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```
