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
6. `docs/10_Planning_Roadmap/13_PROGRAM_ROADMAP_AND_HOURS.md`
7. The activated Work Package
8. Relevant module specification
9. Recent work logs

## Mandatory rules

- Repository Markdown is the single source of truth.
- Every formal Codex execution package must comply with `AI/LARGE_PROJECT_EXECUTION_STANDARD.md`.
- Every formal task and final report must end with `PROJECT POSITION` → `ROADMAP HOURS` → `ESTIMATED CODEX HOURS`.
- `ESTIMATED CODEX HOURS` must be the final semantic module; only Markdown fences or other purely syntactic formatting closures may follow it.
- `PROJECT POSITION` must show the complete Program Roadmap and a distinct complete current-track map.
- `ROADMAP HOURS` is mandatory and must classify total, remaining, incremental, cumulative, planning and actual values using document 13.
- `MIGRATION` is mandatory; Migration authoring/execution subfields cannot replace it.
- Open and unmerged PRs must comply with the current standard before merge.
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
