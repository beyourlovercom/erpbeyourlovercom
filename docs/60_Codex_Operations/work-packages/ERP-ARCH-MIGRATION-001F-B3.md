---
type: work-package
project: BYL ERP
work_package_id: ERP-ARCH-MIGRATION-001F-B3
title: Identity & Access Boundary Migration
status: IMPLEMENTATION COMPLETE / PASS / MERGED / POST-MERGE CLOSURE IN PROGRESS
updated: 2026-08-05
---

# ERP-ARCH-MIGRATION-001F-B3 — Identity & Access Boundary Migration

## Authorization and Baseline

```text
OWNER_CONTINUOUS_AUTHORIZATION_ID: ERP-ARCH-MIGRATION-001F-CONTINUOUS-20260805
AUTHORIZED_SCOPE: PR #28 final review through 001F final post-merge closure
BASE_BRANCH: main
BASE_SHA: b2bc2d6e25699bc93b6ef352bc20f593c1cacd56
BASE_VERIFIED_AT: 2026-08-05 Asia/Hong_Kong
STANDARD: BYL-ERP-ARCH-001 v1.1
MENU_IMPACT: NO
APPROVED_MENU_CHECK: PASS
GLOBAL_ARCHITECTURE_GATE: FAIL
MVP1: NOT STARTED / 0%
UAT: NO
STAGING_MIGRATION: NO
PRODUCTION_MIGRATION: NO
PRODUCTION_DEPLOYMENT: NO
```

## Objective

Move Identity-owned authentication and authorization behavior into the canonical `app/Modules/Identity/` boundary while preserving the existing public compatibility contract. The implementation must remove duplicate business rules from legacy namespaces without introducing a second model or authorization truth.

## Read-only Preflight Findings

```text
CURRENT_USER_MODEL: App\Models\User
CURRENT_ROLE_MODEL: App\Models\Role
CURRENT_PERMISSION_MODEL: App\Models\Permission
CURRENT_AUTH_PROVIDER: config/auth.php -> App\Models\User
CURRENT_FORTIFY_PROVIDER: App\Providers\FortifyServiceProvider
CURRENT_GATE_REGISTRATION: App\Providers\AppServiceProvider
CURRENT_LOCAL_BYPASS: App\Http\Middleware\ApplyLocalAdminBypass
CURRENT_AUTH_CONTROLLER: App\Http\Controllers\AuthController
CURRENT_IDENTITY_COMMAND: App\Console\Commands\CreateErpUser
CURRENT_PERMISSION_DICTIONARY: App\Support\AuthorizationKeys
CURRENT_DATA_SCOPE: App\Support\DataScope
CURRENT_AUDIT_CALLER: Fortify and CreateErpUser use App\Support\Audit\AuditService
CURRENT_PROVIDER_ORDER: AppServiceProvider -> ModulesServiceProvider -> FortifyServiceProvider
CURRENT_TABLES: users, roles, permissions, role_user, permission_role
CURRENT_BASELINE_ARCHITECTURE: 16 tests / 171 assertions / PASS
CURRENT_AUTH_FOCUSED_BASELINE: 29 tests / 157 assertions / 4 failures / 1 error
```

## Scope

Create only the Identity layers required by the current behavior:

```text
app/Modules/Identity/Application/
app/Modules/Identity/Infrastructure/
app/Modules/Identity/Experience/
app/Modules/Identity/Providers/
```

Identity owns:

- canonical User, Role and Permission models;
- authentication configuration and Fortify registration;
- authorization keys, Gate and Policy registration;
- local-only administrator bypass integration;
- login, logout, failed-login and inactive-user behavior;
- Identity-owned authentication controller and user-provisioning command;
- Identity public contracts used by other modules.

The implementation may add focused Identity tests and update architecture/provider tests. Existing `database/migrations/**` files remain unchanged.

## Compatibility Contract

The following must remain unchanged:

- `users`, `roles`, `permissions`, `role_user` and `permission_role` tables;
- `App\Models\User`, `App\Models\Role` and `App\Models\Permission` public class names while dependents remain;
- relations, fillable/hidden behavior, casts, factories and serialization;
- `config/auth.php` provider behavior and session guard;
- login, inactive-user rejection, logout, session invalidation and five-per-minute throttling;
- `AuthorizationKeys`, `DataScope`, Route Names, Permission Keys and middleware behavior;
- local-only bypass host, loopback, environment and active-user checks;
- browser authentication and authorization behavior;
- audit events, sources, no-secret sanitization and critical audit-write failure behavior;
- no duplicate audit write and no second Identity model implementation.

Legacy classes are compatibility wrappers or aliases that delegate to canonical Identity classes. They contain no business rules and record a removal condition in the implementation log.

## Cross-module Boundary

Identity must not import Audit private Infrastructure. Authentication and provisioning use a public Audit Application contract, action or approved event. The selected implementation must preserve the existing `AuditService` public behavior for current callers without creating a second writer binding or duplicate record.

Audit remains the owner of audit persistence. Identity supplies actor/source/action data through the approved public boundary; it does not query `audit_records` or construct an Audit Infrastructure writer.

## Provider and Route Contract

Create an Identity Provider and register it through `App\Modules\ModuleManifest::providers()` in deterministic order. `AppServiceProvider` must no longer own Identity Gate business registration after the migration. Existing App-level framework composition may remain only where required by staged compatibility.

Move Identity-owned routes to module route registration where appropriate while preserving:

```text
/                   root
/login              login
login               route name
root                route name
auth                middleware semantics
```

No governed level-1 or level-2 menu value changes are permitted.

## Prohibited Scope

```text
APP\Modules\Orders: NOT CREATED
APP\Modules\Logistics: NOT CREATED
APP\Shared: NOT CREATED UNLESS FRAMEWORK-FREE PRIMITIVE IS PROVEN NECESSARY
DATABASE_MIGRATIONS: NO MOVE / RENAME / COPY / REBUILD / EXECUTION
MENU_BASELINE: NO CHANGE
REAL_EXTERNAL_PROVIDER_SYNC: NO
UAT: NO
STAGING_MIGRATION: NO
PRODUCTION_MIGRATION: NO
PRODUCTION_DEPLOYMENT: NO
MVP1_BUSINESS_IMPLEMENTATION: NO
UNRELATED_REFACTORING: NO
```

## Acceptance Criteria

1. Canonical Identity classes and providers exist only in `app/Modules/Identity/**` for new ownership.
2. Legacy Identity symbols delegate without duplicate business rules.
3. Auth provider, Fortify, Gate, local bypass and Identity routes preserve their current contracts.
4. Identity-to-Audit calls use a public Application boundary and write exactly once.
5. Provider order is deterministic and module registration fails fast for invalid providers.
6. Historical migration paths and governed menu values are byte-for-byte unchanged.
7. Architecture, focused Identity/Audit tests, full PHPUnit, frontend validation and Browser E2E have no new unexplained failures.
8. Work Package, Work Log, Roadmap, Index, Change Log and current context record exact accepted heads and boundary status.

## Validation

```text
PHP_LINT: all changed PHP files
COMPOSER_VALIDATE: composer validate --strict
ARCHITECTURE: php artisan test --testsuite=Architecture
IDENTITY_FOCUSED: Auth, AuthorizationFoundation, CreateErpUser and Identity tests
AUDIT_FOCUSED: php artisan test tests/Feature/Audit tests/Feature/Auth/AuthAuditTest.php
FULL_PHPUNIT: php artisan test
VITEST: npm test
APPLICATION_TYPECHECK: npm run typecheck
E2E_TYPECHECK: npm run typecheck:e2e
BUILD: npm run build
BROWSER_E2E: npm run test:e2e
DIFF_CHECK: git diff --check
FSCK: git fsck --full
```

Baseline failures must be captured before implementation and compared by test name and error class. New failures are zero; B3-scope pre-existing failures are fixed rather than relabeled.

## Risks and Rollback

- Auth provider recursion or wrapper type mismatch: preserve old symbols as thin delegates and run auth tests before merge.
- Provider order regression: assert the exact provider sequence and boot behavior.
- Audit boundary drift or duplicate writes: use a single public contract and assert one record per auth event.
- Local bypass widening: retain fail-closed environment, host, loopback and active-user checks.
- Factory or relation serialization drift: run model, command and Browser E2E regressions.
- Unexpected route or permission drift: compare route inventory, permission keys and menu baseline.

Rollback is a revert of the B3 implementation squash commit and restoration of the pre-B3 Provider/route/model references. No database rollback is required because this package creates no Migration and changes no schema.

## Project Position

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate -> MVP1 Manual Fulfillment / Tencent Docs Replacement -> MVP1.1 WooCommerce Inbound -> MVP1.2 Single Tracking Outbound -> MVP1.3 Sync Orchestration -> Customer / Lead Pilot -> Product / Media -> WordPress Product and Image Sync -> Content / SEO -> Posts / Pages -> GA4 / GSC / Cloudflare -> Google Docs Publishing -> Full Order Workspace -> Logistics OS -> KOL OS and Social Providers -> Inventory and Procurement -> Accounting / ROI Expansion -> Shopify and Additional Integrations -> Dev UAT and Production Hardening
CURRENT_TRACK_PHASE_MAP: 001A MERGED / PASS -> 001B MERGED / PASS -> 001C MERGED / PASS -> 001D COMPLETE / PASS -> 001E COMPLETE / PASS -> 001F-A COMPLETE / CLOSED -> 001F-B1 COMPLETE / CLOSED -> 001F-B2 COMPLETE / CLOSED -> 001F-B3 IMPLEMENTATION COMPLETE / PASS / MERGED / POST-MERGE CLOSURE IN PROGRESS -> 001F-B4 NEXT / READ-ONLY PREFLIGHT PREPARING -> 001F-B5 PROPOSED / NOT STARTED -> 001F-C PROPOSED -> 001F-D PROPOSED -> 001F-E PROPOSED -> 001F-F PROPOSED -> Global Architecture Gate FAIL
COMPLETED_PHASES: 001A, 001B, 001C, 001D, 001E, 001F-A, 001F-B1, 001F-B2
CURRENT_PHASE: ERP-ARCH-MIGRATION-001F-B3 POST-MERGE CLOSURE
CURRENT_WORK_PACKAGE: ERP-ARCH-MIGRATION-001F-B3
PACKAGE_STATUS: IMPLEMENTATION COMPLETE / PASS / MERGED / POST-MERGE CLOSURE IN PROGRESS
WAITING_PHASES: 001F-B4, 001F-B5, 001F-C, 001F-D, 001F-E, 001F-F pending prior package closure
BASELINE: origin/main@906fea71b1d598de3b38ae89b34a33142cec0982
GITHUB_STATE: PR #28 MERGED; B3 Work Package PR #29 MERGED; B3 Implementation PR #30 MERGED; B3 Closure PR pending creation
SOURCE_CONFLICTS: NONE WITH EVIDENCE; historical NOT AUTHORIZED text is retained as historical governance state
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: B3 post-merge Closure PR review and merge; then B4 read-only Preflight
EVIDENCE_AS_OF: 2026-08-05
```

## Roadmap Hours

```text
CURRENT_PACKAGE_ESTIMATE: B3 5–10h; planning value 7.5h
KNOWN_CURRENT_TRACK_REMAINING: B3–B5 20–40h; planning value 30h, non-overlapping issued estimate
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE
MVP1_TOTAL_ESTIMATE: 122–190h
MVP1_PLANNING_BASELINE: 166h
MVP1_MAXIMUM_ENVELOPE: 192h
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: approximately 68–120h beyond MVP1
LONG_TERM_CUMULATIVE_RANGE: 235–402h architecture foundation; 603–1,018h first highly automated operations MVP; 1,059–1,802h product/SEO growth layer; 1,595–2,714h full core ERP; 1,819–3,146h all expansion
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE
ESTIMATE_CONFIDENCE: MEDIUM for B3 issued range; LOW / UNKNOWN for total remaining
ESTIMATE_AS_OF: 2026-08-05
ESTIMATE_METHOD: B3 preflight and implementation contract uses the frozen B3 5–10h range; no actual hours inferred from chat, commits or elapsed time
```

## Estimated Codex Hours

```text
PREFLIGHT_AND_SCOPE: 1.5h; planning value 1.5h
CORE_EXECUTION: 3.0h; planning value 3.0h
TEST_AND_VALIDATION: 2.0h; planning value 2.0h
REMEDIATION_BUFFER: 1.0h; planning value 1.0h
ESTIMATED_EFFECTIVE_HOURS: 7.5h
ESTIMATE_RANGE: 5–10h
ESTIMATE_BASIS: issued B3 planning range; component values reconcile to 7.5h and cover preflight, Identity migration, validation and review remediation
OWNER_REVIEW: INCLUDED
MERGE: INCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```

IMPLEMENTATION_PR: #30
IMPLEMENTATION_ACCEPTED_HEAD: 90b18665e5f797eb2b8f0cd91d3b3baeb8073595
IMPLEMENTATION_MAIN_SQUASH: 906fea71b1d598de3b38ae89b34a33142cec0982
FINAL_ACCEPTANCE_REVIEW: PASS at 90b18665e5f797eb2b8f0cd91d3b3baeb8073595
FINAL_STATUS: `B3_IMPLEMENTATION_COMPLETE_PASS_MERGED_POST_MERGE_CLOSURE_IN_PROGRESS`
