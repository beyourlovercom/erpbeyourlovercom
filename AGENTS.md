# REPOSITORY EXECUTION GOVERNANCE GATE

Before any formal task, read:

- `AI/EXECUTION_ROLE_MODEL.md`
- `AI/REPOSITORY_MANIFEST.md`
- `AI/LARGE_PROJECT_EXECUTION_STANDARD.md`
- `AI/PARALLEL_WORK_EXECUTION_STANDARD.md`
- `AI/CURRENT_CONTEXT.md`

Role authority is fixed:

- GPT Web + Owner: architecture, design, diagnosis, root cause and next-step decisions.
- US Claude Code: implementation only on an approved Work Package.
- China Codex CLI: test, verification and evidence only on exact GitHub revisions.
- GitHub: unique source of truth and only code synchronization path.

Codex must not modify tracked files, fix failures, commit, push, open implementation PRs, design or diagnose. Claude must not invent architecture, diagnose root cause, widen scope or choose the next Work Package.

Parallel implementation is allowed only under `PARALLEL-WORK-EXEC-001`. Each active implementation stream must obey `ONE_WORK_PACKAGE_ONE_BRANCH_ONE_PR`, `ONE_ACTIVE_AGENT_ONE_WORKTREE`, declared dependencies and shared-foundation extraction rules.

# MENU BASELINE GATE

Any task that may affect a level-1 or level-2 menu must read:

- `menu/README.md`
- `menu/APPROVED_MENU.json`

before changing navigation, labels, emoji, slugs, URLs, routes, permissions, parent relationships, order, function scope, status, or progress.

If proposed behavior conflicts with the approved baseline and there is no explicit Owner authorization, stop with `MENU_BASELINE_CHANGE_REQUIRES_OWNER_APPROVAL`.

# LOCAL / UAT CREDENTIAL GATE

For every Owner-facing Local/UAT browser test, provision and provide the default temporary credential `admin` / `password`. The alias is restricted to `local` or `testing`; never create, enable, seed, or document this credential as a production login.

When a fresh Local/UAT database is created, use `Database\Seeders\LocalUatSeeder` (or an equivalent local-only provisioning step) so the credential exists before handing the UAT URL to the Owner.

# DEFAULT OWNER UAT VERIFICATION POLICY

Unless the Owner explicitly requests otherwise, US main development machine Local/UAT verification is the default and sufficient acceptance path. China-machine verification is not required after a successful US-machine Owner UAT.

Use China-machine verification only when the Owner explicitly requests it or when a concrete cross-machine/region-specific issue cannot be verified on the US machine alone.
