---
type: claude-entrypoint
project: BYL ERP
version: 1.1
---

# BYL ERP Claude Code Entrypoint

Every US Mac Claude Code implementation session begins here. Claude is used when the active Work Package declares `EXECUTION_PROVIDER: CLAUDE_CODE`; deterministic low/medium-complexity packages may instead use US Codex under Execution Role Model v1.3.

## Read in this order

1. `AI/EXECUTION_ROLE_MODEL.md`
2. `AI/REPOSITORY_MANIFEST.md`
3. `AI/LARGE_PROJECT_EXECUTION_STANDARD.md`
4. `AI/PARALLEL_WORK_EXECUTION_STANDARD.md`
5. `AI/CURRENT_CONTEXT.md`
6. `docs/10_Planning_Roadmap/03_SYSTEM_ARCHITECTURE.md`
7. `docs/10_Planning_Roadmap/04_LIVING_ROADMAP.md`
8. `docs/10_Planning_Roadmap/13_PROGRAM_ROADMAP_AND_HOURS.md`
9. The Owner/GPT-approved Work Package
10. Relevant module specifications and recent accepted logs
## Mandatory implementation rules

- GitHub `main` and the activated Work Package are the implementation authority.
- Implement only the explicitly approved scope and acceptance criteria.
- Do not invent architecture, schema semantics, UI behavior or business rules that are not frozen in the Work Package.
- Do not diagnose root cause; report observed errors and evidence to GPT Web.
- Do not choose the next Work Package or widen the current one.
- Use one Work Package → one branch → one Draft delivery PR for newly activated packages.
- Use a dedicated worktree for each simultaneously active implementation stream.
- Declare dependencies and stop when an undeclared shared-foundation dependency appears.
- Run the required implementation-local tests before pushing.
- Commit and push only the approved Work Package branch.
- Never merge without explicit Owner authorization.
- Never deploy to `https://erp.beyourlover.com` without explicit milestone release authorization.
## Required handoff

At the end of an implementation run, report only implementation facts needed for downstream verification:

```text
WORK_PACKAGE_ID
BASELINE_SHA
BRANCH
HEAD_SHA
CHANGED_FILES
MIGRATIONS_CREATED
DEPENDENCIES_CHANGED
TESTS_EXECUTED
TEST_RESULTS
KNOWN_RAW_ERRORS
PUSH_STATUS
PR_NUMBER
```

Do not include design recommendations, root-cause conclusions or next-step decisions. Those return to GPT Web + Owner.
