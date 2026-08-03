---
type: byl-erp-work-package
project: BYL ERP
work_package: ERP-ARCH-MIGRATION-001E
status: in-progress-001e-b-preflight-complete-pending-review
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.1
---

# ERP-ARCH-MIGRATION-001E — Shared UI / Browser E2E

## Work Package

```text
ID: ERP-ARCH-MIGRATION-001E
TITLE: Shared UI / Browser E2E
STATUS: IN PROGRESS / 001E-A MERGED / 001E-B PREFLIGHT COMPLETE PENDING REVIEW
BASELINE: main@493f32ce7bf1be4368a7e9f97de9ecf9f9c9c9d8
OWNER_IMPLEMENTATION_AUTHORIZATION: NO
DEPENDENCY_CHANGE_AUTHORIZATION: NO
APPLICATION_CODE_CHANGE_AUTHORIZATION: NO
WORKFLOW_CHANGE_AUTHORIZATION: NO
MIGRATION_AUTHORIZATION: NO
UAT_AUTHORIZATION: NO
DEPLOYMENT_AUTHORIZATION: NO
```

This package freezes scope and evidence only. It does not authorize implementation or installation.

## Current Accepted Snapshot

```text
ERP-ARCH-MIGRATION-001E-A: MERGED / PASS
PR #17: MERGED
ACCEPTED_HEAD: e46fb54a1b41a1efa620b4af3646e104ff7bcbbc
MAIN_SQUASH_COMMIT: 442a3a872628764fecb7209049d2daf752624e9a

ERP-LOCAL-ADMIN-BYPASS-001: MERGED / PASS
PR #16: MERGED
ACCEPTED_HEAD: 6b63229b7d776104a5736334de639d9b20fb69f3
MAIN_SQUASH_COMMIT: 55ba7320a7046deb04a03abd99053ccd7b7ed11e

ERP-SHELL-ACCOUNT-DOCK-001: MERGED / PASS
PR #15: MERGED
ACCEPTED_HEAD: 0cac340443d47591b7e242ebe051fb4407d0a986
MAIN_SQUASH_COMMIT: 32d7244b9b1c6d1fb0cf8863cb9af694e7acc10f
OWNER_VISUAL_ACCEPTANCE: PASS

ERP-MENU-BASELINE-001: MERGED / PASS
PR #14: MERGED
ACCEPTED_HEAD: 6d5595e879778895105215dc98b1f740521b6f1a
MAIN_SQUASH_COMMIT: 493f32ce7bf1be4368a7e9f97de9ecf9f9c9c9d8

ERP-ARCH-MIGRATION-001E-B: PREFLIGHT COMPLETE / PENDING INDEPENDENT REVIEW / IMPLEMENTATION NOT AUTHORIZED
ERP-ARCH-MIGRATION-001E-C: WAITING
ERP-ARCH-MIGRATION-001F: WAITING
GLOBAL_ARCHITECTURE_GATE: FAIL
DEPLOYMENT: NO
```

The earlier preparation inventory below is retained as historical evidence. The accepted post-merge state above and the independent [[ERP-ARCH-MIGRATION-001E-B]] package are authoritative for current execution.

## Purpose and Architecture Position

001E follows the completed Runtime, Audit and Sync foundations. It prepares a reusable accessible UI layer and real-browser regression boundary before 001F performs the Architecture re-audit. It must strengthen the existing authenticated App Shell without implementing business modules or creating a second design truth.

```text
001D COMPLETE / PASS
→ 001E-A Shared UI and Design Tokens
→ 001E-B Browser E2E Foundation
→ 001E-C Authentication / Permission / App Shell E2E
→ 001F Architecture Re-audit
```

## Frozen Subpackage Split

### ERP-ARCH-MIGRATION-001E-A — Shared UI and Design Tokens

Status: MERGED / PASS by PR #17.

Planned scope:

- one documented token layer for typography, spacing, colors, borders, radius, shadow, focus, density and motion;
- exact first-wave primitives with current consumers: UiButton, FormField, TextInput, UiAlert and EmptyState;
- component-level Vitest contracts for props, events, slots, disabled/error states and keyboard semantics;
- bounded adoption by authentication and App Shell surfaces only after explicit authorization;
- preservation of server authorization and existing Inertia routing contracts.

Accepted implementation estimate: 16–24 effective hours; planning value 20 hours. Accepted actual evidence is retained in the 001E-A Work Package and Work Log.

### ERP-ARCH-MIGRATION-001E-B — Browser E2E Foundation

Status: PREFLIGHT COMPLETE / PENDING INDEPENDENT REVIEW / IMPLEMENTATION NOT AUTHORIZED. Dependency, lock-file, browser-install, Workflow, disposable-E2E-Migration and implementation gates remain separate and ungranted.

Planned scope:

- approved Playwright dependency and browser installation strategy;
- `playwright.config` and a repository-local `tests/e2e` structure;
- repository-external runtime secrets and an isolated temporary Laravel test instance;
- isolated disposable test database and deterministic synthetic users;
- failure artifacts under the authentication-specific upload prohibition and verifiable-redaction gates below;
- local `npm run test:e2e` command;
- separately authorized GitHub Actions design and artifact retention.

Confirmed implementation estimate: 12–18 effective hours; planning value 15 hours. Owner review and merge are excluded.

### ERP-ARCH-MIGRATION-001E-C — Authentication / Permission / App Shell E2E

Status: WAITING. It cannot begin before 001E-B is accepted and a stable isolated environment exists.

Planned scope:

- guest login-page access;
- valid login to `/dashboard`;
- invalid password and inactive-user behavior without state disclosure;
- logout and authentication Session invalidation;
- `dashboard.view`, 403 and route-not-found 404 behavior;
- authenticated App Shell loading;
- primary rail and module-sidebar interactions;
- navigation state after browser refresh where the approved contract requires persistence;
- Modal focus entry/containment/return and Escape behavior after the shared Modal exists;
- basic shared-form validation behavior.

Planned estimate: 12–20 effective hours; planning value 16 hours.

## Explicit Exclusions

- implementation during this preparation package;
- Order, Shipment, Logistics, KOL, Sync UI or any business page;
- external provider/API behavior;
- visual redesign beyond reusable foundations;
- application Migration or persistent database mutation;
- Dev, Staging or Production access;
- UAT, deployment or production browser monitoring;
- ERP-ARCH-MIGRATION-001F;
- claiming that the Global Architecture Gate passed.

## Audited Repository Surface

```text
resources/js/**
resources/css/app.css
resources/css/foundation.css
resources/css/shell.css
resources/views/app.blade.php
resources/views/welcome.blade.php
resources/views/erp/**
tests/Feature/**
package.json
package-lock.json
vite.config.js
vitest.config.ts
tsconfig.json
.github (absent)
```

## CURRENT_SHARED_UI

- `AuthenticatedLayout.vue` composes the current authenticated shell;
- `GlobalNavigation.vue`, `ModuleSidebar.vue` and `TopBar.vue` are App Shell-specific components;
- Login, Dashboard, Placeholder and Foundation pages contain their own presentation markup;
- there is no general-purpose `Components/UI` library and none of the frozen 001E-A primitives exists;
- legacy Blade prototype patterns remain visual reference only and are not approved shared Vue components.

## CURRENT_DESIGN_TOKENS

- Tailwind CSS 4 is loaded through `@import 'tailwindcss'`, exposing framework defaults but no BYL semantic token contract;
- Login and Foundation pages use inline utility colors, spacing, radii, shadows and focus rules;
- `shell.css` contains hard-coded shell colors, sizes, breakpoint, focus outline and transition values;
- `app.css` contains separate legacy prototype button, form, table, card and status styles;
- no repository-owned semantic CSS custom-property layer currently defines typography, spacing, color, radius, shadow, focus, density or motion tokens.

## CURRENT_COMPONENT_INVENTORY

```text
App Shell: AuthenticatedLayout, GlobalNavigation, ModuleSidebar, TopBar
Pages: Login, Dashboard Index, Shell Placeholder, Architecture Foundation
Shared buttons: NONE
Shared form controls: NONE
Shared status/feedback: NONE
Shared overlays: NONE
Shared data-display/table shell: NONE
```

## CURRENT_APP_SHELL

- server-trimmed navigation is rendered by the primary rail and module sidebar;
- desktop sidebar collapse uses `hidden`; mobile navigation uses `inert` and `aria-hidden` when closed;
- toggle controls expose `aria-expanded`; logout remains POST; active routes and narrow-screen backdrop are covered by Vitest;
- the main region is focusable with `tabindex=-1`, but no skip link or explicit route-change focus contract exists;
- mobile navigation has no browser-proven focus containment, Escape close or focus-return contract.

## CURRENT_VITEST_COVERAGE

```text
RUNNER: Vitest 4 / jsdom
FILES: 5
TESTS: 11
COVERED: Login form contract; Foundation interaction; Dashboard/Placeholder rendering; App Shell props, permission-trimmed navigation, active links, collapse/mobile accessibility attributes and POST logout
NOT COVERED: real browser, CSS layout, viewport rendering, browser Session/cookies, network redirects, tab order, focus containment/return, screenshots, traces or videos
```

## CURRENT_BROWSER_E2E

```text
PLAYWRIGHT_PACKAGE: NOT INSTALLED
PLAYWRIGHT_CONFIG: ABSENT
E2E_DIRECTORY: ABSENT
E2E_SCRIPT: ABSENT
BROWSER_BINARIES: NOT MANAGED BY REPOSITORY
CURRENT_EXECUTABLE_BROWSER_E2E: NONE
```

`package-lock.json` contains optional Vitest peer metadata mentioning `@vitest/browser-playwright`; `npm ls` reports no installed top-level Playwright package. That metadata is not an E2E capability and must not be treated as installation evidence.

## DUPLICATED_UI_PATTERNS

- button styling is independently encoded in Login utilities, Foundation utilities, shell icon/logout CSS and legacy `.primary-button`/`.secondary-button` patterns;
- form labels, inputs, selects, checkboxes, error text and focus treatment are independently encoded in Login and legacy prototype CSS;
- status presentation exists as legacy `.status-chip` and card badges without a typed shared status contract;
- table, toolbar, page heading, empty/placeholder and card patterns are CSS/page-specific rather than shared Vue primitives;
- modal-like settings panels and mobile drawer behavior lack one reusable overlay contract;
- repeated hard-coded cyan/teal/slate palettes, radii, shadows and transition durations can drift.

## ACCESSIBILITY_GAPS

- no browser keyboard proof for tab order, hidden navigation, focus restoration or route transitions;
- no shared Dialog/Modal contract for labeling, focus trap, Escape, backdrop and return focus;
- no skip-to-content link despite a focusable main target;
- mobile drawer lacks a browser-validated Escape and focus-return contract;
- repeated controls have no single error, description, required, disabled or invalid-state convention;
- no automated contrast, reduced-motion or zoom/reflow evidence;
- legacy prototype panels are not an accessibility baseline for the Vue application.

## TEST_INFRASTRUCTURE_GAPS

- no real-browser runner, config, fixtures, page objects or WebServer lifecycle;
- no disposable browser-test database lifecycle or deterministic test-user fixture;
- no browser artifact policy, retries or timeout contract;
- no E2E environment-variable validation or secret-redaction guard;
- existing PHP tests prove server authentication/authorization, 403 and selected 404 behavior, but not browser behavior.

## DEPENDENCY_GAPS

- `@playwright/test` is not installed;
- browser binaries and their cache policy are undefined;
- no additional dependency is approved in this package;
- `@axe-core/playwright` may be evaluated later but is not part of the frozen baseline and would require its own dependency decision.

## CI_GAPS

- `.github` is absent and the repository has no GitHub Actions workflow;
- no CI job currently installs browsers, starts Laravel/Vite, provisions a disposable database or uploads E2E artifacts;
- no branch-protection check name, concurrency policy, retry policy or artifact retention exists.

## Playwright Selection Basis

Playwright is the proposed tool for 001E-B because it provides maintained Chromium/Firefox/WebKit automation, isolated browser contexts, first-party WebServer integration, screenshots, traces, video, retries and TypeScript support. It fits the existing Node/Vite/TypeScript toolchain and avoids inventing a custom browser harness.

Selection remains a proposal. The implementation package must verify the then-current compatible version, lock-file delta, Node compatibility and browser cache size before Owner dependency authorization. Cypress, Selenium and Vitest Browser Mode are not selected because the required authentication, multi-page navigation, trace and cross-browser contracts map directly to Playwright's first-party runner; this is not evidence that Playwright is already installed.

## Shared Component Boundary

The independent [[ERP-ARCH-MIGRATION-001E-A]] package is authoritative for the first implementation boundary. Only UiButton, FormField, TextInput, UiAlert and EmptyState are first-wave candidates because each has a named current consumer. Select, Checkbox, StatusBadge, Toast, Modal, Drawer, Dropdown, TableShell, PageHeader and PageToolbar are parent-level future candidates only; they are excluded from 001E-A until a later consumer-driven Preflight.

## Proposed Files

Exact paths require subpackage preflight. Expected changes are bounded to:

```text
001E-A:
resources/css/tokens.css
five component files and five component tests under resources/js/Components/UI/
bounded Login, TopBar, Foundation, Placeholder and shell token adoption defined by ERP-ARCH-MIGRATION-001E-A

001E-B:
package.json
package-lock.json
playwright.config.ts
tests/e2e/support/**
tests/e2e/smoke.spec.ts
.github/workflows/browser-e2e.yml (only with separate Workflow authorization)

001E-C:
tests/e2e/authentication.spec.ts
tests/e2e/authorization.spec.ts
tests/e2e/app-shell.spec.ts
tests/e2e/accessibility.spec.ts
```

No listed path is authorized for modification by this preparation package.

## Dependency Change Proposal

```text
PROPOSED_DEV_DEPENDENCY: @playwright/test
CURRENT_STATUS: NOT INSTALLED
PACKAGE_JSON_CHANGE: REQUIRES OWNER AUTHORIZATION
LOCK_FILE_CHANGE: REQUIRES OWNER AUTHORIZATION
BROWSER_DOWNLOAD: REQUIRES OWNER AUTHORIZATION AND CACHE PLAN
OTHER_RUNTIME_DEPENDENCIES: NONE PROPOSED
```

## Test Database and Test User Strategy

- every E2E run uses a fresh repository-external temporary SQLite file or a disposable isolated MySQL database selected by the separately authorized implementation package;
- Dev, Staging, Production and shared databases are prohibited;
- Migration execution is limited to the disposable E2E database and requires explicit bounded Migration Execution authorization before the first run;
- synthetic users are unique per run and cover active authorized, active unauthorized and inactive states;
- user creation must use an approved test bootstrap boundary, not browser-accessible public registration;
- passwords are generated at runtime, passed through environment/fixtures, never committed and never printed;
- database and users are destroyed after the run even when tests fail;
- tests may not depend on production-like personal data, external APIs or persistent Sessions.

## Screenshot, Trace and Video Policy

```text
SCREENSHOT: only-on-failure
TRACE: on-first-retry or retain-on-failure
VIDEO: retain-on-failure
LOCAL_RETENTION: temporary run directory; cleanable after diagnosis
CI_RETENTION_PROPOSAL: 7 days for failed runs only
SUCCESS_ARTIFACTS: not retained by default
AUTHENTICATION_TRACE_UPLOAD: PROHIBITED until a verifiable sanitization and artifact-scan contract is implemented and independently reviewed
AUTHENTICATION_VIDEO_UPLOAD: PROHIBITED until a verifiable sanitization and artifact-scan contract is implemented and independently reviewed
AUTHENTICATION_SCREENSHOT_UPLOAD: PROHIBITED unless masking, synthetic-data-only proof and pre-upload verification all pass
```

Artifacts must not contain passwords, authorization headers, cookies, Session IDs, CSRF tokens, storage-state files, database credentials or real personal data. Authentication fields are synthetic, but synthetic values do not make Session or credential artifacts safe. Until a repeatable sanitization process and automated artifact scan can prove those fields absent, authentication traces and videos remain local-only, repository-external and are deleted after diagnosis; CI must not upload them. Authentication screenshots remain local-only unless sensitive regions are deterministically masked and the pre-upload scan passes. Playwright storage state must remain repository-external and must never be uploaded.

## CI Design Proposal

Subject to separate Workflow authorization:

1. check out the reviewed commit and install locked Composer/npm dependencies;
2. install the explicitly approved Playwright browser(s) and cache by lock-file key;
3. create an isolated test environment and disposable database;
4. run existing PHP and Vitest gates before browser tests;
5. build assets and start Laravel plus Vite through Playwright WebServer contracts;
6. run E2E with bounded workers, timeout and retry policy;
7. upload only artifact classes that pass the approved scan; never upload authentication traces/videos before the verifiable sanitization gate;
8. always stop servers and remove database, credentials and artifacts not selected for retention.

The first implementation may gate on Chromium only for deterministic foundation proof; Firefox/WebKit expansion requires evidence and Owner scope approval. No CI workflow exists or is modified in this package.

## Accessibility Contract

- all interactive components use correct native elements before ARIA;
- every form control has a programmatic label and associated description/error;
- visible focus is tokenized and never removed without an equivalent;
- disabled, loading, invalid and required states remain perceivable without color alone;
- Dialog/Drawer backgrounds become non-interactive; focus enters, remains contained, returns to the trigger and Escape closes when safe;
- Dropdown supports keyboard operation and correct expanded state;
- navigation hidden states are not focusable;
- route changes define predictable heading/main focus behavior;
- reduced motion is respected; 200% zoom/reflow and keyboard-only paths are tested;
- browser E2E verifies behavior, while component tests retain fast semantic contracts.

## Implementation Order

```text
1. 001E-A and its supporting packages are accepted and merged.
2. Independent review evaluates the frozen 001E-B Preflight.
3. Owner separately authorizes the exact Playwright dependency and package-lock delta.
4. Owner separately authorizes Chromium installation and disposable E2E Migration execution.
5. Owner separately authorizes 001E-B implementation and, if desired, the candidate Workflow file.
6. 001E-B is implemented, independently reviewed and accepted.
7. Owner separately authorizes 001E-C Preflight and implementation.
8. 001E-C is implemented, independently reviewed and accepted.
9. Parent 001E closure is reviewed before any 001F authorization.
```

## Test Plan

### 001E-A

- token values and semantic mappings;
- component render, variants, native attributes, events and slots;
- disabled/loading/error contracts;
- keyboard, focus and ARIA behavior under jsdom where reliable;
- existing Login and App Shell Vitest regression;
- typecheck and build.

### 001E-B

- config validation and missing-secret failure;
- WebServer start/stop behavior;
- disposable database setup/cleanup, including failure cleanup;
- synthetic-user fixture isolation;
- one browser smoke test proving Laravel, Vite and Inertia integration;
- screenshot/trace/video retention and secret-exclusion contract;
- local and separately authorized CI execution parity.

### 001E-C

- guest, valid, invalid and inactive authentication paths;
- logout and old Session invalidation;
- dashboard permission, 403 and 404 boundaries;
- App Shell, active navigation, rail/sidebar/mobile interaction and refresh state;
- Modal/Drawer keyboard and focus behavior after those primitives exist;
- shared form validation and error announcement;
- no external network requests or business workflow behavior.

## Rollback Plan

- 001E-A rollback removes only new shared primitives/tokens and reverts bounded consumers to the accepted 001C App Shell behavior;
- 001E-B rollback removes the Playwright dependency/lock delta, config, E2E support files and separately authorized Workflow without touching application data;
- disposable test databases and generated artifacts are deleted independently of Git rollback;
- 001E-C tests can be reverted without weakening existing PHP/Vitest gates;
- no schema or persistent data rollback belongs to this package;
- each subpackage is independently committed and reviewed so rollback never requires reverting accepted 001D foundations.

## Risks

- tokenizing legacy CSS too broadly could accidentally treat prototype pages as production design truth;
- component abstraction before real usage could create inflexible APIs;
- overlay focus behavior is difficult to prove in jsdom and requires real-browser validation;
- browser test flakiness can hide real regressions if retries are unbounded;
- test setup could contact a shared database if environment gates are weak;
- screenshots/traces can leak credentials or Session state without strict artifact handling;
- absent CI means local-only E2E would not protect merges;
- browser download size and cross-browser matrix can increase CI time;
- implementation must preserve server-side authorization as the real security boundary.

## Acceptance Criteria

- this preparation commit changes governance Markdown only;
- the current UI, token, test, browser, dependency and CI evidence is accurately recorded;
- 001E-A/B/C have ordered, non-overlapping scopes and separate Owner gates;
- 001E-A remains Preflight-required and its child implementation estimate remains unconfirmed;
- authentication Trace/Video upload remains prohibited until verifiable sanitization and scanning are independently accepted;
- Playwright is proposed but not installed;
- no package/lock, Workflow, application, CSS, Migration, UAT or deployment change occurs;
- Global Architecture Gate remains FAIL and 001F remains WAITING;
- Work Package receives independent review before implementation authorization.

## Owner Gates

```text
001E_PARENT_SCOPE_ACCEPTANCE: REQUIRED
001E_A_PREFLIGHT_AUTHORIZATION: REQUIRED
001E_A_IMPLEMENTATION_AUTHORIZATION: REQUIRED
001E_B_DEPENDENCY_CHANGE_AUTHORIZATION: REQUIRED
001E_B_IMPLEMENTATION_AUTHORIZATION: REQUIRED
ISOLATED_E2E_MIGRATION_EXECUTION_AUTHORIZATION: REQUIRED
GITHUB_ACTIONS_WORKFLOW_AUTHORIZATION: REQUIRED
001E_C_IMPLEMENTATION_AUTHORIZATION: REQUIRED
001E_MERGE_AUTHORIZATION: SUBPACKAGE-SPECIFIC
UAT_AUTHORIZATION: NOT INCLUDED
DEPLOYMENT_AUTHORIZATION: NOT INCLUDED
001F_AUTHORIZATION: NOT INCLUDED
```

## Parent Planning Estimate

```text
001E-A PARENT PLANNING ALLOCATION: 16–24h; planning value 20h; implementation estimate requires 001E-A Preflight confirmation
001E-B PARENT PLANNING ALLOCATION: 12–18h; planning value 15h; implementation estimate requires 001E-B Preflight confirmation
001E-C PARENT PLANNING ALLOCATION: 12–20h; planning value 16h; implementation estimate requires 001E-C Preflight confirmation
TOTAL_RANGE: 40–62 effective hours
TOTAL_PLANNING_VALUE: 51 effective hours
CLASSIFICATION: parent-level planning estimate only; not an authorized or frozen child implementation estimate
```

## Preparation Verdict

```text
FINAL_PREPARATION_VERDICT: ERP_ARCH_MIGRATION_001E_A_CLOSED_001E_B_PREFLIGHT_COMPLETE_PENDING_INDEPENDENT_REVIEW
```

## PROJECT POSITION

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Manual Fulfillment / Tencent Docs Replacement → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Customer / Lead Pilot → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Dev UAT and Production Hardening
CURRENT_TRACK_PHASE_MAP: 001A MERGED / PASS → 001B MERGED / PASS → 001C MERGED / PASS → 001D COMPLETE / PASS → 001E-A MERGED / PASS → 001E-B PREFLIGHT COMPLETE / PENDING REVIEW / IMPLEMENTATION NOT AUTHORIZED → 001E-C WAITING → 001F WAITING
COMPLETED_PHASES: 001A, 001B, 001C, 001D-A, 001D-B, 001D-C, 001D, 001E-A
CURRENT_PHASE: 001E-B Browser E2E Foundation Preflight review
CURRENT_WORK_PACKAGE: ERP-ARCH-MIGRATION-001E-B
PACKAGE_STATUS: PREFLIGHT COMPLETE / PENDING INDEPENDENT REVIEW / IMPLEMENTATION NOT AUTHORIZED
WAITING_PHASES: 001E-B implementation gates, 001E-C, 001F
BASELINE: main@493f32ce7bf1be4368a7e9f97de9ecf9f9c9c9d8
GITHUB_STATE: PR #14, #15, #16 and #17 MERGED; PR #19 OPEN / DRAFT / PENDING INDEPENDENT REMEDIATION REVIEW
SOURCE_CONFLICTS: NONE WITH EVIDENCE; GitHub and authorized main agree. Browser E2E capability remains absent and no dependency, browser binary or Workflow is authorized.
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: Independent 001E-B Preflight review, then separate Owner decisions for dependency, lock, browser, disposable Migration, Workflow and implementation gates
EVIDENCE_AS_OF: 2026-08-03; authorized main, repository source/config/test inventory, official Playwright documentation and merged PR #14/#15/#16/#17
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: 15h planning value; confirmed 12–18h range for 001E-B implementation and validation; implementation not authorized
KNOWN_CURRENT_TRACK_REMAINING: 24–38h parent allocation for non-overlapping 001E-B/C; planning value 31h; excludes unknown 001F
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE; 001F lacks a frozen estimate and cannot be safely added
MVP1_TOTAL_ESTIMATE: 122–190h total estimate
MVP1_PLANNING_BASELINE: 166h total planning value
MVP1_MAXIMUM_ENVELOPE: 192h maximum total envelope
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; accepted actual-hours ledger and overlap map are incomplete
POST_MVP_KNOWN_INCREMENT: approximately 68–120h incremental beyond MVP1
LONG_TERM_CUMULATIVE_RANGE: 235–402h through 1,819–3,146h; cumulative non-additive horizons
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; cumulative horizons, 001F estimate and project-wide actual ledger are incomplete
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; 001D-A lacks an accepted actual-hours value, preventing a complete architecture ledger
ESTIMATE_CONFIDENCE: MEDIUM for frozen 001E-B implementation boundary; LOW / UNKNOWN for Architecture Gate and program remaining
ESTIMATE_AS_OF: 2026-08-03
ESTIMATE_METHOD: repository/toolchain inventory, exact file delta, lifecycle/security contracts and non-overlapping parent allocation
```

## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: 1–2h; planning value 1.5h
CORE_EXECUTION: 5–7h; planning value 6h
TEST_AND_VALIDATION: 4–6h; planning value 5h
REMEDIATION_BUFFER: 2–3h; planning value 2.5h
ESTIMATED_EFFECTIVE_HOURS: 15h
ESTIMATE_RANGE: 12–18 effective hours
ESTIMATE_BASIS: confirmed 001E-B child estimate; planning values 1.5 + 6 + 5 + 2.5 = 15h; no implementation authorized
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```
