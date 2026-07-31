---
type: codex-entrypoint
project: BYL ERP
version: 1.0
---

# BYL ERP Codex Entrypoint

Every Codex session begins here.

## Read in this order

1. `AI/REPOSITORY_MANIFEST.md`
2. `AI/CURRENT_CONTEXT.md`
3. `docs/10_Planning_Roadmap/00_COMMAND_CENTER.md`
4. `docs/10_Planning_Roadmap/03_SYSTEM_ARCHITECTURE.md`
5. `docs/10_Planning_Roadmap/04_LIVING_ROADMAP.md`
6. The activated Work Package
7. Relevant module specification
8. Recent work logs

## Mandatory rules

- Repository Markdown is the single source of truth.
- Every formal Codex execution package must comply with `AI/LARGE_PROJECT_EXECUTION_STANDARD.md`.
- Every Codex execution command must end with `PROJECT POSITION` followed by `ESTIMATED CODEX HOURS`.
- No content may appear after those two final modules.
- Project position must be derived from `AI/CURRENT_CONTEXT.md`, `docs/10_Planning_Roadmap/04_LIVING_ROADMAP.md`, the current Work Package and verified GitHub state.
- A Work Package missing either mandatory footer module must not be executed.
- Do not maintain a second Roadmap in Obsidian.
- Do not mix unrelated documentation changes into feature PRs.
- Do not begin a waiting Work Package.
- Do not silently change architecture, product scope, priority, status or estimates.
- Record Owner changes in the Decision Register and Change Log.
- Never report PASS without repository evidence.
- Keep the global architecture gate accurate.
