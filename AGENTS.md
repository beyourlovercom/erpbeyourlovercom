# REPOSITORY EXECUTION GOVERNANCE GATE

Before any formal repository-mutating task, every agent must read:

- `CODEX_ENTRYPOINT.md`
- `AI/LARGE_PROJECT_EXECUTION_STANDARD.md`
- `AI/PARALLEL_WORK_EXECUTION_STANDARD.md`

Parallel execution is allowed when its preflight passes. Multiple active Work Packages, PRs, OpenCode sessions and dedicated worktrees may exist concurrently.

Parallel execution must obey all of the following mandatory rules:

1. `ONE_WORK_PACKAGE_ONE_BRANCH_ONE_PR`
2. `ONE_ACTIVE_AGENT_ONE_WORKTREE`
3. `PARALLEL_PR_MUST_NOT_HAVE_UNDECLARED_DEPENDENCIES`
4. `COMMON_FOUNDATION_CHANGES_MUST_BE_EXTRACTED_BEFORE_PAGE-LEVEL_PARALLELIZATION`

`ONE_WORK_PACKAGE_ONE_BRANCH_ONE_PR` is forward-effective for newly activated Work Packages after `PARALLEL-WORK-EXEC-001` becomes effective. Historical merged packages and legacy active packages explicitly grandfathered by that standard remain valid.

If the required Work Package / branch / worktree / dependency evidence is missing or inconsistent, stop at the applicable governance gate instead of silently widening scope or sharing execution state.

# MENU BASELINE GATE

Any task that may affect a level-1 or level-2 menu must read:

- `menu/README.md`
- `menu/APPROVED_MENU.json`

before changing navigation, labels, emoji, slugs, URLs, routes, permissions, parent relationships, order, function scope, status, or progress.

If proposed behavior conflicts with the approved baseline and there is no explicit Owner authorization, stop with `MENU_BASELINE_CHANGE_REQUIRES_OWNER_APPROVAL`.
