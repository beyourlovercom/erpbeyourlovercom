---
type: byl-erp-work-package
project: BYL ERP
work_package: ERP-ARCH-MIGRATION-001F-B1
status: current-implemented-pending-independent-review
standard_id: BYL-ERP-ARCH-001
standard_version: 1.1
---

# ERP-ARCH-MIGRATION-001F-B1 — Module Architecture Contract & Decision Freeze

## Scope and Decision

```text
AUTHORIZED_BASE: origin/main@70b492772eedc993e5790d563c9c261cb0b1e0b7
DECISION: D-031 — Adopt A1 Physical Module Contract for Architecture Standard v1.1
RECOMMENDATION: RECOMMEND_OPTION_A1
STATUS: CURRENT / IMPLEMENTED / PENDING INDEPENDENT REVIEW
IMPLEMENTATION: NO APPLICATION OR MODULE CODE
GLOBAL_ARCHITECTURE_GATE: FAIL
MVP1: NOT STARTED / 0%
```

v1.0 required a Laravel modular monolith and layering but did not explicitly require `app/Modules/**`. v1.1 makes that path the forward-effective physical contract. B1 freezes documentation only and creates no directory.

## Target Contract

```text
app/Modules/{Identity,Runtime,Audit,Sync,Shell,Dashboard}/
app/Modules/{Orders,Logistics}/ reserved for MVP1; not created in B1
app/Shared/
```

Identity owns users/authentication/roles/permissions/authorization. Runtime is platform technology. Audit and Sync own their data. Shell and Dashboard are Experience/Application capabilities. Orders and Logistics are reserved. Authorization is not a separate top-level module.

Layers are Experience → Application → Domain; Infrastructure implements approved ports; Providers compose bindings/routes only. Domain is framework-free and cannot depend on Eloquent, transport or module internals. Cross-module calls use public Actions/Contracts or approved Events/Queries. `App\Support` is Legacy Compatibility Namespace, not Shared Kernel; `App\Shared` is restricted to stable framework-free primitives.

Historical migrations remain centrally loaded and unchanged. Existing table ownership is Identity (`users`, sessions, roles, permissions and pivots), Audit (`audit_records`), Sync (`sync_outbox`) and Runtime/Laravel infrastructure (cache/jobs). Compatibility wrappers may remain in old namespaces until B5, without duplicate business rules.

## B2–B5 Sequence

```text
001F-B2 Module Skeleton & Automated Boundary Enforcement: PROPOSED / NOT AUTHORIZED
001F-B3 Identity & Access Boundary Migration: PROPOSED / NOT AUTHORIZED
001F-B4 Runtime / Audit / Sync Boundary Migration: PROPOSED / NOT AUTHORIZED
001F-B5 Shell / Dashboard Boundary & Compatibility Cleanup: PROPOSED / NOT AUTHORIZED
```

B2 must establish the minimal skeleton and boundary tests. B3/B4 migrate stable foundations with wrappers. B5 handles Shell/Dashboard routes and wrapper cleanup. No B2–B5 implementation is authorized by B1.

## Provider Future Policy

B2 evaluates `App\Modules\ModulesServiceProvider`, registered only in `bootstrap/providers.php`, and registers only existing module Providers. Providers handle bindings, routes, Policy/Gate, configuration and Infrastructure assembly; they contain no business rules. Existing AppServiceProvider Audit/Gate bindings migrate in stages, with registration order protected by automated tests.

## Route Future Policy

`TARGET_MODULE_ROUTE_PATH: app/Modules/<Module>/Experience/Routes/web.php`. Module Providers load routes while URL, name, middleware and Permission Key remain unchanged. `routes/web.php` remains a compatibility entry. Domain/Infrastructure cannot register routes; Shell legacy dynamic routes are B5 scope; Orders/Logistics routes await MVP1 authorization.

## Frontend Boundary

Frontend directories need not mirror backend modules. Shared UI remains in `resources/js/Components`, Layouts in `resources/js/Layouts`, domain pages in `resources/js/Pages/<Domain>`, Shell/Dashboard are Experience, and frontend must not copy Domain rules. B2 does not automatically refactor frontend.

## Wrapper Removal Contract

Each wrapper records its Canonical Module Class and removal condition, adds no business rule, preserves Route/Permission/table/Cast/relation/serialization contracts, and never creates two active implementations writing one table. B5 owns cleanup.

## Architecture Test Contract

B2 must verify Domain bans on Illuminate/Facade/Controller/Request/Route/Inertia/Eloquent/other module internals; Application bans on other module Infrastructure; public Contract/Action/Event/Query-only cross-module calls; Provider rule isolation; registered Support; framework-free Shared; unchanged historical Migration paths; no Orders/Logistics before MVP1; and unchanged Route Name, Permission Key and Menu baseline. PHPStan/Deptrac or other dependencies require separate Owner authorization.

## Verification and Gates

Future architecture tests must reject forbidden Domain/Application dependencies, private cross-module imports, impure Providers, unregistered Support classes, impure Shared classes, route ownership violations, migration path changes, premature Orders/Logistics code and unauthorized route/permission/menu changes. Existing Composer `App\` → `app/` mapping is sufficient by default; no Provider, Route, Composer, frontend or Migration change is part of B1.

```text
001F_B1_DOCUMENTATION_IMPLEMENTATION: GRANTED
MODULAR_BOUNDARY_IMPLEMENTATION: REQUIRED / NOT GRANTED
NAMESPACE_MIGRATION: REQUIRED / NOT GRANTED
COMPOSER_AUTOLOAD_CHANGE: REQUIRED / NOT GRANTED
PROVIDER_CHANGE: REQUIRED / NOT GRANTED
ROUTE_CHANGE: REQUIRED / NOT GRANTED
MIGRATION_PATH_CHANGE: REQUIRED / NOT GRANTED
DATABASE_OPERATION: REQUIRED / NOT GRANTED
001F-C: NOT AUTHORIZED
MVP1_START: NOT AUTHORIZED
UAT: NOT AUTHORIZED
DEPLOYMENT: NOT AUTHORIZED
```

## Estimate and Rollback

```text
B2: 1–2 Codex / 4–8h
B3: 1–2 Codex / 5–10h
B4: 1–2 Codex / 6–12h
B5: 1–2 Codex / 5–10h
B2_TO_B5_TOTAL: 4–8 Codex / 20–40h; planning value 30h
ACTUAL_HOURS: UNKNOWN WITH EVIDENCE
```

Rollback is documentation-only: revert the B1 commit. No application, namespace, provider, route, migration, database or frontend state is changed.

## Project Position

```text
CURRENT_TRACK: ERP Architecture Migration
CURRENT_PHASE: 001F-B1 Module Architecture Contract
NEXT: B2–B5 proposed / not authorized
GLOBAL_GATE: FAIL
MVP1: NOT STARTED / 0%
UAT: NO
DEPLOYMENT: NO
```

FINAL_STATUS: `ERP_ARCH_MIGRATION_001F_B1_IMPLEMENTED_PENDING_INDEPENDENT_REVIEW`
