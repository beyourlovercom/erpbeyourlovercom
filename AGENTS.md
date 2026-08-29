# REPOSITORY EXECUTION GOVERNANCE GATE

Before any formal task, read:

- `AI/EXECUTION_ROLE_MODEL.md`
- `AI/REPOSITORY_MANIFEST.md`
- `AI/LARGE_PROJECT_EXECUTION_STANDARD.md`
- `AI/PARALLEL_WORK_EXECUTION_STANDARD.md`
- `AI/CURRENT_CONTEXT.md`

Role authority is governed by the Owner-approved execution-provider routing below:

- GPT Web + Owner: architecture, design, diagnosis, root cause, next-step decisions, and bounded exact remediation/implementation when behavior and scope are already approved.
- US Claude Code: primary implementation provider for large new modules, broad cross-file implementation, complex integrations, materially complex new schema/data-model work, and high-complexity refactors.
- China Codex CLI: test, verification and evidence only on exact GitHub revisions.
- GitHub: unique source of truth and only code synchronization path.

Codex must not modify tracked files, fix failures, commit, push, open implementation PRs, design or diagnose. Claude must not invent architecture, diagnose root cause, widen scope or choose the next Work Package. GPT bounded implementation must not widen approved product scope or bypass Owner merge/release gates.

# HIGHEST EXECUTION PROVIDER / MODEL BUDGET GATE

**OWNER_APPROVED / ACTIVE. This gate supersedes historical wording that required Claude for every tracked-code implementation.**

Default provider selection must minimize unnecessary Claude context/token consumption while preserving correctness and verification:

- Use **GPT Web + US Remote Desktop Commander directly** for bounded, well-understood remediation where architecture/business behavior is already approved or frozen and the change can be verified locally.
- Typical GPT-direct work includes UI/CSS/layout/spacing changes; labels and page-action placement; bounded form/control changes; small bug fixes with an established root cause; exact permission enforcement following an approved policy; test-harness/assertion fixes; TypeScript/typecheck/build fixes; architecture/hash baseline updates for directly changed files; UAT remediation; small route/controller/view corrections; governance/documentation edits; and similarly narrow follow-up changes that do not require new architecture.
- Do **not** start a new Claude session merely for a one/few-file exact remediation when GPT can safely implement and verify it.
- Use **US Codex CLI** for frozen, deterministic low-to-medium complexity implementation where architecture/business behavior is already specified and no new design decision is required.
- Use **US Claude Code** for complex/high-ambiguity implementation, materially interdependent schema/data-model work, complex external integrations, large cross-module refactors, concurrency/state-machine work, or other implementation whose ambiguity materially benefits from Claude.
- When Claude is used, keep the same approved Work Package/session context where practical. Avoid repeated fresh Claude sessions for implementation → remediation → cleanup → hardening when the follow-up is bounded enough for GPT.
- Claude usage requires a concrete complexity reason, not historical habit. If GPT can implement the approved change safely and run the required gates, GPT is the default for the remediation.
- GPT-direct implementation may perform bounded local edits, tests, typecheck, build, runtime/UAT verification, and authorized Git delivery for that Work Package. Commit/push/PR/merge/release authority remains subject to existing Owner and Work Package gates; merge and production release still require explicit Owner authorization.
- US Codex may be an implementation provider under `AI/EXECUTION_ROLE_MODEL.md` v1.3. China Mac is not part of the default development/verification chain.

Provider choice must never weaken canonical data truth, authorization/security, migrations, accounting, menu governance, UAT evidence, or exact-revision verification requirements.

# HIGHEST IMPLEMENTATION COLLISION / HANDOFF GATE

**OWNER_APPROVED / ACTIVE. Applies globally, including all future Customer work.**

The canonical implementation contract is:

`ONE_WORK_PACKAGE_ONE_BRANCH_ONE_PR + ONE_ACTIVE_IMPLEMENTATION_PROVIDER_PER_WORKTREE`.

Mandatory rules:

- **SAME_FILE_PARALLEL_IMPLEMENTATION = FORBIDDEN.** GPT, US Codex and Claude must never concurrently edit the same tracked file, and must not concurrently implement in the same worktree.
- A worktree has exactly one active implementation provider at a time: GPT+DC, US Codex CLI, or Claude Code.
- Provider handoff is serialized. Before handoff, stop the outgoing provider, capture current branch/HEAD plus `git status`/`git diff`, and make the current working tree the only implementation truth. The incoming provider must read that exact state before editing.
- A stale Claude session must never resume and overwrite later GPT remediation. If Claude returns after GPT has changed the worktree, Claude must refresh/re-read the current diff and approved contract first; historical session state is not code authority.
- A Codex verification-only session verifies the requested exact GitHub SHA/branch and does not repair failures. A US Codex implementation session follows its frozen Work Package and mutates only its dedicated worktree.
- Sequential Work Packages for the same module must start from the latest merged `origin/main` after the prior package is merged, unless an explicitly documented dependency branch is approved. Do not start a later package from an obsolete pre-UAT/pre-merge baseline.
- Owner-UAT-PASS / COMPLETE / FROZEN UI and behavior are regression baselines. Later Work Packages must extend them incrementally. Redesigning, removing, reverting, or replacing frozen behavior requires explicit Owner approval.
- Before a later package reaches Owner UAT, perform a regression diff/audit against the frozen baseline for touched pages/files. Missing approved controls, changed permissions, lost layout behavior, removed translations, or reverted page-action placement are failures, not acceptable reinterpretations.
- Parallel implementation is allowed only in separate worktrees with declared non-overlapping ownership or an approved shared-foundation extraction. If two streams need the same file, serialize them or extract/merge the shared foundation first.
- GitHub remains the final synchronization boundary. Never use agent memory, an old local session, or machine-to-machine copying as authority over the current branch/worktree and GitHub history.

These collision/handoff rules do not alter Owner UAT, explicit merge authorization, production release authorization, security, data-truth, migration, or verification gates.

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

# GLOBAL DATA GRID VI V1.1 GATE

**Rule ID: `BYL_GLOBAL_DATA_GRID_VI_V1_1`. Implementation baseline: OWNER_UAT PASS / COMPLETE / FROZEN / CLOSED. The rule remains active for BYL ERP and is portable to other projects when explicitly invoked.**

For every primary business list or data-workbench page, default to the Global Data Grid VI pattern unless a documented exception applies. Simple embedded tables with only a few stable columns may remain Simple Tables.

The canonical layout is: identity column fixed at the left, configurable data columns in the middle, and row actions fixed at the right. The compact table-header far-right `…` is the canonical Column Settings trigger. Column Settings supports show/hide, drag reorder and restore defaults without permanently consuming vertical space. Wide grids may scroll horizontally.

Every displayed field must come from an explicit canonical authority or an explicit relation/aggregate/virtual projection. Never copy Order, Shipment, Inventory, Finance, Customer or other business truth into another resource merely to make a grid easier to render, and never fabricate unavailable fields for presentation.

Primary grids that can grow materially must use server-side pagination/filtering/sorting. Sort/filter keys are allowlisted logical keys; arbitrary database-field passthrough is forbidden. N+1 row loading is forbidden.

Personal grid layout is a user-account preference, not a device preference. In BYL ERP, canonical persistence is the single generic `user_ui_preferences` authority keyed by `(user_id, namespace)` with versioned namespaces such as `grid.{resource}.{view}.v{version}`. Browser storage is cache/migration fallback only and must never become canonical authority. Do not create per-module Grid preference tables or a database-backed column registry.

Stored preferences may contain UI choices only. Current code/config remains authoritative for allowed columns, labels, source mappings, formatting and permissions. A Preference Sanitizer must remove unknown/duplicate/disallowed keys, restore required/default columns, and must never allow a saved preference to bypass current permissions.

The complete canonical contract is `docs/10_Planning_Roadmap/26_BYL_ERP_GLOBAL_DATA_GRID_VI_V1_1.md`.
Cross-project canonical source locator: `beyourlovercom/erpbeyourlovercom@main:docs/10_Planning_Roadmap/26_BYL_ERP_GLOBAL_DATA_GRID_VI_V1_1.md`.

## Cross-project invocation

`BYL_GLOBAL_DATA_GRID_VI_V1_1` is a portable, framework-agnostic UI/data contract. Any other project may adopt it by explicitly referencing this Rule ID in that project's `AGENTS.md`, work package, architecture document, or Owner instruction. Once invoked, preserve the Visual, Column, Query and Preference contracts while adapting implementation details to that project's framework, database and authentication model.

Do not assume Vue, Laravel, `useAccountGridPreferences`, or the literal `user_ui_preferences` table name outside BYL ERP. A different project may implement equivalent adapters and persistence, but it must preserve one generic account-preference authority rather than per-module preference stores. If a target project has no authenticated user/account concept, it cannot claim full V1.1 account-sync compliance unless the Owner explicitly defines an equivalent identity authority.

When a target project's explicit Owner-approved local rule conflicts with this portable contract, do not silently override either rule; stop and surface the conflict for Owner resolution.
