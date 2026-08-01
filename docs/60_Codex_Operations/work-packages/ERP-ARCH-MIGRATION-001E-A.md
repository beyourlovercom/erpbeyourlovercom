---
type: byl-erp-work-package
project: BYL ERP
work_package: ERP-ARCH-MIGRATION-001E-A
status: preflight-required-implementation-not-ready
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.1
---

# ERP-ARCH-MIGRATION-001E-A — Shared UI and Design Tokens

## Work Package

```text
ID: ERP-ARCH-MIGRATION-001E-A
TITLE: Shared UI and Design Tokens
STATUS: PREFLIGHT REQUIRED / IMPLEMENTATION NOT READY
BASELINE: main@548d53f1eae2ec14eea7261d3a101c1f2fda0dcc
PARENT_PACKAGE: ERP-ARCH-MIGRATION-001E
PREFLIGHT_AUTHORIZATION: NO
IMPLEMENTATION_AUTHORIZATION: NO
APPLICATION_CODE_CHANGE: NO
CSS_CHANGE: NO
DEPENDENCY_CHANGE: NO
MIGRATION: NO
UAT: NO
DEPLOYMENT: NO
```

This preparation package freezes the Preflight questions and maximum candidate boundary. It does not certify implementation readiness and authorizes no repository mutation beyond governance Markdown.

## Purpose

Establish one semantic BYL UI token source and a minimal shared Vue component set proven by current authentication and App Shell consumers. The package must reduce active duplication without converting old Blade prototypes into design truth or building speculative components without a real consumer.

## Unique Token Source Contract

```text
PROPOSED_UNIQUE_SEMANTIC_TOKEN_SOURCE: resources/css/tokens.css
CURRENT_STATUS: FILE DOES NOT EXIST / IMPLEMENTATION NOT AUTHORIZED
TAILWIND_ROLE: utility/compiler mechanism; not the BYL semantic fact source
SHELL_CSS_ROLE: consumer of tokens; may not own duplicated semantic values after migration
COMPONENT_SCOPED_CSS_ROLE: consumer of tokens; may define layout mechanics but not duplicate semantic color/spacing/focus facts
LEGACY_APP_CSS_ROLE: prototype reference only; excluded from token migration and not a production design source
INLINE_UTILITY_ROLE: permitted for structural layout only after migration; semantic visual choices must resolve through approved tokens
```

The Preflight must inventory current raw values and propose exact token names and values before implementation. The single source must cover:

- typography families, sizes, weights and line heights;
- spacing scale and density aliases;
- semantic canvas, surface, text, muted, border, accent, success, warning, danger and focus colors;
- border widths;
- radii;
- shadows/elevation;
- focus ring width, color and offset;
- motion duration/easing and reduced-motion behavior.

No second JavaScript/TypeScript token object, Tailwind-only duplicate map or component-local semantic palette may become an equal fact source.

## Exact Component Scope

Only these five components are candidates for the first authorized 001E-A implementation:

```text
UiButton
FormField
TextInput
UiAlert
EmptyState
```

Frozen contract:

- `UiButton`: native button by default; explicit link mode only when semantically required; type, disabled, loading, size, tone, focus and event forwarding;
- `FormField`: programmatic label, optional description, required marker and associated error IDs;
- `TextInput`: forwarded native input attributes, stable ID, value/update contract, invalid state and optional trailing-control slot;
- `UiAlert`: finite informational/success/warning/error tones, appropriate role/live behavior and dismiss event only when configured;
- `EmptyState`: heading, description and optional action slot without inventing business copy.

The following parent candidates are excluded from 001E-A implementation because no approved current Vue consumer requires them:

```text
Select
Checkbox
StatusBadge
Toast
Modal
Drawer
Dropdown
TableShell
PageHeader
PageToolbar
```

They require a later consumer-driven Preflight and cannot be added opportunistically.

## Actual Consumer Map

```text
resources/js/Pages/Auth/Login.vue
  UiButton: login submit and password visibility control
  FormField + TextInput: email and password
  UiAlert: generic server validation error

resources/js/Components/AppShell/TopBar.vue
  UiButton: mobile navigation toggle, module-sidebar toggle and POST logout control

resources/js/Pages/Architecture/Foundation.vue
  UiButton: foundation acknowledgement action

resources/js/Pages/Shell/Placeholder.vue
  EmptyState: authorized module-not-implemented state

resources/css/shell.css
  tokens.css consumer for existing App Shell surfaces, borders, focus and motion
```

`Dashboard/Index.vue`, `AuthenticatedLayout.vue`, `GlobalNavigation.vue` and `ModuleSidebar.vue` remain regression consumers of the shared token/shell behavior but require no markup change unless Preflight produces evidence that a listed contract cannot be met otherwise.

## Candidate File Boundary

The Preflight must confirm or shrink this boundary. It may not expand it without a new Owner gate.

```text
NEW CANDIDATES:
resources/css/tokens.css
resources/js/Components/UI/UiButton.vue
resources/js/Components/UI/UiButton.test.ts
resources/js/Components/UI/FormField.vue
resources/js/Components/UI/FormField.test.ts
resources/js/Components/UI/TextInput.vue
resources/js/Components/UI/TextInput.test.ts
resources/js/Components/UI/UiAlert.vue
resources/js/Components/UI/UiAlert.test.ts
resources/js/Components/UI/EmptyState.vue
resources/js/Components/UI/EmptyState.test.ts

MODIFIED CANDIDATES:
resources/css/foundation.css
resources/css/shell.css
resources/js/Pages/Auth/Login.vue
resources/js/Pages/Auth/Login.test.ts
resources/js/Components/AppShell/TopBar.vue
resources/js/Layouts/AuthenticatedLayout.test.ts
resources/js/Pages/Architecture/Foundation.vue
resources/js/Pages/Architecture/Foundation.test.ts
resources/js/Pages/Shell/Placeholder.vue
resources/js/Pages/Shell/Placeholder.test.ts
```

Explicitly excluded:

```text
resources/css/app.css
resources/views/erp/**
resources/views/welcome.blade.php
package.json
package-lock.json
vite.config.js
vitest.config.ts
tsconfig.json
.github/**
PHP application files
Migration files
business pages
```

## Preflight Deliverables

Before implementation authorization, a separate Preflight report must provide:

1. exact raw-value inventory with source file and consumer;
2. exact token names, values, aliases and fallback behavior;
3. contrast evidence for text, controls and focus states;
4. exact component APIs, slots, events and native-attribute forwarding;
5. confirmed consumer-by-consumer migration diff;
6. confirmed changed/new file list;
7. CSS import-order and Vite build evidence;
8. exact test list and limitations of jsdom;
9. visual-regression/manual-review need without calling it UAT;
10. confirmed implementation estimate and range;
11. rollback checkpoints;
12. architecture-conflict decision or `NONE WITH EVIDENCE`.

## Migration Strategy

This is a presentation-code migration, not a database Migration.

```text
STEP 1: inventory existing active Vue/App Shell semantic values; leave legacy Blade prototype CSS untouched
STEP 2: add tokens.css with values that preserve accepted visuals; no redesign
STEP 3: import the token source once through the approved foundation CSS entry
STEP 4: implement and test the five shared components independently
STEP 5: migrate Login consumers and preserve exact authentication behavior/copy
STEP 6: migrate TopBar buttons and preserve POST logout, aria-expanded and navigation behavior
STEP 7: migrate Foundation and Placeholder consumers
STEP 8: map shell.css semantic values to tokens without changing layout/navigation contracts
STEP 9: remove active duplicate semantic values only after all affected tests/build checks pass
STEP 10: independently review the bounded diff before merge authorization
```

Each step must leave the branch buildable. No legacy Blade page is converted or modified. Server-side authorization and Inertia route behavior remain unchanged.

## Accessibility Contract

- native semantics precede ARIA;
- controls remain keyboard operable with visible tokenized focus;
- FormField label, description and error IDs remain programmatically connected;
- password reveal remains a named button and never changes submission behavior;
- loading and disabled states remain perceivable and prevent duplicate activation;
- alert live behavior avoids duplicate announcements;
- EmptyState retains a heading and meaningful reading order;
- color is never the sole indicator;
- reduced-motion tokens disable nonessential transitions;
- jsdom limitations are documented and deferred browser behavior is not claimed as PASS.

## Test Contract

### Component tests

- each component renders native semantics and forwards safe attributes;
- every tone/size/state is finite and typed;
- button loading/disabled behavior and events;
- field label/description/error associations;
- input value, IDs, invalid state and trailing control;
- alert role/live/dismiss rules;
- EmptyState heading, description and action slot;
- keyboard behavior that jsdom can reliably prove.

### Consumer regression

- Login retains email/password fields, exact approved provisioning copy, no registration, POST `/login`, processing, errors and robots meta;
- TopBar retains POST logout, toggle events and accurate `aria-expanded`;
- AuthenticatedLayout retains active navigation, hidden-focus contracts and mobile backdrop behavior;
- Foundation acknowledgement remains interactive;
- Placeholder remains an authorized non-business empty state.

### Repository validation

```text
npm run test
npm run typecheck
npm run build
git diff --check
git status --short
```

No Playwright, Browser E2E, PHP application change, Migration, UAT or deployment belongs to 001E-A.

## Rollback Plan

- revert consumer migrations in reverse order while retaining accepted pre-migration behavior;
- remove a shared component only after its final consumer is reverted;
- remove token imports only after shell and page values are restored to the accepted baseline;
- never touch legacy `app.css` or Blade prototype files as part of rollback;
- use ordinary Git reverts at reviewed commit boundaries; no data rollback exists;
- if a component API expands beyond the five frozen components or candidate paths, stop for a new Owner decision.

## Risks

- token names can become a second raw-value layer if aliases are not semantic;
- migrating all legacy CSS would improperly promote prototype pages to production truth;
- speculative components create APIs without consumers;
- scoped CSS and Tailwind utilities can silently bypass the token source;
- jsdom cannot prove real layout, focus visuals, contrast or browser keyboard sequencing;
- broad consumer rewrites could alter accepted authentication or navigation behavior;
- premature implementation estimates may be unreliable before the raw-value and API inventories.

## Estimate Classification

```text
PARENT_001E_A_PLANNING_ALLOCATION: 16–24h; planning value 20h
CLASSIFICATION: parent-level planning allocation only
CONFIRMED_001E_A_IMPLEMENTATION_ESTIMATE: PENDING PREFLIGHT
PREFLIGHT_ESTIMATE: 2–4 effective hours; planning value 3h
IMPLEMENTATION_ESTIMATE_GATE: exact component APIs, token map, file delta and tests must be frozen first
```

The 16–24h range is not authorization and must not be presented as the confirmed child implementation estimate. The Preflight may confirm, narrow or replace it with evidence; any expansion beyond the parent range requires an explicit estimate-impact decision.

## Owner Gates

```text
001E_A_PREFLIGHT_AUTHORIZATION: REQUIRED
001E_A_PREFLIGHT_REVIEW: REQUIRED
001E_A_IMPLEMENTATION_AUTHORIZATION: REQUIRED AFTER PREFLIGHT
APPLICATION_CODE_CHANGE_AUTHORIZATION: REQUIRED AFTER PREFLIGHT
CSS_CHANGE_AUTHORIZATION: REQUIRED AFTER PREFLIGHT
DEPENDENCY_CHANGE: NOT INCLUDED
MIGRATION: NOT INCLUDED
UAT: NOT INCLUDED
DEPLOYMENT: NOT INCLUDED
001E_B: NOT INCLUDED
001F: NOT INCLUDED
```

## Preparation Verdict

```text
FINAL_PREPARATION_VERDICT: ERP_ARCH_MIGRATION_001E_A_PREFLIGHT_REQUIRED_IMPLEMENTATION_NOT_READY
```

## PROJECT POSITION

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Manual Fulfillment / Tencent Docs Replacement → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Customer / Lead Pilot → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Dev UAT and Production Hardening
CURRENT_TRACK_PHASE_MAP: 001A MERGED / PASS → 001B MERGED / PASS → 001C MERGED / PASS → 001D-A MERGED / PASS → 001D-B MERGED / PASS → 001D-C MERGED / PASS → 001D COMPLETE / PASS → 001E-A PREFLIGHT REQUIRED / IMPLEMENTATION NOT READY → 001E-B WAITING → 001E-C WAITING → 001F WAITING
COMPLETED_PHASES: 001A, 001B, 001C, 001D-A, 001D-B, 001D-C, 001D
CURRENT_PHASE: 001E-A Preflight gate
CURRENT_WORK_PACKAGE: ERP-ARCH-MIGRATION-001E-A
PACKAGE_STATUS: PREFLIGHT REQUIRED / IMPLEMENTATION NOT READY / AUTHORIZATION NO
WAITING_PHASES: 001E-A Preflight, 001E-A implementation, 001E-B, 001E-C, 001F
BASELINE: governance branch@3d26f09943daaf3ddf50b69428588027e4440d2e based on main@548d53f1eae2ec14eea7261d3a101c1f2fda0dcc
GITHUB_STATE: governance preparation branch pushed; no PR; no 001E implementation branch or PR
SOURCE_CONFLICTS: NONE WITH EVIDENCE; parent estimate is explicitly planning-only and child implementation estimate remains pending Preflight
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: Independent review of this remediation, then separate Owner authorization for 001E-A Preflight only
EVIDENCE_AS_OF: 2026-08-01; governance Head, current Vue/CSS consumers and repository test/config inventory
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: 3h planning value; 2–4h range for the independent 001E-A Preflight only
KNOWN_CURRENT_TRACK_REMAINING: 40–62h parent 001E planning range; child implementation estimates remain unconfirmed
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE; child Preflights and 001F estimate are incomplete
MVP1_TOTAL_ESTIMATE: 122–190h total estimate
MVP1_PLANNING_BASELINE: 166h total planning value
MVP1_MAXIMUM_ENVELOPE: 192h maximum total envelope
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; accepted actual-hours ledger and overlap map are incomplete
POST_MVP_KNOWN_INCREMENT: approximately 68–120h incremental beyond MVP1
LONG_TERM_CUMULATIVE_RANGE: 235–402h through 1,819–3,146h; cumulative non-additive horizons
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; Architecture Gate and project-wide actual ledger are incomplete
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; 001D-A lacks an accepted actual-hours value
ESTIMATE_CONFIDENCE: MEDIUM for bounded Preflight; LOW for implementation until Preflight evidence exists
ESTIMATE_AS_OF: 2026-08-01
ESTIMATE_METHOD: bounded evidence-gathering deliverables; parent implementation allocation remains classification-only
```

## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: 0.5–1h; planning value 0.75h
CORE_EXECUTION: 0.75–1.5h; planning value 1h
TEST_AND_VALIDATION: 0.5–1h; planning value 0.75h
REMEDIATION_BUFFER: 0.25–0.5h; planning value 0.5h
ESTIMATED_EFFECTIVE_HOURS: 3h
ESTIMATE_RANGE: 2–4 effective hours
ESTIMATE_BASIS: Preflight-only planning values 0.75 + 1 + 0.75 + 0.5 = 3h; lower bounds total 2h; upper bounds total 4h
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```
