---
type: repository-manifest
project: BYL ERP
version: 1.3
---

# Repository Manifest

## Repository

```text
Name: erpbeyourlovercom
Remote: git@github.com:beyourlovercom/erpbeyourlovercom.git
Local test checkout: /Users/a/AI/007/erpbeyourlovercom
Default branch: main
Primary local runtime: http://erp.b
Remote milestone target: https://erp.beyourlover.com
```

GitHub `main` is the unique repository source of truth and the only code synchronization path between development and test machines.
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

## Mandatory role and execution governance

```text
AI/EXECUTION_ROLE_MODEL.md
AI/LARGE_PROJECT_EXECUTION_STANDARD.md
AI/PARALLEL_WORK_EXECUTION_STANDARD.md
CLAUDE_ENTRYPOINT.md
CODEX_ENTRYPOINT.md
AI/CURRENT_CONTEXT.md
```
## Planning locations

```text
docs/10_Planning_Roadmap/
docs/60_Codex_Operations/
AI/CURRENT_CONTEXT.md
```

## Current execution model

- GPT Web + Owner own architecture, design, diagnosis, root cause and next-step decisions.
- US Mac Claude Code implements only Owner/GPT-approved Work Packages.
- China Mac Codex CLI tests exact GitHub revisions and returns evidence only.
- Codex does not modify tracked files, fix code, commit, push or open implementation PRs.
- Claude does not invent architecture, diagnose root cause, widen scope or choose the next Work Package.
- Normal development validation remains on China Mac `http://erp.b`.
- Remote `https://erp.beyourlover.com` is milestone-gated and not a normal debugging environment.

## Repository governance

- `main` is the merge target and repository truth.
- New implementation Work Packages use one Work Package → one branch → one Draft delivery PR.
- Each simultaneously active Claude implementation stream uses a dedicated worktree.
- Parallel dependencies must be explicit; shared foundations are extracted first unless Owner authorizes a stacked dependency.
- Owner authorization is required before merge or remote deployment.
- Documentation/design work must not contaminate unrelated implementation PRs.
