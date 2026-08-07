---
type: repository-manifest
project: BYL ERP
version: 1.2
---

# Repository Manifest

## Repository

```text
Name: erpbeyourlovercom
Remote: git@github.com:beyourlovercom/erpbeyourlovercom.git
Local: /Users/a/AI/007/erpbeyourlovercom
Default branch: main
```

## Mandatory architecture

```text
Laravel modular monolith
Inertia.js
Vue 3
TypeScript
Tailwind CSS
MySQL
Redis
Queue
Scheduler
Web session authentication
Server-side authorization
Audit logs
Single repository
Single deployment unit
No SSR
No independent JWT SPA
```

## Planning locations

```text
docs/10_Planning_Roadmap/
docs/60_Codex_Operations/
AI/CURRENT_CONTEXT.md
```

## Mandatory execution governance

```text
AI/LARGE_PROJECT_EXECUTION_STANDARD.md
AI/PARALLEL_WORK_EXECUTION_STANDARD.md
```

## Current repository governance

- `main` is the merge target and repository truth.
- Multiple active Work Packages, Pull Requests, OpenCode sessions and dedicated Git worktrees are allowed when `PARALLEL-WORK-EXEC-001` preflight gates pass.
- For newly activated Work Packages after `PARALLEL-WORK-EXEC-001` becomes effective, each bounded Work Package maps to one delivery branch and one delivery PR.
- Historical merged packages remain valid; legacy active packages explicitly grandfathered by an already-issued multi-PR contract may finish under that contract.
- Each simultaneously active repository-mutating agent uses a dedicated worktree.
- Parallel PR dependencies must be explicitly declared.
- Shared foundation changes required by multiple page/module packages must be extracted before page-level parallelization unless Owner explicitly authorizes a declared stacked dependency.
- New Work Packages use one Draft delivery PR across contract, implementation, validation, remediation and final pre-merge evidence; separate Work Package / Implementation / Closure PRs are not created for the same new Work Package identity.
- A genuine post-merge repository mutation requires a new separately identified Work Package rather than another PR under the original new Work Package identity.
- Owner authorization is required before merge.
- Documentation work must not contaminate unrelated implementation PRs.
