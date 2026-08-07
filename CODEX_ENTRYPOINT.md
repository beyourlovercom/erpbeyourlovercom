---
type: codex-entrypoint
project: BYL ERP
version: 1.2
---

# BYL ERP Codex Entrypoint

Every Codex or OpenCode session begins here.

## Read in this order

1. `AI/REPOSITORY_MANIFEST.md`
2. `AI/LARGE_PROJECT_EXECUTION_STANDARD.md`
3. `AI/PARALLEL_WORK_EXECUTION_STANDARD.md`
4. `AI/CURRENT_CONTEXT.md`
5. `docs/10_Planning_Roadmap/00_COMMAND_CENTER.md`
6. `docs/10_Planning_Roadmap/03_SYSTEM_ARCHITECTURE.md`
7. `docs/10_Planning_Roadmap/04_LIVING_ROADMAP.md`
8. `docs/10_Planning_Roadmap/13_PROGRAM_ROADMAP_AND_HOURS.md`
9. The activated Work Package
10. Relevant module specification
11. Recent work logs

## Mandatory rules

- Repository Markdown is the single source of truth.
- Every formal Codex or OpenCode execution package must comply with `AI/LARGE_PROJECT_EXECUTION_STANDARD.md`.
- Every formal execution involving multiple active Work Packages, branches, PRs, worktrees, or agent streams must comply with `AI/PARALLEL_WORK_EXECUTION_STANDARD.md`.
- Multiple active Work Packages, PRs, OpenCode sessions and dedicated worktrees are allowed when the Parallelization Preflight Gate passes.
- `ONE_WORK_PACKAGE_ONE_BRANCH_ONE_PR`: for newly activated packages after `PARALLEL-WORK-EXEC-001` becomes effective, each bounded Work Package maps to exactly one delivery branch and one delivery PR; historical merged packages and explicitly grandfathered legacy active packages remain valid.
- `ONE_ACTIVE_AGENT_ONE_WORKTREE`: every simultaneously active repository-mutating agent session uses its own dedicated worktree bound to its Work Package and branch.
- `PARALLEL_PR_MUST_NOT_HAVE_UNDECLARED_DEPENDENCIES`: parallel packages must explicitly declare baseline, dependencies, dependency type/status, and whether they are parallel-safe.
- `COMMON_FOUNDATION_CHANGES_MUST_BE_EXTRACTED_BEFORE_PAGE-LEVEL_PARALLELIZATION`: shared foundation work must be separated and merged first unless Owner explicitly authorizes a declared stacked dependency.
- Newly activated Work Packages use one Draft delivery PR for Work Package contract, implementation, validation, remediation and final pre-merge evidence; do not create separate Work Package / Implementation / Closure PRs for the same new Work Package identity.
- A second post-merge Closure PR under the same new Work Package identity is prohibited; genuine post-merge repository mutation requires a separately identified Work Package.
- Every formal task and final report must end with `PROJECT POSITION` → `ROADMAP HOURS` → `ESTIMATED CODEX HOURS`.
- `ESTIMATED CODEX HOURS` must be the final semantic module; only Markdown fences or other purely syntactic formatting closures may follow it.
- `PROJECT POSITION` must show the complete Program Roadmap and a distinct complete current-track map.
- `ROADMAP HOURS` is mandatory and must classify total, remaining, incremental, cumulative, planning and actual values using document 13.
- `MIGRATION` is mandatory; Migration authoring/execution subfields cannot replace it.
- Open and unmerged PRs must comply with the current standard before merge unless an explicit Version Transition rule grandfathers their already-issued contract.
- Final reports require actual effective hours, breakdown, variance and variance reason.
- Project position derives from current context, Living Roadmap, document 13, the current Work Package and verified GitHub state.
- Project position must independently report the global gate and current production deployment status; estimate inclusion must not be used as deployment status.
- Estimated Codex hours must show preflight and scope, core execution, test and validation, and remediation buffer separately, with totals reconciled to all components.
- A task missing any mandatory footer module must not be executed; an incomplete final report is not merge-ready.
- Do not maintain a second Roadmap in Obsidian.
- Do not mix unrelated documentation changes into feature PRs.
- Do not begin a waiting Work Package.
- Do not silently change architecture, product scope, priority, status or estimates.
- Record Owner changes in the Decision Register and Change Log.
- Never report PASS without repository evidence.
- Keep the global architecture gate accurate.
