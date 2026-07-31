---
type: repository-manifest
project: BYL ERP
version: 1.0
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

## Current repository governance

- `main` is the merge target and repository truth.
- Feature work occurs on dedicated branches.
- Owner authorization is required before merge.
- Documentation work must not contaminate unrelated implementation PRs.
