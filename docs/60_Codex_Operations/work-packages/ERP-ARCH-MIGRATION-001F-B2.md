---
type: byl-erp-work-package
project: BYL ERP
work_package: ERP-ARCH-MIGRATION-001F-B2
status: implemented-pass-merged-post-merge-closure-in-progress
standard_id: BYL-ERP-ARCH-001
standard_version: 1.1
---

# ERP-ARCH-MIGRATION-001F-B2 — Module Skeleton & Automated Boundary Enforcement

## Historical PR #26 Approved Implementation Contract

HISTORICAL PRE-MERGE STATE — BEGIN

## Scope and Authorization

```text
AUTHORIZED_BASE: origin/main@9ed96acdd2e78ba49c5f57f469e4046fb2074cab
ARCHITECTURE_STANDARD: BYL-ERP-ARCH-001 v1.1
DECISION: D-031 remains unchanged
RECOMMENDATION: RECOMMEND_B2_S2
HISTORICAL PRE-MERGE STATE:

STATUS: FORMALIZED / APPROVED / IMPLEMENTATION NOT STARTED
B2_WORK_PACKAGE_PR: #26
HISTORICAL PRE-MERGE STATE — IMPLEMENTATION_AUTHORIZATION: NOT GRANTED
SOURCE: Codex completed read-only B2 Preflight
IMPLEMENTATION_AGENT: Kimi Orchestrator
INTENDED_BUILDER: xshoow-builder / openai-api/gpt-5.6-luna
BUILDER_TASK_RESULT: UNAVAILABLE
GLOBAL_ARCHITECTURE_GATE: FAIL
MVP1: NOT STARTED / 0%
```

This Work Package formalizes the completed B2 read-only Preflight. It authorizes documentation of the implementation contract only. It does not authorize application code, module directories, Provider registration, architecture tests, dependency changes, migrations, database access, UAT or deployment.

## Approved Skeleton Option

`RECOMMEND_B2_S2` is the approved minimal skeleton contract.

### Empty Directory Policy

- Do not create empty directories.
- Do not use `.gitkeep` to simulate modules.
- Do not create module directories without real responsibility.

### Shared Kernel Policy

- B2 does not create `app/Shared`.
- A later independently authorized package may create it only for real, stable, framework-free shared primitives.

### Candidate Module Policy

B2 does not create empty `Identity`, `Runtime`, `Audit`, `Sync`, `Shell` or `Dashboard` directories. B3 through B5 create directories incrementally only when real responsibilities are implemented.

## Provider Contract

### ModulesServiceProvider

Implementation is expected at `app/Modules/ModulesServiceProvider.php` in a separately authorized implementation phase.

- Extend `Illuminate\Support\ServiceProvider` directly.
- `register()` explicitly registers Providers for real existing modules.
- `boot()` remains empty or performs only non-business startup checks.
- Do not migrate existing `AppServiceProvider` responsibilities.

### Module Manifest

MODULE_MANIFEST_PATH: `app/Modules/ModuleManifest.php`

B2 implementation may create only `app/Modules/ModuleManifest.php` for the module manifest. It may not substitute another class name, interface, configuration file or registration contract. Any alternative must first amend the formal Work Package and receive separate GitHub review and Owner authorization; the current B2 implementation authorization does not cover such a change.

- Use an explicit static array of registered existing module Providers.
- Preserve a deterministic Provider order.
- Define empty registry behavior.
- Fail fast when a declared Provider class is missing.
- Never scan the filesystem.
- Never use convention-based discovery or dynamic string auto-loading.
- Do not pre-register `Orders`, `Logistics` or nonexistent module Providers.

### Provider Order

The implementation and Architecture Test must freeze this order:

1. `App\Providers\AppServiceProvider`
2. `App\Modules\ModulesServiceProvider`
3. `App\Providers\FortifyServiceProvider`

`AppServiceProvider` must retain the AuditWriter binding, Gate `before`, Permission definitions and Local Admin Bypass Gate logic. B2 does not migrate these responsibilities.

Composer changes are not required. The existing `App\` → `app/` PSR-4 mapping covers `App\Modules`.

## Architecture Test Contract

Implementation is expected to add only these focused tests, subject to the separate implementation authorization:

- `tests/Architecture/ModuleArchitectureTest.php`
- `tests/Architecture/ModuleProviderTest.php`

The strategy is PHPUnit plus `token_get_all`, Reflection and file lists. No independent test helper is added in the first phase; immutable in-test fixtures or temporary in-memory structures are preferred.

### Rule Matrix

| Rule ID | Purpose | Check boundary | Failure condition | Current allowed baseline | Tokenizer range | Unverified range |
|---|---|---|---|---|---|---|
| `MOD-001` | Freeze physical module contract | `app/Modules/**` and reserved modules | Forbidden empty or premature module scope | No module directory in B2 | Files and declared namespaces | Runtime module discovery |
| `DOM-001` | Keep Domain framework-free | Module Domain files | Illuminate, Facade, Controller, Request, Route, Inertia, Eloquent or module-internal dependency | No module Domain files in B2 | Namespace, use, FQN and grouped use | Runtime side effects |
| `APP-001` | Keep Application boundaries public | Module Application files | Private cross-module import or forbidden dependency | No module Application files in B2 | Namespace and imports | Container-resolved runtime calls |
| `INF-001` | Isolate Infrastructure | Module Infrastructure files | Application or Domain owns Infrastructure dependency | No module Infrastructure files in B2 | Namespace and imports | Dynamic runtime wiring |
| `PROV-001` | Isolate Provider composition | Module Providers and manifest | Business rule, filesystem scan, convention discovery or unstable order | No module Provider in B2 | Provider declarations and file paths | Dynamic Provider behavior |
| `SHARED-001` | Protect Shared Kernel purity | `app/Shared/**` | Framework, persistence, transport or module-specific content | `app/Shared` absent in B2 | File and token declarations | Runtime behavior |
| `SUPPORT-001` | Freeze Legacy Support baseline | Existing `app/Support/**` | New unregistered Support class | Existing authorized Support list only | File and namespace list | External runtime callers |
| `MIG-001` | Preserve migration paths | `database/migrations/**` | Added, moved, renamed or changed migration | Existing authorized path list | Git tree and paths | Database schema state |
| `COMPAT-001` | Preserve compatibility contracts | Existing wrappers and public contracts | Namespace, route, permission, cast, relation or serialization drift | Existing code unchanged in B2 | File and token evidence | Runtime behavior |
| `FE-001` | Keep frontend outside B2 | `resources/js/**` | Frontend change or copied Domain rules | Existing frontend unchanged | File tree and token evidence | Browser behavior |

### Tokenizer Requirements

The implementation must detect namespaces, `use` statements, fully qualified names, group use statements, attributes, file paths and line numbers. It must ignore comments and ordinary strings containing pseudo-namespaces or pseudo-class names. Reports must include Rule ID, file path, line number and forbidden dependency or symbol.

Tokenizer results cannot reliably prove dynamic string parsing, Container runtime resolution, dynamic Provider behavior or reflection-external side effects. These results must be marked `UNVERIFIED`, never `PASS`.

## Frozen Baselines

### Legacy Support

- Freeze the existing `app/Support` file list at the authorized Base.
- Do not require immediate cleanup of historical Support classes.
- Reject newly added unregistered Support classes.
- Review any baseline addition or change separately.

### Migrations

- Freeze the existing migration path list at `origin/main@9ed96acdd2e78ba49c5f57f469e4046fb2074cab`.
- Do not modify, move, rename or add migrations.
- Architecture Tests compare paths and Git tree read-only.
- Do not connect to a database.

### Routes, Permissions and Menu

- Freeze current Route Names.
- Freeze current Permission Keys.
- Freeze the Owner-approved level-1 and level-2 menu baseline.
- Do not connect to a database.
- B2 does not modify Routes, Permissions or Menu.

## Proposed Implementation Boundary

The separately authorized B2 implementation may create only:

- `app/Modules/ModulesServiceProvider.php`
- `app/Modules/ModuleManifest.php`
- `tests/Architecture/ModuleArchitectureTest.php`
- `tests/Architecture/ModuleProviderTest.php`

It may modify `bootstrap/providers.php` only to add the one approved Provider, and `phpunit.xml` only after evidence shows existing PHPUnit discovery cannot find `tests/Architecture`. `composer.json`, `composer.lock`, `app/Providers/AppServiceProvider.php`, `app/Providers/FortifyServiceProvider.php`, Routes, migrations, frontend, menu and workflows remain unchanged.

## Explicit Non-Scope

B2 does not move Identity, Runtime, Audit, Sync, Shell or Dashboard responsibilities; create wrappers; change namespaces, models, routes, permissions, menus or migrations; access a database; change frontend, dependencies or workflows; start Orders or Logistics; start B3, B4, B5, 001F-C, MVP1, UAT or deployment.

## Validation Matrix

```text
PHP_SYNTAX: REQUIRED
COMPOSER_VALIDATE: REQUIRED / READ-ONLY
ARCHITECTURE_FOCUSED_TESTS: REQUIRED
FULL_PHP_TEST_SUITE: REQUIRED
VITEST: NOT REQUIRED / FRONTEND UNCHANGED
TYPECHECK: NOT REQUIRED / FRONTEND UNCHANGED
BUILD: NOT REQUIRED / FRONTEND UNCHANGED
BROWSER_E2E: NOT REQUIRED / FRONTEND AND AUTH UNCHANGED
ROUTE_LIST: OPTIONAL / READ-ONLY REGRESSION
PERMISSION_BASELINE: REQUIRED / READ-ONLY CONTRACT
MENU_BASELINE: REQUIRED
MIGRATION_PATH_BASELINE: REQUIRED
DATABASE: PROHIBITED
REDIS: PROHIBITED
QUEUE: PROHIBITED
SCHEDULER: PROHIBITED
MIGRATION_EXECUTION: PROHIBITED
```

## Future Implementation Acceptance Criteria

B2 implementation may be marked PASS only when all of the following are evidenced: the exact module-root contract exists; `ModulesServiceProvider` and `app/Modules/ModuleManifest.php` exist; only one approved Provider is added to `bootstrap/providers.php`; Provider order is App → Modules → Fortify; existing bindings and Gates remain in place; the manifest is explicit and fail-fast; empty registration works; Architecture Tests pass; no third-party architecture dependency, namespace, route, permission, menu, migration, database, frontend or Orders/Logistics business implementation changes occur; Global Architecture Gate remains FAIL; MVP1 remains NOT STARTED / 0%; and B3 remains NOT AUTHORIZED after B2 completion and requires separate future Owner authorization.

## Risks

| Risk | Probability | Impact | Mitigation | Blocks implementation or review |
|---|---|---|---|---|
| Empty Provider has insufficient practical value | Medium | Medium | Keep scope to explicit registration and fail-fast contract; review value before implementation | Review only |
| Provider registration order regresses | Medium | High | Freeze App → Modules → Fortify and add focused Architecture Test | Implementation |
| Architecture Test false positives | Medium | Medium | Use token boundaries, fixtures and reviewed exceptions | Review |
| Architecture Test false negatives | Medium | High | Report tokenizer limits as `UNVERIFIED`; supplement with Reflection and file checks | Review |
| Dynamic strings and Container parsing evade tokenizer | High | High | Mark runtime behavior `UNVERIFIED`; do not claim PASS | Review |
| Snapshot masks newly added violations | Medium | High | Compare explicit current file lists and reject unregistered additions | Implementation |
| Standard and test rules drift | Medium | High | Keep Rule IDs and contract in this Work Package; require separate change review | Review |
| Empty directory or `.gitkeep` pseudo-module | Medium | Medium | Enforce empty-directory policy and file-tree checks | Implementation |
| B2 expands into B3/B4/B5 | Medium | High | Keep implementation boundary and authorization gates explicit | Implementation |
| Orders or Logistics enter manifest early | Low | High | Reject absent/non-approved Providers and reserve business scope | Implementation |
| App\Support restriction affects maintenance | Medium | Medium | Freeze current baseline and review additions separately | Review |
| Laravel upgrade changes token or Provider behavior | Medium | High | Pin evidence to current baseline and rerun focused tests after upgrades | Review |
| Single-maintainer complexity increases | Medium | Medium | Keep explicit static registry and minimal test surface | Review |

## Rollback

B2 code implementation rollback is limited to reverting the B2 implementation Commit or Squash Commit, restoring the original `bootstrap/providers.php` order, and deleting B2-created ModuleManifest, ModulesServiceProvider and Architecture Test files. No database change, Down Migration, Redis cleanup, history rewrite or force-push main is permitted.

## Estimates

```text
B2_ESTIMATED_CODEX: 1–2
B2_ESTIMATED_HOURS: 4–8h
B2_TO_B5_ESTIMATED_CODEX: 4–8
B2_TO_B5_ESTIMATED_HOURS: 20–40h
B2_TO_B5_PLANNING_VALUE: 30h
ACTUAL_HOURS: UNKNOWN WITH EVIDENCE
```

## Owner Gates

```text
001F_B2_READ_ONLY_PREFLIGHT: COMPLETE
001F_B2_WORK_PACKAGE_FORMALIZATION: GRANTED
001F_B2_IMPLEMENTATION: NOT GRANTED
APP_MODULES_CREATION: NOT GRANTED
APP_SHARED_CREATION: NOT GRANTED
MODULES_SERVICE_PROVIDER_CREATION: NOT GRANTED
MODULE_MANIFEST_CREATION: NOT GRANTED
BOOTSTRAP_PROVIDER_CHANGE: NOT GRANTED
ARCHITECTURE_TEST_CREATION: NOT GRANTED
PHPUNIT_XML_CHANGE: NOT GRANTED
THIRD_PARTY_ARCHITECTURE_DEPENDENCY: NOT GRANTED
NAMESPACE_MIGRATION: NOT GRANTED
EXISTING_PROVIDER_MIGRATION: NOT GRANTED
ROUTE_CHANGE: NOT GRANTED
MIGRATION_CHANGE: NOT GRANTED
DATABASE_OPERATION: NOT GRANTED
001F_B3: NOT AUTHORIZED
001F_C: NOT AUTHORIZED
MVP1: NOT AUTHORIZED
UAT: NOT AUTHORIZED
DEPLOYMENT: NOT AUTHORIZED
```

HISTORICAL PRE-MERGE STATE — END

## Project Position

```text
CURRENT_TRACK: ERP Architecture Migration
CURRENT_PHASE: ERP-ARCH-MIGRATION-001F-B2 POST-MERGE CLOSURE — CLOSED
CURRENT_WORK_PACKAGE: ERP-ARCH-MIGRATION-001F-B2
PACKAGE_STATUS: COMPLETE / PASS / CLOSED
BASELINE: origin/main@b2bc2d6e25699bc93b6ef352bc20f593c1cacd56
GITHUB_STATE: PR #27 MERGED; closure PR #28 MERGED / PASS / CLOSED
SOURCE_CONFLICTS: NONE WITH EVIDENCE
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: ERP-ARCH-MIGRATION-001F-B3 Read-only Preflight / Work Package.
EVIDENCE_AS_OF: 2026-08-05
```

## Roadmap Hours

```text
CURRENT_PACKAGE_ESTIMATE: B2 implementation 1–2 Codex / 4–8h; planning value included in B2–B5 30h
KNOWN_CURRENT_TRACK_REMAINING: B2–B5 4–8 Codex / 20–40h; planning value 30h
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE
MVP1_TOTAL_ESTIMATE: 122–190h
MVP1_PLANNING_BASELINE: 166h
MVP1_MAXIMUM_ENVELOPE: 192h
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: approximately 68–120h beyond MVP1
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE
ESTIMATE_CONFIDENCE: LOW / UNKNOWN for remaining totals
ESTIMATE_AS_OF: 2026-08-04
ESTIMATE_METHOD: non-overlapping package estimates; no fabricated actuals; formalization is documentation-only
```

## Estimated Codex Hours

```text
PREFLIGHT_AND_SCOPE: 0.25h; planning value 0.25h
CORE_EXECUTION: 0.5h; planning value 0.5h
TEST_AND_VALIDATION: 0.25h; planning value 0.25h
REMEDIATION_BUFFER: 0.0h; planning value 0.0h
ESTIMATED_EFFECTIVE_HOURS: 1.0h
ESTIMATE_RANGE: 0.75–1.25h
ESTIMATE_BASIS: docs-only B2 formalization; preflight already completed; no implementation, dependency, migration or database work
OWNER_REVIEW: INCLUDED
MERGE: INCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```

## Implementation Completion and Post-Merge Closure

```text
B2_AUTHORIZED_BASE: 7e1f0a7dbaa2b686be552c3683680121fa6f09c5
B2_WORK_PACKAGE_PR: #26
B2_IMPLEMENTATION_PR: #27
B2_ACCEPTED_HEAD: fe6512f655944e7668c1786899f06880af049f29
B2_FINAL_ACCEPTANCE_REVIEW: PASS
FOCUSED_ARCHITECTURE_TESTS: 16 tests / 171 assertions / PASS
BASELINE_FULL_PHPUNIT: 134 tests / 998 assertions / 28 failures / 1 error
B2_FULL_PHPUNIT: 150 tests / 1169 assertions / 28 failures / 1 error
NEW_FAILURES: 0
NEW_ERRORS: 0
FULL_SUITE_NO_NEW_REGRESSION: PASS
MERGE_METHOD: SQUASH
B2_MAIN_SQUASH_COMMIT: 6df005f8e0e59c877d95fcf41fb5c507c507378f
B2_MERGED_AT: 2026-08-04T13:24:22Z
SOURCE_BRANCH_DELETED: NO
UAT: NO
DEPLOYMENT: NO
POST_MERGE_CLOSURE: PR #28 MERGED / PASS / CLOSED
POST_MERGE_CLOSURE_SQUASH: b2bc2d6e25699bc93b6ef352bc20f593c1cacd56
```

The B2 implementation and docs-only post-merge closure are `COMPLETE / PASS / CLOSED`. B3 is the next active package under the continuous execution authorization.

FINAL_STATUS: `B2_COMPLETE_PASS_CLOSED`
