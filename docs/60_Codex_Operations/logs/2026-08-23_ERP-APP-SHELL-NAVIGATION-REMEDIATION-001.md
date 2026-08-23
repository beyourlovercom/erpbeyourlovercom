---
type: work-log
project: BYL ERP
work_package_id: ERP-APP-SHELL-NAVIGATION-REMEDIATION-001
status: PREFLIGHT COMPLETE / IMPLEMENTATION NOT AUTHORIZED
evidence_as_of: 2026-08-23T12:42:00+08:00
---

# 2026-08-23 — ERP-APP-SHELL-NAVIGATION-REMEDIATION-001

## Audit authority

Owner instruction: formally re-audit `feature/byl-erp-ui-application-shell-001` against current `main` and define it as an explicit App Shell / Navigation Remediation Work Package.

Design/diagnosis authority: GPT Web.
Implementation provider after a separate Owner gate: US Claude Code.
Test/evidence provider after implementation: China Codex CLI on `http://erp.b`.

## Repository facts

```text
CURRENT_MAIN = 02ce87dba5ede14d791b646b7cbe976a12064cae
SOURCE_WIP_BRANCH = feature/byl-erp-ui-application-shell-001
SOURCE_WIP_BASE = 75cb7fc822d30fd9ac34799b5971702c069a8de0
SOURCE_WIP_HEAD = 53d2b79c924d3cfe1042a06509f96e45a730ac28
SOURCE_WIP_COMMIT = wip(shell): preserve local application shell redesign
SOURCE_WIP_FILES = 12
SOURCE_WIP_INSERTIONS = 207
SOURCE_WIP_DELETIONS = 102
```

## Current-main overlap audit

For the 12 WIP product/test paths:

```text
DIFF 75cb7fc..02ce87d ON WIP PATHS = EMPTY
CURRENT_MAIN_PRODUCT_OVERLAP = NONE
```

A disposable worktree at current main applied the WIP commit with:

```text
git cherry-pick --no-commit 53d2b79...
RESULT = PASS
TEXTUAL_CONFLICTS = 0
```

This is replay evidence only; it is not authorization to cherry-pick the old commit into the new delivery branch.

## Focused baseline evidence

Current main, before WIP replay:

```text
GlobalNavigation.test.ts = 5 PASS
AuthenticatedLayout.test.ts = 7 PASS
TOTAL = 12 / 12 PASS
EXIT = 0
```

Current main with WIP replayed in the disposable worktree:

```text
GlobalNavigation.test.ts = 6 PASS
AuthenticatedLayout.test.ts = 7 PASS / 1 FAIL
TOTAL = 13 PASS / 1 FAIL
EXIT = 1
```

Failing assertion contract:

```text
TEST = restores the collapsed state from local storage
ERROR = TypeError: Cannot read properties of undefined (reading 'setItem')
LOCATION = resources/js/Layouts/AuthenticatedLayout.test.ts
```

GPT diagnosis:

- the failure is introduced by the WIP because current-main baseline passes;
- the failing line directly calls `window.localStorage.setItem(...)` before mounting the component;
- the component's production localStorage read/write helpers already fail safe with `try/catch`;
- therefore current evidence supports a test-environment/localStorage contract defect, not a proven production persistence defect;
- implementation must add deterministic test-local storage support and then verify persistence in a real browser.

## Additional replay evidence

```text
TYPECHECK = PASS
PRODUCTION_BUILD = PASS
GIT_DIFF_CHECK = PASS
```

## Structural WIP finding

The WIP changes the Shell from:

```text
primary rail + separate module sidebar + workspace
```

to:

```text
single primary navigation + inline active child navigation + workspace
```

Observed implementation direction:

- expanded navigation width token becomes 208px;
- collapsed navigation width becomes 60px;
- desktop collapse control moves into the navigation brand row;
- Top Bar removes the desktop module-sidebar toggle;
- `NavigationItem.vue` recursively renders active child navigation;
- child navigation is omitted from interaction when collapsed;
- local collapse preference is stored under `module_sidebar_collapsed`;
- mobile layout remains a drawer at `<=800px`;
- `AuthenticatedLayout` no longer renders `ModuleSidebar`.

## UAT linkage

Current canonical finding:

```text
MVP1-UAT-DEFECT-001
二级菜单折叠后页面布局错乱
SEVERITY = HIGH
FOUNDATION_CANDIDATE = APP_SHELL
CLOSURE_STATUS = OPEN
```

Audit classification:

```text
LEGACY_WIP = KEEP_AND_COMPLETE
TARGET_WORK_PACKAGE = ERP-APP-SHELL-NAVIGATION-REMEDIATION-001
DEFECT_CLOSURE = NOT AUTHORIZED / OWNER UAT REQUIRED
```

The original UAT root cause is not declared closed by this read-only audit. Browser verification must prove that the new Shell layout resolves Orders list/create/detail collapse and responsive scenarios.

## Parallel PR collision audit

Open PR #81 owns:

```text
resources/js/Pages/Dashboard/Index.vue
resources/js/Pages/Dashboard/Index.test.ts
```

The legacy WIP also touched `Dashboard/Index.vue`. Decision:

```text
DASHBOARD_DELTA_FROM_LEGACY_WIP = EXCLUDED FROM NEW PACKAGE
PARALLEL_SAFE_WITH_PR_81 = YES ONLY WITH THAT EXCLUSION
```

Open PR #86 changes canonical Roadmap/context synchronization only. Decision:

```text
PRODUCT_DEPENDENCY_ON_PR_86 = NONE
SHARED_ROADMAP_CONTEXT_EDIT_IN_THIS_PACKAGE = DEFERRED UNTIL PR_86 RESOLVED / REBASE
PARALLEL_SAFE_WITH_PR_86 = YES
```

## Safety

```text
APPLICATION_CODE_MUTATED_BY_AUDIT = NO
MIGRATION = NO
DATABASE_MUTATION = NO
DEPENDENCY_CHANGE = NO
MENU_BASELINE_CHANGE = NO
EMAIL_CONNECTION = NO
WHATSAPP_CONNECTION = NO
REMOTE_DEPLOYMENT = NO
```

## Current verdict

```text
CURRENT_MAIN_REAUDIT = COMPLETE
LEGACY_WIP_CLASSIFICATION = KEEP_AND_COMPLETE
NEW_WORK_PACKAGE = ERP-APP-SHELL-NAVIGATION-REMEDIATION-001
NEW_DELIVERY_BRANCH = feature/erp-app-shell-navigation-remediation-001
CLAUDE_IMPLEMENTATION = NOT AUTHORIZED
CODEX_MUTATION = PROHIBITED
OWNER_UAT = NOT RUN
MERGE = NOT AUTHORIZED
```
