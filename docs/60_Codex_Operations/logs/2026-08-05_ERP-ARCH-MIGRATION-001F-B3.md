---
type: work-log
project: BYL ERP
work_package_id: ERP-ARCH-MIGRATION-001F-B3
updated: 2026-08-05
---

# ERP-ARCH-MIGRATION-001F-B3 Work Log

## Execution Record

```text
OWNER_CONTINUOUS_AUTHORIZATION_ID: ERP-ARCH-MIGRATION-001F-CONTINUOUS-20260805
BASE_SHA: b2bc2d6e25699bc93b6ef352bc20f593c1cacd56
BRANCH: docs/erp-arch-migration-001f-b3-work-package
PHASE: READ-ONLY PREFLIGHT
IMPLEMENTATION: NOT STARTED ON THIS BRANCH
MENU_IMPACT: NO
APPROVED_MENU_CHECK: PASS
```

## Evidence Reviewed

```text
app/Models/User.php
app/Models/Role.php
app/Models/Permission.php
app/Providers/FortifyServiceProvider.php
app/Providers/AppServiceProvider.php
app/Http/Controllers/AuthController.php
app/Http/Middleware/ApplyLocalAdminBypass.php
app/Support/AuthorizationKeys.php
app/Support/DataScope.php
app/Console/Commands/CreateErpUser.php
config/auth.php
config/fortify.php
database/factories/UserFactory.php
routes/web.php
tests/Feature/Auth/**
tests/Feature/AuthorizationFoundationTest.php
tests/Feature/Console/CreateErpUserCommandTest.php
tests/e2e/auth/**
tests/e2e/authorization/**
app/Support/Audit/**
app/Modules/ModuleManifest.php
app/Modules/ModulesServiceProvider.php
bootstrap/providers.php
tests/Architecture/ModuleArchitectureTest.php
tests/Architecture/ModuleProviderTest.php
menu/README.md
menu/APPROVED_MENU.json
```

## Preflight Results

```text
ARCHITECTURE_BASELINE: 16 tests / 171 assertions / PASS
AUTH_FOCUSED_BASELINE: 29 tests / 157 assertions / 4 failures / 1 error
AUTH_BASELINE_FAILURES: inactive-user session errors, logout 503, authorization 503, super-admin 503, failed-login audit record lookup error
GIT_DIFF_CHECK: PASS
GIT_FSCK: PASS WITH EXISTING DANGLING OBJECTS ONLY
```

The focused baseline is retained as comparison evidence. Its failures are not claimed as B3 acceptance; implementation must determine which are environment fixtures and which are B3 defects.

## Findings and Decisions

1. Identity is currently distributed across `App\Models`, `App\Providers`, `App\Http`, `App\Support` and `App\Console`; canonical ownership is not yet physical.
2. Fortify currently calls the private `App\Support\Audit\AuditService`; B3 must expose a public Audit Application boundary before Identity provider registration depends on it.
3. Gate registration and local bypass composition currently live in `AppServiceProvider`; B3 moves Identity rules to the Identity Provider while preserving deterministic App → Modules → Fortify boot order.
4. `App\Models` compatibility wrappers must retain Eloquent behavior expected by config, factories, relations and existing tests until all public callers are migrated.
5. No module-specific `Domain` directory is required because B3 has no framework-free domain primitive to extract; empty directories are not created.
6. Menu values are not in scope. The approved baseline was read before audit and remains unchanged.

## Validation Plan

```text
PHP_LINT: all changed PHP files
COMPOSER_VALIDATE: composer validate --strict
ARCHITECTURE: php artisan test --testsuite=Architecture
IDENTITY: php artisan test tests/Feature/Auth tests/Feature/AuthorizationFoundationTest.php tests/Feature/Console/CreateErpUserCommandTest.php
AUDIT: php artisan test tests/Feature/Audit tests/Feature/Auth/AuthAuditTest.php
FULL_PHPUNIT: php artisan test
VITEST: npm test
APPLICATION_TYPECHECK: npm run typecheck
E2E_TYPECHECK: npm run typecheck:e2e
BUILD: npm run build
BROWSER_E2E: npm run test:e2e
DIFF_CHECK: git diff --check
FSCK: git fsck --full
```

## Review and Merge State

```text
WORK_PACKAGE_PR: NOT CREATED
INDEPENDENT_REVIEW: PENDING
IMPLEMENTATION_PR: NOT CREATED
GLOBAL_ARCHITECTURE_GATE: FAIL
MVP1: NOT STARTED / 0%
UAT: NO
DEPLOYMENT: NO
```

## Project Position

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate -> MVP1 Manual Fulfillment / Tencent Docs Replacement -> MVP1.1 WooCommerce Inbound -> MVP1.2 Single Tracking Outbound -> MVP1.3 Sync Orchestration -> Customer / Lead Pilot -> Product / Media -> WordPress Product and Image Sync -> Content / SEO -> Posts / Pages -> GA4 / GSC / Cloudflare -> Google Docs Publishing -> Full Order Workspace -> Logistics OS -> KOL OS and Social Providers -> Inventory and Procurement -> Accounting / ROI Expansion -> Shopify and Additional Integrations -> Dev UAT and Production Hardening
CURRENT_TRACK_PHASE_MAP: 001A MERGED / PASS -> 001B MERGED / PASS -> 001C MERGED / PASS -> 001D COMPLETE / PASS -> 001E COMPLETE / PASS -> 001F-A COMPLETE / CLOSED -> 001F-B1 COMPLETE / CLOSED -> 001F-B2 COMPLETE / CLOSED -> 001F-B3 PREFLIGHT COMPLETE / WORK PACKAGE FORMALIZED -> 001F-B4 PROPOSED -> 001F-B5 PROPOSED -> 001F-C PROPOSED -> 001F-D PROPOSED -> 001F-E PROPOSED -> 001F-F PROPOSED -> Global Architecture Gate FAIL
COMPLETED_PHASES: 001A, 001B, 001C, 001D, 001E, 001F-A, 001F-B1, 001F-B2
CURRENT_PHASE: ERP-ARCH-MIGRATION-001F-B3 READ-ONLY PREFLIGHT / WORK PACKAGE
CURRENT_WORK_PACKAGE: ERP-ARCH-MIGRATION-001F-B3
PACKAGE_STATUS: PREFLIGHT COMPLETE / WORK PACKAGE FORMALIZED / IMPLEMENTATION AUTHORIZED
WAITING_PHASES: 001F-B4, 001F-B5, 001F-C, 001F-D, 001F-E, 001F-F
BASELINE: origin/main@b2bc2d6e25699bc93b6ef352bc20f593c1cacd56
GITHUB_STATE: PR #28 MERGED; B3 Work Package PR not yet created
SOURCE_CONFLICTS: NONE WITH EVIDENCE
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: Independent B3 Work Package review and merge
EVIDENCE_AS_OF: 2026-08-05
```

## Roadmap Hours

```text
CURRENT_PACKAGE_ESTIMATE: B3 5–10h; planning value 7.5h
KNOWN_CURRENT_TRACK_REMAINING: B3–B5 20–40h; planning value 30h
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE
MVP1_TOTAL_ESTIMATE: 122–190h
MVP1_PLANNING_BASELINE: 166h
MVP1_MAXIMUM_ENVELOPE: 192h
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE
ESTIMATE_CONFIDENCE: MEDIUM for B3; LOW / UNKNOWN for total remaining
ESTIMATE_AS_OF: 2026-08-05
ESTIMATE_METHOD: accepted repository estimates only; no actual-hours inference
```

## Estimated Codex Hours

```text
PREFLIGHT_AND_SCOPE: 1.5h; planning value 1.5h
CORE_EXECUTION: 3.0h; planning value 3.0h
TEST_AND_VALIDATION: 2.0h; planning value 2.0h
REMEDIATION_BUFFER: 1.0h; planning value 1.0h
ESTIMATED_EFFECTIVE_HOURS: 7.5h
ESTIMATE_RANGE: 5–10h
ESTIMATE_BASIS: B3 issued range; components reconcile to 7.5h
ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE
ACTUAL_HOURS_BREAKDOWN: NOT RECORDED FOR PREFLIGHT-ONLY DOCUMENTATION PASS
VARIANCE: UNKNOWN WITH EVIDENCE
VARIANCE_REASON: Actual effective hours are not available in an accepted ledger
OWNER_REVIEW: INCLUDED
MERGE: INCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```

FINAL_STATUS: `B3_PREFLIGHT_COMPLETE_WORK_PACKAGE_FORMALIZED_IMPLEMENTATION_AUTHORIZED`
