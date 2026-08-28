---
type: work-package
project: BYL ERP
work_package_id: ERP-SHELL-BACK-NAVIGATION-001
title: Global Shell Back Navigation
status: FORMALIZED / OWNER AUTHORIZED
updated: 2026-08-28
execution_provider: CLAUDE_CODE
---

# ERP-SHELL-BACK-NAVIGATION-001

## Objective
Replace the legacy top-left mobile navigation hamburger with a deterministic hierarchy-aware back control, while preserving mobile navigation access elsewhere in the TopBar.

## Owner decision
- Remove the historical top-left `shell-mobile-toggle` hamburger from its current location.
- On pages with a navigable parent breadcrumb, show a compact back control in that left position.
- The control means `返回上一层` and navigates to the nearest prior breadcrumb with an `href`.
- Do not use `history.back()` because it may leave ERP or return to an unrelated page.
- If no parent breadcrumb exists, render no back control.

## Mobile navigation preservation
The sidebar becomes off-canvas below 800px, so navigation access must not be removed. Move the hamburger to the TopBar trailing/right side on narrow screens only. Preserve the existing toggle state, backdrop, `aria-expanded`, and open/close behavior.

## Scope
Expected implementation files:
- `resources/js/Components/AppShell/TopBar.vue`
- `resources/js/Layouts/AuthenticatedLayout.vue` only if event/props wiring requires it
- `resources/css/shell.css`
- focused AppShell frontend tests

No level-1/level-2 menu labels, routes, order, permissions or approved menu baseline are changed.

## Dependencies / parallel safety
BASELINE: `main@6eb5cc595469f0efb84aa4523e13385b093c571d`
DEPENDENCIES: NONE
DEPENDENCY_TYPE: NONE
DEPENDENCY_STATUS: CLEAR
PARALLEL_SAFE: YES — dedicated shared-shell worktree; no KOL worktree mutation.
FOUNDATION_CHANGE_REQUIRED: YES — this Work Package is the extracted shared-shell foundation change.

## Acceptance criteria
1. The old top-left hamburger is absent globally.
2. A parent-aware back control appears when a prior breadcrumb has an `href`.
3. Back navigation uses that ERP parent href, never browser history.
4. Root pages without a parent do not show a fake back action.
5. At <=800px, the mobile navigation hamburger remains available at the TopBar trailing/right side.
6. Existing breadcrumb links/title remain unchanged.
7. Keyboard focus and accessible labels remain valid.

## Validation
VERIFICATION_TIER = DAILY
Required on US Mac:
- focused TopBar/AppShell Vitest coverage
- full Vitest if focused coverage passes
- `npm run typecheck`
- `npm run build`
- `git diff --check`
- local responsive smoke at desktop and <=800px

No China verification is required under the Owner standing policy. No production deployment is authorized.

# PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration [COMPLETE] -> MVP1 Existing Operations -> ERP-INQUIRY-M1 -> milestone release -> MVP1.1 WooCommerce Inbound -> MVP1.2 Tracking Outbound -> MVP1.3 Sync Orchestration -> Product/Media -> Content/SEO -> Full Order Workspace -> Logistics OS -> KOL OS -> Inventory/Procurement -> Accounting/ROI -> Integrations -> Production Hardening
CURRENT_TRACK_PHASE_MAP: Shared AppShell refinement [CURRENT] -> Owner review/merge gate
COMPLETED_PHASES: AppShell foundation exists on main
CURRENT_PHASE: Global shell navigation refinement
CURRENT_WORK_PACKAGE: ERP-SHELL-BACK-NAVIGATION-001
PACKAGE_STATUS: FORMALIZED / OWNER AUTHORIZED
WAITING_PHASES: Owner review / merge
BASELINE: main@6eb5cc595469f0efb84aa4523e13385b093c571d
GITHUB_STATE: dedicated branch/worktree created; Draft PR pending
SOURCE_CONFLICTS: AI/CURRENT_CONTEXT.md main SHA is stale against verified GitHub main; task baseline uses verified GitHub main
GLOBAL_GATE: PASS
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: Claude implementation + US verification
EVIDENCE_AS_OF: 2026-08-28

# ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 1.5h planning value; 1.0-2.5h range
KNOWN_CURRENT_TRACK_REMAINING: this package only
ARCHITECTURE_GATE_REMAINING: 0h global gate; package validation remains
MVP1_TOTAL_ESTIMATE: historical value unchanged
MVP1_PLANNING_BASELINE: historical value unchanged
MVP1_MAXIMUM_ENVELOPE: historical value unchanged
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; this shared-shell refinement is not used to recalculate MVP1
POST_MVP_KNOWN_INCREMENT: NONE WITH EVIDENCE
LONG_TERM_CUMULATIVE_RANGE: unchanged from canonical roadmap
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE
ESTIMATE_CONFIDENCE: HIGH
ESTIMATE_AS_OF: 2026-08-28
ESTIMATE_METHOD: bounded shared Vue/CSS change + focused regression + local responsive smoke

# ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.25h
CORE_EXECUTION: 0.75h
TEST_AND_VALIDATION: 0.35h
REMEDIATION_BUFFER: 0.15h
ESTIMATED_EFFECTIVE_HOURS: 1.5h
ESTIMATE_RANGE: 1.0-2.5h
ESTIMATE_BASIS: one shared TopBar behavior change, responsive nav preservation, focused tests and build verification
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: INCLUDED
DEPLOYMENT: EXCLUDED
