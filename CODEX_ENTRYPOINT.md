---
type: codex-entrypoint
project: BYL ERP
version: 3.0
---

# BYL ERP Codex CLI Entrypoint

Every Codex CLI session begins here. The active Work Package determines whether the session is implementation-capable or verification-only.

## Read in this order

1. `AI/EXECUTION_ROLE_MODEL.md`
2. `AI/REPOSITORY_MANIFEST.md`
3. `AI/LARGE_PROJECT_EXECUTION_STANDARD.md`
4. `AI/PARALLEL_WORK_EXECUTION_STANDARD.md`
5. `AI/CURRENT_CONTEXT.md`
6. The exact Work Package / PR / commit
7. Relevant frozen specifications and acceptance criteria

## US Codex implementation mode

US Mac Codex may implement only when the Work Package declares `EXECUTION_PROVIDER: US_CODEX_CLI` and freezes the required architecture/business behavior.
In implementation mode Codex may:
- modify tracked files inside the dedicated Work Package worktree;
- create the frozen migrations/schema/tests specified by the package;
- run PHPUnit, Vitest, typecheck, build and `http://erp.b` runtime verification;
- commit, push and maintain the Draft PR only when the Work Package explicitly authorizes those Git actions;
- report raw implementation/test facts.

Codex must stop and return to GPT Web when architecture, business behavior, scope or remediation becomes ambiguous.

Codex must not invent architecture, widen scope, choose replacement designs, select the next Work Package, merge without Owner authorization or deploy without explicit release authorization.

## Verification-only mode

When the Work Package declares `EXECUTION_PROVIDER: CODEX_CLI` for verification only, Codex must not modify tracked files. It may check out the requested exact GitHub revision, prepare approved local test runtime, run the specified verification and return evidence-supported results.

China Mac is not a default verification host. If an independent secondary host is explicitly requested, the Work Package must name the host and exact scope.

## Collision rule

Codex must never enter implementation mode in a worktree currently owned by GPT+DC or Claude Code. `ONE_ACTIVE_IMPLEMENTATION_PROVIDER_PER_WORKTREE` is mandatory.
