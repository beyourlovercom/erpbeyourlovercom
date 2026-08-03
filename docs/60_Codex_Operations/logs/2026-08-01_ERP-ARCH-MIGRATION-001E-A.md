---
type: byl-erp-work-log
date: 2026-08-01
work_package: ERP-ARCH-MIGRATION-001E-A
status: merged-pass
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.1
---

# ERP-ARCH-MIGRATION-001E-A Preflight and Implementation Log

## Current Accepted Status

```text
STATUS: MERGED / PASS
PR: #17
ACCEPTED_HEAD: e46fb54a1b41a1efa620b4af3646e104ff7bcbbc
MAIN_SQUASH_COMMIT: 442a3a872628764fecb7209049d2daf752624e9a
MERGED_AT: 2026-08-03T04:16:11Z
MIGRATION: NO
UAT: NO
DEPLOYMENT: NO
```

Earlier entries remain the historical evidence as recorded at their original review gates.

## Authorization and Result

```text
WORK_PACKAGE: ERP-ARCH-MIGRATION-001E-A
AUTHORIZATION_TYPE: PREFLIGHT ONLY
AUTHORIZED_GOVERNANCE_HEAD: 6c644136d54f6b9c343b5a377d8ec111b5f5100e
REMEDIATION_AUTHORIZED_HEAD: eb6b4c425551bd60401fa20c85ee139f3d0e4785
RESULT: PREFLIGHT REMEDIATION COMPLETE / PENDING INDEPENDENT REVIEW
IMPLEMENTATION_AUTHORIZED_HEAD: 7cef9e3d8b238d591f42013a43c0b17426ab5ab5
IMPLEMENTATION_BRANCH: feature/erp-arch-migration-001e-a-shared-ui-design-tokens
IMPLEMENTATION_RESULT: 800PX SHELL LAYOUT REMEDIATION COMPLETE / PENDING INDEPENDENT REVIEW
IMPLEMENTATION_AUTHORIZATION: YES / COMPLETE
APPLICATION_CODE_CHANGE: YES / BOUNDED
CSS_CHANGE: YES / BOUNDED
DEPENDENCY_CHANGE: NO
LOCK_FILE_CHANGE: NO
PLAYWRIGHT: NO
WORKFLOW_CHANGE: NO
MIGRATION: NO
UAT: NO
DEPLOYMENT: NO
```

## Evidence Gathered

- inventoried active raw visual values from Login, Foundation and App Shell sources;
- confirmed `resources/css/tokens.css` does not exist and froze it as the only future BYL semantic token source;
- froze exact primitive values, semantic aliases, literal fallbacks and dark-theme overrides;
- calculated WCAG contrast for active text, control boundaries and focus indicators;
- identified existing low-contrast shell metadata, authentication account copy, input boundary and focus states and froze accessible replacements;
- froze exact Props, Slots, Events and native-attribute behavior for `UiButton`, `FormField`, `TextInput`, `UiAlert` and `EmptyState`;
- mapped every active consumer and excluded speculative components and legacy prototype CSS;
- confirmed one-import Tailwind 4/Vite strategy without dependency, lock-file or build-config changes;
- froze component/consumer Vitest coverage and explicitly retained jsdom browser limitations;
- required a bounded Owner visual check before merge authorization, classified separately from UAT;
- confirmed the 001E-A implementation range as 16–24 effective hours, planning value 20h.

## Limited Governance Remediation

```text
BREAKPOINT_TOKEN_CONFLICT: RESOLVED
BREAKPOINT_CLASSIFICATION: STRUCTURAL CSS CONTRACT
SHELL_NARROW_BREAKPOINT: 800px / @media (max-width: 800px)
REMOVED_BREAKPOINT_VARIABLE: --byl-breakpoint-shell-narrow
LOGIN_SEMANTIC_TAILWIND_MAP: FROZEN
FOUNDATION_SEMANTIC_TAILWIND_MAP: FROZEN
LOGIN_LOADING_COPY: PRESERVED
NORMAL_TEXT: 登录系统
LOADING_TEXT: 验证中…
GENERIC_UI_BUTTON_LOADING_FALLBACK: 处理中…
LOGIN_OVERRIDE_REQUIRED: YES
IMPLEMENTATION_ESTIMATE: UNCHANGED / 16–24h / 20h
APPLICATION_CODE_CHANGE: NO
CSS_CHANGE: NO
DEPENDENCY_CHANGE: NO
```

The remediation removed the invalid plan to represent a media-query condition as a CSS custom property. The `800px` shell breakpoint remains a literal structural contract in `resources/css/shell.css`; no custom-media facility, plugin, configuration or dependency is planned. The Login and Foundation maps now remove Tailwind slate/cyan/rose utilities as semantic color facts while retaining Tailwind for structural utilities.

## Implementation Evidence

```text
TOKENS_SOURCE: resources/css/tokens.css / UNIQUE
TOKEN_IMPORT: foundation.css ONLY / AFTER tailwindcss
BREAKPOINT_CONTRACT: @media (max-width: 800px) PRESERVED
BREAKPOINT_VARIABLE: ABSENT
COMPONENTS: UiButton, FormField, TextInput, UiAlert, EmptyState
LOGIN_TOKEN_MIGRATION: COMPLETE
FOUNDATION_TOKEN_MIGRATION: COMPLETE
SHELL_TOKEN_MIGRATION: COMPLETE
LOGIN_LOADING_COPY: 登录系统 → 验证中… / generic 处理中… not rendered
COMPONENT_AND_CONSUMER_TESTS: PASS / 10 files / 58 tests
TYPECHECK: PASS
BUILD: PASS
COMPOSER_VALIDATE: PASS
ROUTE_LIST: PASS / 59 routes
FULL_PHP_TEST_SUITE: PASS / 110 tests / 1006 assertions
SEMANTIC_COLOR_SCAN: PASS
DIFF_CHECK: PASS
CODEX_VISUAL_REMEDIATION_CHECK: PASS / 799px, 800px and 801px
OWNER_VISUAL_CHECK: NOT PERFORMED / REQUIRED BEFORE MERGE AUTHORIZATION
DEPENDENCY_CHANGE: NO
LOCK_FILE_CHANGE: NO
WORKFLOW_CHANGE: NO
PHP_CHANGE: NO
MIGRATION: NO
PR: #17 / OPEN / DRAFT
MERGE: NO
UAT: NO
DEPLOYMENT: NO
```

The implementation added exactly the eleven frozen files, modified the ten frozen application/test files and updated only these two authorized governance records. It did not touch the legacy prototype CSS or views, dependency manifests, build/test configuration, workflows, PHP or database Migration files.

## Component Contract Remediation Evidence

```text
REMEDIATION_AUTHORIZED_HEAD: 5c2902dedf1ab43702ec1c5ad104fd5a9bb71c36
ATTRIBUTE_ALLOWLISTS: UiButton, FormField, TextInput, UiAlert and EmptyState IMPLEMENTED
UNAPPROVED_ATTRIBUTE_FORWARDING: REJECTED BY IMPLEMENTATION AND NEGATIVE TESTS
UI_ALERT_CALLER_ROLE: SAFE COMPATIBILITY CONTRACT IMPLEMENTED
UI_ALERT_ROLE_CASES: off+region; polite default; error default; polite+alert conflict PASS
FORM_FIELD_SLOT_ONLY: description and error presence controls elements, describedBy, invalid and alert role
FOCUSED_COMPONENT_TESTS: PASS / 5 files / 41 tests
FULL_FRONTEND_TESTS: PASS / 10 files / 57 tests
TOKENS_CSS: UNCHANGED
LOGIN: UNCHANGED
FOUNDATION: UNCHANGED
SHELL_CSS: UNCHANGED
TOPBAR: UNCHANGED
PLACEHOLDER: UNCHANGED
DEPENDENCY_CHANGE: NO
LOCK_FILE_CHANGE: NO
WORKFLOW_CHANGE: NO
PHP_CHANGE: NO
MIGRATION: NO
OWNER_VISUAL_CHECK: NOT RUN
PR: NO
MERGE: NO
UAT: NO
DEPLOYMENT: NO
```

## 800px Shell Layout Remediation Evidence

```text
AUTHORIZED_HEAD: 561caa0109d8388c02dd24602464f640b1442eb1
AUTHORIZED_BASE: main@592b9732c09902cb2803f3b62f5686075619849d
VISUAL_DEFECT: .shell-workspace width 0 at max-width 800px
ROOT_CAUSE: fixed Rail and hidden Sidebar left Grid flow while the narrow template retained two leading zero-width columns
REMOVED_RULE: grid-template-columns: 0 0 minmax(0, 1fr)
REPLACEMENT_RULE: grid-template-columns: minmax(0, 1fr)
BREAKPOINT_VALUE: 800px / UNCHANGED
REGRESSION_TEST: AuthenticatedLayout.test.ts reads the real shell.css and enforces one narrow workspace column
FOCUSED_LAYOUT_TESTS: PASS / 1 file / 7 tests
FULL_FRONTEND_TESTS: PASS / 10 files / 58 tests
FULL_PHP_TEST_SUITE: PASS / 110 tests / 1006 assertions
COMPOSER_VALIDATE: PASS
TYPECHECK: PASS
BUILD: PASS
DIFF_CHECK: PASS
800PX: PASS / columns=800px / workspace=800px / horizontal overflow=false
799PX: PASS / columns=799px / workspace=799px / horizontal overflow=false
801PX: PASS / columns=92px 220px 489px / horizontal overflow=false
NAV_CLOSED: PASS / inert=true / aria-hidden=true / drawer translated outside viewport
NAV_OPEN: PASS / inert=false / aria-hidden=false / drawer=220px / Backdrop covers viewport
BROWSER_CONSOLE: PASS / no warning or error
VISUAL_EVIDENCE: repository-external temporary directory / not committed
OWNER_VISUAL_ACCEPTANCE: NOT PERFORMED
DEPENDENCY_CHANGE: NO
LOCK_FILE_CHANGE: NO
WORKFLOW_CHANGE: NO
MIGRATION: NO
DATABASE_MUTATION: NO
UAT: NO
DEPLOYMENT: NO
```

The remediation preserves the literal `800px` breakpoint, desktop Rail and Sidebar dimensions, drawer and Backdrop behavior, and the existing inert/`aria-hidden` contract. It changes no route, permission, authentication, dependency, workflow or database behavior.

## Contrast Result

```text
PASSING BASELINE: primary shell/nav/auth/foundation text and existing foundation action boundary
CURRENT FAILURES: four shell muted-text uses, shell light focus, auth small account note, auth input boundary and translucent auth focus ring
FROZEN CORRECTIONS: #526670 muted text, #277d78 light focus, #94a3b8 dark muted text, #64748b dark control border, opaque #67e8f9 dark focus
CONTRAST_METHOD: WCAG relative luminance from frozen sRGB values; alpha composited over documented backgrounds
BROWSER_RENDERING: UNVERIFIED / OWNER VISUAL CHECK REQUIRED
```

## Frozen Boundary

```text
FUTURE NEW FILES: 11
- resources/css/tokens.css
- five Vue components
- five matching Vitest files

FUTURE MODIFIED FILES: 10
- resources/css/foundation.css
- resources/css/shell.css
- four active Vue consumers
- four matching consumer/regression tests

CURRENT PREFLIGHT CHANGED FILES: 2 GOVERNANCE MARKDOWN FILES ONLY
```

## Architecture Decision

```text
ARCHITECTURE_CONFLICT: NONE WITH EVIDENCE
UNIQUE_TOKEN_SOURCE: resources/css/tokens.css
SECOND_TOKEN_SOURCE: PROHIBITED
ACTIVE_CONSUMER_EVIDENCE: PRESENT FOR ALL FIVE COMPONENTS
DEPENDENCY_GAP: NONE
ACCESSIBILITY_REMEDIATION: REQUIRED WITHIN FROZEN IMPLEMENTATION SCOPE
```

## Validation

```text
GIT_DIFF_CHECK: PASS
GIT_FSCK_FULL: PASS
CHANGED_PATH_BOUNDARY: PASS / FOUR AUTHORIZED REMEDIATION FILES ONLY
APPLICATION_CODE_CHANGE: NO
CSS_CHANGE: YES / BOUNDED TO NARROW GRID PLACEMENT
DEPENDENCY_CHANGE: NO
WORKFLOW_CHANGE: NO
MIGRATION: NO
```

## Verdict

```text
FINAL_STATUS: PR_17_800PX_SHELL_LAYOUT_REMEDIATION_COMPLETE_PENDING_INDEPENDENT_REVIEW
NEXT_GATE: independent implementation review and required Owner visual check before separate merge authorization
```

## PROJECT POSITION

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Manual Fulfillment / Tencent Docs Replacement → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Customer / Lead Pilot → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Dev UAT and Production Hardening
CURRENT_TRACK_PHASE_MAP: 001A MERGED / PASS → 001B MERGED / PASS → 001C MERGED / PASS → 001D COMPLETE / PASS → 001E-A 800PX SHELL LAYOUT REMEDIATION COMPLETE / PENDING INDEPENDENT REVIEW → 001E-B WAITING → 001E-C WAITING → 001F WAITING
COMPLETED_PHASES: 001A, 001B, 001C, 001D-A, 001D-B, 001D-C, 001D
CURRENT_PHASE: 001E-A implementation review gate
CURRENT_WORK_PACKAGE: ERP-ARCH-MIGRATION-001E-A
PACKAGE_STATUS: 800PX SHELL LAYOUT REMEDIATION COMPLETE / PENDING INDEPENDENT REVIEW / MERGE AUTHORIZATION NO
WAITING_PHASES: 001E-A independent review and Owner visual check, 001E-B, 001E-C, 001F
BASELINE: remediation-authorized Head 561caa0109d8388c02dd24602464f640b1442eb1 based on main@592b9732c09902cb2803f3b62f5686075619849d
GITHUB_STATE: PR #17 open / Draft / remediation commit pushed / independent review pending / no merge
SOURCE_CONFLICTS: NONE WITH EVIDENCE; current accessibility failures are recorded as bounded implementation corrections
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: Independent implementation review and required Owner visual check before separate merge authorization
EVIDENCE_AS_OF: 2026-08-02; bounded diff, automated regression, typecheck, build and static scans
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: 20h planning value; confirmed 16–24h implementation range after Preflight
KNOWN_CURRENT_TRACK_REMAINING: 40–62h parent 001E planning range; 001E-B/C estimates require their own Preflights
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE; 001E-B/C and 001F are incomplete
MVP1_TOTAL_ESTIMATE: 122–190h total estimate
MVP1_PLANNING_BASELINE: 166h total planning value
MVP1_MAXIMUM_ENVELOPE: 192h maximum total envelope
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; accepted actual-hours ledger and overlap map are incomplete
POST_MVP_KNOWN_INCREMENT: approximately 68–120h incremental beyond MVP1
LONG_TERM_CUMULATIVE_RANGE: 235–402h through 1,819–3,146h; cumulative non-additive horizons
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; Architecture Gate and project-wide actual ledger are incomplete
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; project-wide accepted actual-hours ledger remains incomplete
ESTIMATE_CONFIDENCE: HIGH for completed bounded 001E-A implementation; LOW for remaining Architecture Gate
ESTIMATE_AS_OF: 2026-08-02
ESTIMATE_METHOD: file-level inventory, exact component contracts, consumer map, test contract and remediation allowance
```

## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: 3–4h; planning value 3.5h
CORE_EXECUTION: 8–13h; planning value 10.5h
TEST_AND_VALIDATION: 3–4h; planning value 3.5h
REMEDIATION_BUFFER: 2–3h; planning value 2.5h
ESTIMATED_EFFECTIVE_HOURS: 20h
ESTIMATE_RANGE: 16–24 effective hours
ESTIMATE_BASIS: confirmed bounded implementation estimate; planning values 3.5 + 10.5 + 3.5 + 2.5 = 20h
ACTUAL_EFFECTIVE_HOURS: 5.2h implementation including component remediation; 7.1h cumulative including 1.9h Preflight and governance remediation
ACTUAL_HOURS_BREAKDOWN: tokens and CSS 1h; five components 1.25h; consumer migration 0.75h; initial component and regression tests 0.75h; initial full validation and governance record 0.75h; attribute/role/slot remediation and validation 0.7h
VARIANCE: -14.8h / -74% against 20h implementation planning value
VARIANCE_REASON: frozen contracts, existing test infrastructure and tightly bounded consumers reduced discovery and integration effort; limited remediation stayed inside existing components; Owner visual review remains excluded and not performed
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```
