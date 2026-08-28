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

# HIGHEST UI / FORM DESIGN GATE

For any BYL ERP create/edit/data-entry form task, the highest UI/form authority is:

- `docs/10_Planning_Roadmap/18_BYL_ERP_FORM_DESIGN_STANDARD_V1.md`
- Page-specific form specifications such as `docs/10_Planning_Roadmap/19_PRODUCT_EDIT_V2_UI_SPEC.md`

These rules are OWNER_APPROVED and mandatory unless the Owner explicitly approves an exception. They govern field grouping, VI, spacing, controls, responsive behavior, validation presentation, action hierarchy, and destructive-action placement. Existing developer-style forms must migrate toward this standard when touched.

This UI/form gate does not override repository security, authorization, canonical data-truth, accounting, menu, or execution-governance gates. Where a visual request would violate those higher-order correctness constraints, preserve correctness and stop for Owner/GPT direction.

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

# HIGHEST PAGE-LEVEL ACTION PLACEMENT GATE

**Implementation baseline: OWNER_UAT PASS / COMPLETE / FROZEN / CLOSED (Batch 1 + Batch 2). The rule remains active and mandatory for future work.**

For every BYL ERP page, all page-level actions must be consolidated into one canonical Page Header Action Zone at the upper-right of the page header. This applies to Create, Edit, Show/Detail, Index/List, dashboards, import/export workflows, and other operational pages.

Page-level actions include Save/Create/Update, Cancel, Back/Back to List, Edit, New/Create, Import, Export, Archive/Restore, Delete, and any other action whose scope is the current page, current primary record, or current workflow. Do not duplicate or scatter those actions at the page bottom, beside unrelated cards, or across multiple page regions.

Within the upper-right cluster, navigation/cancel actions come first, secondary business actions next, destructive actions remain visually separated and confirmation-protected, and the single dominant primary action is rightmost. On narrow screens the same header action zone may wrap, but it must remain at the top and must not migrate to a bottom action bar.

This rule does not move table-row actions, field-local actions, filters/search controls, pagination, or modal-local actions out of their own context. Existing pages are migration candidates; whenever an existing page is materially touched, its page-level actions must be brought into compliance.

This is an OWNER-APPROVED HIGHEST UI interaction rule and applies globally unless the Owner explicitly revokes or replaces it.
