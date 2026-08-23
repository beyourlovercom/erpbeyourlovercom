---
type: work-package
project: BYL ERP
work_package_id: ERP-APP-SHELL-NAVIGATION-REMEDIATION-001
title: App Shell / Navigation Remediation
status: OWNER UAT PASS / MERGE GATE
execution_provider: CLAUDE_CODE
design_authority: GPT_WEB
test_provider: CODEX_CLI
baseline: main@02ce87dba5ede14d791b646b7cbe976a12064cae
source_wip_branch: feature/byl-erp-ui-application-shell-001
source_wip_head: 53d2b79c924d3cfe1042a06509f96e45a730ac28
updated: 2026-08-23
---

# ERP-APP-SHELL-NAVIGATION-REMEDIATION-001

## 1. Objective

Reconcile the preserved legacy App Shell WIP with current `main`, then complete one bounded shared-foundation remediation before new Inquiry UI work depends on the Customer workspace.

Primary outcome:

```text
current main shell
        ↓
legacy WIP evidence re-audited
        ↓
one unified expandable/collapsible primary navigation
        ↓
inline active child navigation
        ↓
stable workspace width / responsive behavior
        ↓
MVP1-UAT-DEFECT-001 retest
        ↓
Owner visual UAT
```

This package is a shared App Shell / Navigation foundation package. It is not a menu-taxonomy redesign and does not change business routes, permissions, schema, Orders business logic, Dashboard business logic, Inquiry data models, Email/WhatsApp integration, or deployment.

## 2. Why this is a new Work Package

The historical branch `feature/byl-erp-ui-application-shell-001` was created before the current role/governance model and before `ERP-INQUIRY-M1` became current. It has no formal current-main delivery PR and is based on:

```text
SOURCE_BASE: 75cb7fc822d30fd9ac34799b5971702c069a8de0
SOURCE_HEAD: 53d2b79c924d3cfe1042a06509f96e45a730ac28
SOURCE_COMMIT: wip(shell): preserve local application shell redesign
```

Under `PARALLEL-WORK-EXEC-001` v1.2, materially resumed legacy work becomes a new Work Package with one branch and one Draft delivery PR. The source branch is evidence only and must not be merged directly.

## 3. Current-main re-audit evidence

GPT Web re-audited the exact source WIP against:

```text
CURRENT_MAIN: 02ce87dba5ede14d791b646b7cbe976a12064cae
AUDIT_DATE: 2026-08-23 Asia/Hong_Kong
```

Observed source WIP delta relative to its original base:

```text
12 files changed
207 insertions
102 deletions
```

The 12 source-WIP product/test files are:

```text
resources/css/shell.css
resources/css/tokens.css
resources/js/Components/AppShell/GlobalNavigation.test.ts
resources/js/Components/AppShell/GlobalNavigation.vue
resources/js/Components/AppShell/NavigationItem.vue
resources/js/Components/AppShell/TopBar.vue
resources/js/Layouts/AuthenticatedLayout.test.ts
resources/js/Layouts/AuthenticatedLayout.vue
resources/js/Pages/Dashboard/Index.vue
resources/js/Pages/Orders/Index.vue
resources/js/Pages/Orders/Show.vue
tests/e2e/shell/app-shell.spec.ts
```

### 3.1 Drift and replay result

For those exact source-WIP files, `main` has no product-file delta between `75cb7fc` and current `02ce87d`.

A disposable current-main worktree applied `53d2b79` with `git cherry-pick --no-commit`:

```text
CHERRY_PICK_APPLY = PASS
TEXTUAL_CONFLICTS = 0
```

This proves the old product patch is structurally replayable, but does not authorize wholesale cherry-pick because current parallel PR ownership must still be respected.

### 3.2 Baseline versus replayed test evidence

Current `main@02ce87d` focused Shell tests:

```text
GlobalNavigation.test.ts       5 PASS
AuthenticatedLayout.test.ts    7 PASS
TOTAL                          12 / 12 PASS
```

Current main + replayed WIP:

```text
GlobalNavigation.test.ts       6 PASS
AuthenticatedLayout.test.ts    7 PASS / 1 FAIL
TOTAL                          13 PASS / 1 FAIL
```

Failing test:

```text
restores the collapsed state from local storage
TypeError: Cannot read properties of undefined (reading 'setItem')
AuthenticatedLayout.test.ts
```

The failure occurs in the newly added test before component mount because the current Vitest environment does not provide the assumed `window.localStorage` implementation. The component itself already guards localStorage reads/writes with `try/catch`. GPT diagnosis: this is a WIP test-harness contract failure unless later real-browser evidence proves a runtime persistence defect.

Other replayed-WIP checks:

```text
TYPECHECK = PASS
PRODUCTION_BUILD = PASS
GIT_DIFF_CHECK = PASS
```

## 4. Product classification

```text
LEGACY_WIP_CLASSIFICATION = KEEP_AND_COMPLETE
DIRECT_MERGE_OF_OLD_BRANCH = PROHIBITED
REPLAY_TO_CURRENT_MAIN = REQUIRED
```

Reasons:

- the WIP directly addresses the App Shell structure implicated by `MVP1-UAT-DEFECT-001`;
- it removes the separate rendered Module Sidebar from the authenticated layout and nests active child navigation inside the primary navigation;
- it introduces a single expanded/collapsed navigation state and responsive layout rules;
- current main has not independently superseded those exact product changes;
- future `/customers` Inquiry Inbox work will depend on a stable Shell/navigation foundation.

## 5. Human UAT linkage

Canonical open finding:

```text
FINDING_ID: MVP1-UAT-DEFECT-001
TITLE: 二级菜单折叠后页面布局错乱
SEVERITY: HIGH
FOUNDATION_CANDIDATE: APP_SHELL
CURRENT_STATUS: PLANNING
CLOSURE_STATUS: OPEN
```

This Work Package becomes the remediation target for that finding. The finding must remain OPEN until exact-revision China-local browser verification and Owner visual UAT both pass.

This package does not automatically close any other Human UAT finding.

## 6. Frozen design direction

The accepted remediation direction is:

```text
BEFORE
expanded primary rail
+ separate module sidebar
+ workspace

AFTER
one primary navigation
├── expanded: label + emoji + active child links
├── collapsed: compact icon/emoji rail, child links removed from interaction
└── workspace
```

Behavioral contract:

- expanded navigation uses the wide navigation token;
- collapsed navigation uses the compact navigation token;
- desktop collapse control lives in the navigation brand row;
- Top Bar keeps only the narrow-screen navigation control;
- active child navigation appears inline only under the active parent path;
- collapsed navigation hides labels and child links from interaction;
- collapsed first-level items retain an accessible label/title;
- account dock remains in the primary navigation and remains usable;
- collapsed preference persists locally when browser localStorage is available;
- absence/denial of localStorage fails safely and must not break rendering;
- at `<=800px`, navigation remains a bounded drawer and the workspace remains one grid column;
- closed mobile navigation remains `inert` and `aria-hidden`;
- no horizontal overflow at 799px, 800px, 801px and normal desktop widths.

## 7. Approved menu contract

`menu/APPROVED_MENU.json` remains authoritative and unchanged by this package.

Frozen Level-1 facts remain:

```text
11 Level-1 menu items
Dashboard → 物流 → 同步 → 客户 → 网红 → 库存 → 外采 → 产品 → 指南 → 会计 → 设置
```

This package changes presentation of runtime child navigation only. It must not:

- add/remove/reorder approved Level-1 items;
- change approved labels, emoji, slug, URL, route, permission or function descriptions;
- create a new Level-1/Level-2 menu taxonomy;
- change server-side permission filtering;
- fabricate unauthorized navigation.

## 8. Parallel-work / open-PR reconciliation

### PR #81 — Dashboard redesign

PR #81 is open Draft and owns:

```text
resources/js/Pages/Dashboard/Index.vue
resources/js/Pages/Dashboard/Index.test.ts
```

The legacy WIP also touched `Dashboard/Index.vue` only to add generic page-layout classes. To keep parallel ownership clean:

```text
DASHBOARD_INDEX_IN_THIS_PACKAGE = EXCLUDED
PR_81_DEPENDENCY = NONE
PARALLEL_SAFE_WITH_PR_81 = YES, ONLY IF DASHBOARD FILES REMAIN UNTOUCHED
```

Claude must not wholesale cherry-pick `53d2b79`; the authorized Shell changes must be replayed selectively.

### PR #86 — Inquiry M1.0 acceptance sync

PR #86 is open Draft and changes shared canonical Roadmap/context files, not product Shell files.

To avoid unnecessary governance overlap, this package initially uses its own Work Package / Work Log plus the UAT Finding linkage. Shared `AI/CURRENT_CONTEXT`, Living Roadmap, Work Package Index and Program Hours synchronization is deferred until PR #86 is resolved or this PR is rebased on its merged result.

```text
PARALLEL_SAFE_WITH_PR_86 = YES
SHARED_ROADMAP_FILE_OVERLAP = PROHIBITED BEFORE REBASE/SYNC
```

## 9. Authorized implementation file boundary

Claude may change only the following product/test paths unless GPT Web issues a new bounded revision to this Work Package:

```text
resources/css/shell.css
resources/css/tokens.css
resources/js/Components/AppShell/GlobalNavigation.vue
resources/js/Components/AppShell/NavigationItem.vue
resources/js/Components/AppShell/TopBar.vue
resources/js/Layouts/AuthenticatedLayout.vue
resources/js/Components/AppShell/GlobalNavigation.test.ts
resources/js/Layouts/AuthenticatedLayout.test.ts
resources/js/Pages/Orders/Index.vue
resources/js/Pages/Orders/Show.vue
resources/js/Pages/Orders/Create.vue        // layout-only adaptation if proven necessary
tests/e2e/shell/app-shell.spec.ts
```

Governance/evidence files for this same Work Package may also be updated on this delivery branch.

GPT Web bounded scope revision (2026-08-23):

```text
tests/Architecture/ModuleArchitectureTest.php
```

is additionally authorized **only** to synchronize the existing `FRONTEND_FILES` frozen frontend file-set/hash baseline with this Work Package's already-authorized Shell changes. The allowed edit is limited to:

- replacing hashes for frontend files changed by this Work Package;
- adding `resources/js/Components/AppShell/NavigationItem.vue` with its exact SHA-256 hash;
- preserving all other architecture assertions, route contracts, permission contracts, module boundaries, migration hashes, menu hash and test semantics unchanged.

This is a test-baseline synchronization, not a new product or architecture redesign.

Explicit exclusions:

```text
resources/js/Pages/Dashboard/**          // owned by PR #81
resources/js/Components/AppShell/ModuleSidebar.vue // preserve current architecture artifact unless separately authorized
menu/**
config/erp_shell_navigation.php
app/Modules/Shell/**
app/Modules/Identity/**
routes/**
database/**
composer.*
package.json
package-lock.json
.env*
```

No route, permission, authentication, database, dependency, queue, Email, WhatsApp, Inquiry-domain, remote or deployment change is authorized.

## 10. Implementation instructions

Claude Code implementation must:

1. start from the exact delivery branch based on current `main`;
2. read this Work Package and current role/governance entrypoints;
3. use old branch `53d2b79` only as implementation evidence/reference;
4. replay the approved behavior selectively rather than cherry-picking the whole legacy commit;
5. exclude the Dashboard delta owned by PR #81;
6. fix the localStorage persistence test using a controlled test-local browser-storage contract, not by weakening the production fallback;
7. preserve all approved menu and permission contracts;
8. preserve Account Dock / logout / mobile inertness behavior;
9. make no unrelated visual redesign;
10. stop and return raw implementation facts if any file outside the authorized scope (including the bounded `tests/Architecture/ModuleArchitectureTest.php` baseline-sync exception above) becomes necessary.

Claude must not diagnose root cause, alter architecture, select a different design, expand scope, merge, deploy or perform Owner UAT.

## 11. Acceptance criteria

Implementation is merge-ready only when all are true:

1. legacy WIP behavior is reconciled onto the current-main lineage without textual or semantic regression;
2. authenticated desktop layout uses one unified primary navigation plus workspace, not a separately rendered Module Sidebar;
3. expanded/collapsed navigation state is accessible and stable;
4. child navigation is reachable when expanded and absent from interaction when collapsed;
5. localStorage persistence is covered by a deterministic test and safe fallback;
6. mobile drawer behavior and `inert` / `aria-hidden` contracts pass;
7. approved menu baseline remains byte/semantic unchanged;
8. routes and permission keys remain unchanged;
9. Dashboard PR #81 files remain untouched;
10. Orders list/create/detail remain usable with expanded and collapsed navigation;
11. `MVP1-UAT-DEFECT-001` browser scenario is reproduced/retested on exact revision;
12. no horizontal overflow at 799/800/801 and normal desktop width;
13. Account Dock and logout remain usable in expanded/collapsed/mobile states;
14. browser console has no new error/warning caused by the remediation;
15. all required automated validation passes;
16. China Mac Codex returns raw PASS/FAIL evidence only on the exact GitHub revision;
17. Owner visual UAT passes before merge authorization;
18. no prohibited file or system boundary was changed.

## 12. Required automated validation

Minimum implementation-side validation:

```text
npm test -- --run resources/js/Components/AppShell/GlobalNavigation.test.ts resources/js/Layouts/AuthenticatedLayout.test.ts
npm test
npm run typecheck
npm run build
php artisan test --testsuite=Architecture
php artisan test tests/Feature/ApprovedMenuBaselineTest.php
php artisan test
npm run typecheck:e2e
npm run test:e2e
git diff --check
```

Where the local E2E harness requires its established China-local browser/runtime switches, Codex must use repository-documented test-only configuration and report the exact invocation and exit code.

## 13. Human visual UAT matrix

Owner UAT is required before merge and must cover at least:

```text
Desktop normal width / expanded nav
Desktop normal width / collapsed nav
Orders list / expanded and collapsed
Orders create / expanded and collapsed
Orders detail / expanded and collapsed
799px / nav closed and open
800px / nav closed and open
801px / expanded and collapsed
Account menu / expanded and collapsed
Refresh after collapse / persistence behavior
```

`MVP1-UAT-DEFECT-001` closes only after this matrix passes and the exact merged-candidate revision is recorded.

## 14. Migration / deployment / rollback

```text
MIGRATION_FILE_AUTHORING = NO
MIGRATION_EXECUTION = NO
DATABASE_MUTATION = NO
REMOTE_DEV_ACCESS = NO
PRODUCTION_ACCESS = NO
DEPLOYMENT = NO
```

Rollback is a revert of this Work Package's product commit(s). No database rollback is required.

## 15. Dependency declaration

```text
BASELINE: delivery base main@02ce87dba5ede14d791b646b7cbe976a12064cae; current main@9f64c00faf79c6c59ea9ff2a3bd5befa740ba6f6; validated product head 795d3a5ac565c68a5c8b9cc7bd173d2272d45b5c
DEPENDENCIES: merged current-main Shell architecture; merged Menu Baseline; source WIP branch is evidence only
DEPENDENCY_TYPE: MERGED_PR
DEPENDENCY_STATUS: SATISFIED
PARALLEL_SAFE: YES WITH DECLARED EXCLUSIONS
SOURCE_WIP_BRANCH: feature/byl-erp-ui-application-shell-001
SOURCE_WIP_HEAD: 53d2b79c924d3cfe1042a06509f96e45a730ac28
OPEN_PR_81: independent only when Dashboard files are excluded
OPEN_PR_86: independent only while shared Roadmap/context files are not edited before rebase/sync
```

## 16. Authorization state

The Owner has authorized the read-only/current-main re-audit and formal Work Package definition.

The Owner authorized Claude implementation under this Work Package on 2026-08-23. R2 implementation and all required automated verification completed, and the Owner completed the second visual UAT on 2026-08-23 with `PASS`. This authorizes formal closure evaluation and the Merge Gate only; the actual merge still requires a separate explicit Owner merge authorization.

```text
PREFLIGHT = COMPLETE
WORK_PACKAGE = FORMALIZED
CLAUDE_IMPLEMENTATION = COMPLETE
CODEX_PRODUCT_MUTATION = PROHIBITED
CHINA_CODEX_EXACT_REVISION = PASS
OWNER_UAT = PASS
MVP1-UAT-DEFECT-001 = REMEDIATION PASS / MERGE PENDING
MERGE_GATE = AUTHORIZED FOR EVALUATION
MERGE = NOT AUTHORIZED
DEPLOYMENT = NOT AUTHORIZED
```

## 17. R2 bounded revision — Owner UAT remediation (2026-08-23)

Owner UAT on the R1 delivery (PR #87) returned `CHANGES REQUIRED`. Owner authorized a bounded R2 revision that explicitly widens the file boundary defined in Section 9 for this one round only:

```text
R2_TRIGGER: Owner UAT CHANGES REQUIRED on PR #87
R2_AUTHORIZATION: IMPLEMENTATION AUTHORIZED / MERGE NOT AUTHORIZED
R2_ADDITIONAL_ALLOWED_FILES:
  config/erp_shell_navigation.php
  menu/APPROVED_MENU.json
  resources/js/Pages/Dashboard/Index.vue (literal display-name strings only)
  resources/js/Pages/Dashboard/Index.test.ts
  tests/Architecture/ModuleArchitectureTest.php (frontend + menu hash/file-set synchronization, in addition to the prior FE-001 exception)
  tests/e2e/shell/app-shell.spec.ts (already listed in Section 9)
```

R2 scope, bounded to three defects raised in UAT:

1. **Restored secondary navigation.** `config/erp_shell_navigation.php` gained the full legacy Level-2/Level-3 hierarchy (`config/erp_navigation.php` remains legacy evidence/route-compat only; `ErpShellNavigation` still reads a single runtime source). Child `permission` values were derived from each child's actual registered route middleware (real permission where implemented, `prototype.view` for legacy-only routes, parent's own permission where a child intentionally shares its parent's landing route/URL — `库存总览`, `外采总览`, `产品索引`, `全部教程`). No route or permission was added, renamed or removed; `tests/Architecture/ModuleArchitectureTest.php`'s frozen `ROUTE_NAMES`/`PERMISSION_KEYS` COMPAT-001 assertions pass unchanged.
2. **Dashboard localization.** Canonical `dashboard` label plus the Dashboard page's visible TopBar/breadcrumb text and browser title changed from `Dashboard` to `仪表盘`. Key/slug/path/route/permission are unchanged. Dashboard layout, metrics and PR #81 redesign scope were not touched.
3. **Collapsed-rail Level-1 labels.** `bee763d` (R1's fix for a since-superseded e2e expectation) had removed `.shell-primary-label` from the DOM entirely when collapsed. Owner UAT requires the emoji + Chinese label to remain visible (clipped, non-wrapping) inside the fixed 92px rail; only child links (`.shell-child-links`) are removed from the DOM/interaction while collapsed. `NavigationItem.vue` and `resources/css/shell.css` were reverted to this contract; `tests/e2e/shell/app-shell.spec.ts` was updated to match (it previously asserted zero `.shell-primary-label` elements collapsed — that assertion is now `levelOneMenu.length`).

`menu/APPROVED_MENU.json` governance was explicitly expanded by Owner for this round only: `governed_levels` remains `[1, 2]`; the 32 confirmed Level-2 entries (including the non-link `费用分析表` group, which carries `route: null` and is never registered as a Laravel route) are now Owner-approved and byte-verified against the runtime config (`tests/Feature/ApprovedMenuBaselineTest.php::test_runtime_level_one_and_two_menu_matches_the_owner_approved_baseline`). Level-3 children of `费用分析表` render at runtime but are intentionally excluded from the governed baseline, consistent with the existing schema. The URL-uniqueness assertion was narrowed to allow exactly the four Owner-approved parent/child landing-URL pairs named above; no other duplicate URL is permitted.

```text
R2_VALIDATION: see Section 12 command list; R2 run recorded in the 2026-08-23 work log
R2_MERGE: NOT AUTHORIZED
R2_DEPLOYMENT: NOT AUTHORIZED
```

## 18. Owner UAT PASS / Merge Gate evidence (2026-08-23)

Validated product revision before closure-only documentation:

```text
VALIDATED_PRODUCT_HEAD = 795d3a5ac565c68a5c8b9cc7bd173d2272d45b5c
US_FULL_LOCAL_VERIFICATION = PASS
CHINA_EXACT_REVISION_VERIFICATION = PASS
OWNER_VISUAL_UAT_R2 = PASS
MVP1-UAT-DEFECT-001 = REMEDIATION PASS
```

China exact-revision evidence on `795d3a5...`: focused frontend 20/20 PASS; full frontend 78/78 PASS; typecheck PASS; build PASS; full PHP 165 tests / 163 passed / 2 skipped / 1 risky / 2265 assertions; Architecture 16/16 PASS; Approved Menu Baseline 4/4 PASS; App Shell Navigation 21/21 PASS; E2E typecheck PASS; Browser E2E 19/19 PASS with cleanup PASS; `http://erp.b` HTTP 200; final Git state clean.

Owner visual UAT then confirmed the R2 requirements in the China runtime: restored secondary navigation, `仪表盘` localization, fixed-width collapsed rail retaining emoji + Chinese Level-1 labels, hidden/non-interactive child navigation while collapsed, and stable expand/collapse layout behavior.

Current `main` advanced after the package baseline to `9f64c00faf79c6c59ea9ff2a3bd5befa740ba6f6` only through the asymmetric verification-tier governance change. Its changed files (`AI/EXECUTION_ROLE_MODEL.md`, `AI/LARGE_PROJECT_EXECUTION_STANDARD.md`, `docs/60_Codex_Operations/work-packages/ERP-VERIFICATION-TIER-POLICY-001.md`) do not overlap PR #87 changed files. A read-only `git merge-tree` check reports no conflict markers. Open Draft PR #81 remains a later Dashboard-owner rebase concern, not a current-main merge blocker for #87.

```text
MERGE_GATE_PRODUCT = PASS
MERGE_GATE_TESTS = PASS
MERGE_GATE_OWNER_UAT = PASS
MERGE_GATE_CURRENT_MAIN_CONFLICT = NONE WITH EVIDENCE
MERGE_GATE_RESULT = PASS / READY FOR EXPLICIT OWNER MERGE AUTHORIZATION
MERGE = NOT YET AUTHORIZED
DEPLOYMENT = NOT AUTHORIZED
```

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate [COMPLETE] → MVP1 Existing Operations / Tencent Docs Replacement [IMPLEMENTATION COMPLETE; HUMAN UAT REMEDIATION OPEN] → ERP-INQUIRY-M1 B2B Inquiry Foundation [CURRENT] → ERP-INQUIRY-M1 Local Milestone UAT → Milestone-gated Remote Release to erp.beyourlover.com → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Production Hardening / Expansion
CURRENT_TRACK_PHASE_MAP: ERP-INQUIRY-M1.0 CONTRACT MERGED → ERP-APP-SHELL-NAVIGATION-REMEDIATION-001 IMPLEMENTATION/VERIFICATION/OWNER UAT COMPLETE / MERGE GATE → ERP-INQUIRY-M1.1 Company → M1.2 Contact → M1.3 Case → M1.4 Inbound Message → M1.5 Inquiry Inbox → M1.6 Email → M1.7 WhatsApp → M1.8 Dashboard → M1.9 Local Milestone UAT
COMPLETED_PHASES: Architecture Foundation; Global Architecture Gate; MVP1 implementation; ERP-INQUIRY-M1.0 contract design/merge
CURRENT_PHASE: App Shell / Navigation remediation closure and Merge Gate
CURRENT_WORK_PACKAGE: ERP-APP-SHELL-NAVIGATION-REMEDIATION-001
PACKAGE_STATUS: IMPLEMENTATION COMPLETE / VERIFICATION PASS / OWNER UAT PASS / MERGE GATE PASS
WAITING_PHASES: explicit Owner merge authorization for PR #87; then ERP-INQUIRY-M1.1 onward per Owner sequence
BASELINE: delivery base main@02ce87dba5ede14d791b646b7cbe976a12064cae; current main@9f64c00faf79c6c59ea9ff2a3bd5befa740ba6f6; validated product head 795d3a5ac565c68a5c8b9cc7bd173d2272d45b5c
GITHUB_STATE: PR #87 on feature/erp-app-shell-navigation-remediation-001; validated product head 795d3a5ac565c68a5c8b9cc7bd173d2272d45b5c; Owner UAT PASS; closure-only documentation follows on the same PR; current main 9f64c00 has no overlapping changed files
SOURCE_CONFLICTS: current main 9f64c00 changes only verification-governance files and has no overlap with PR #87; open Draft PR #81 also touches Dashboard/Index.vue and must reconcile after #87, but does not block #87 against current main; historical source-WIP localStorage test issue was remediated and validated
GLOBAL_GATE: PASS
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: explicit Owner merge authorization for PR #87
EVIDENCE_AS_OF: 2026-08-23 Asia/Hong_Kong

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 6–12h; planning value 9h; bounded App Shell remediation from preserved WIP evidence
KNOWN_CURRENT_TRACK_REMAINING: ERP-INQUIRY-M1 M1.1–M1.9 86–150h; planning value 116h in merged M1.0 contract; canonical Program Hours synchronization remains pending PR #86
ARCHITECTURE_GATE_REMAINING: 0h / complete
MVP1_TOTAL_ESTIMATE: 122–190h
MVP1_PLANNING_BASELINE: 166h
MVP1_MAXIMUM_ENVELOPE: 192h
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; five Human UAT findings remain open and accepted actual-hours overlap ledger is incomplete
POST_MVP_KNOWN_INCREMENT: approximately 68–120h beyond MVP1
LONG_TERM_CUMULATIVE_RANGE: 235–402h architecture foundation; 603–1,018h first highly automated operations MVP; 1,059–1,802h product/SEO growth layer; 1,595–2,714h full core ERP; 1,819–3,146h all expansion
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE
ESTIMATE_CONFIDENCE: HIGH for technical closure; implementation, dual-host validation, browser E2E and Owner visual UAT are complete; actual-hours ledger remains separately UNKNOWN WITH EVIDENCE
ESTIMATE_AS_OF: 2026-08-23
ESTIMATE_METHOD: current-main replay audit of preserved WIP + baseline/replayed focused tests + declared open-PR overlap exclusions; no elapsed-time inference

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 1–2h; planning value 1.5h
CORE_EXECUTION: 2–4h; planning value 3h
TEST_AND_VALIDATION: 2–4h; planning value 3h
REMEDIATION_BUFFER: 1–2h; planning value 1.5h
ESTIMATED_EFFECTIVE_HOURS: 9h
ESTIMATE_RANGE: 6–12h
ESTIMATE_BASIS: selective current-main replay of a 12-file preserved WIP, removal of PR #81 overlap, deterministic localStorage-test repair, full Shell/menu/browser regression and merge-candidate preparation
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
