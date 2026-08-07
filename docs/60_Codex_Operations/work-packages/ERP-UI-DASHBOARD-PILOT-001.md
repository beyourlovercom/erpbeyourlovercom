---
type: work-package
project: BYL ERP
work_package_id: ERP-UI-DASHBOARD-PILOT-001
title: ERP UI Dashboard Pilot
status: AUTHORIZED / ACTIVE
updated: 2026-08-07
---

# ERP-UI-DASHBOARD-PILOT-001 — ERP UI Dashboard Pilot

## WORK PACKAGE

```text
ID: ERP-UI-DASHBOARD-PILOT-001
TITLE: ERP UI Dashboard Pilot
STATUS: AUTHORIZED / ACTIVE
OWNER_AUTHORIZATION: Explicit Owner authorization dated 2026-08-07
STANDARD_ID: LARGE-PROJECT-EXEC-001
STANDARD_VERSION: 1.1
STAGE: IMPLEMENTATION AND VALIDATION
```

## REPOSITORY / EXECUTION IDENTITY

```text
REMOTE: origin
LOCAL_WORKTREE: /Users/a/AI/007/erpbeyourlovercom-wt-dashboard
TARGET_BRANCH: feature/erp-ui-dashboard-pilot-001
BASE: main
BASELINE_HEAD: 8da010651ddd1f664d8099f4b1319cc18865cf1c
DEPENDENCIES: NONE
DEPENDENCY_TYPE: NONE
DEPENDENCY_STATUS: NONE
PARALLEL_SAFE: YES
FOUNDATION_CHANGE_REQUIRED: NO
```

## AUTHORIZATION

```text
AUTHORIZED_FILES:
1. docs/60_Codex_Operations/work-packages/ERP-UI-DASHBOARD-PILOT-001.md
2. docs/60_Codex_Operations/logs/2026-08-07_ERP-UI-DASHBOARD-PILOT-001.md
AUTHORIZED_ACTIVITY: Stage 0A formalization followed by immediate bounded Dashboard implementation under the updated Owner authorization dated 2026-08-07.
OWNER_AUTHORIZATION_DATE: 2026-08-07
IMPLEMENTATION_AUTHORIZATION: GRANTED / immediate implementation after Stage 0A formalization
```

## AUTHORIZED ACTIONS

- Read the repository template and governing roadmap/execution documents required for formalization.
- Create or update only the two authorized Markdown evidence files listed above, plus the two authorized Dashboard implementation/test files listed in the updated contract.
- Record authorization, scope, prohibitions, acceptance criteria, validation matrix, dependency preflight, and planned implementation/validation gates.
- Record `ui-ux-pro-max` as the required UI/UX skill for later UI work.
- Verify that all four authorized files exist, contain the required formalization/implementation fields, and remain the complete worktree scope.

## PROHIBITED ACTIONS

- Do not modify application, test, layout, component, global CSS/token, menu, route, permission, backend, database, migration, dependency, package, lock, or any other documentation file.
- Do not implement UI behavior, run migrations, deploy, access credentials, access remote Dev/Staging/Production, access production databases, or write external API data.
- Do not create extra files, delete files, merge, or force-push. Commit, regular push, and one Draft Delivery PR are authorized by the Owner for this Work Package only.
- Do not claim implementation, tests, runtime validation, UAT, review, merge, or deployment evidence that has not occurred.
- Stop after the authorized Dashboard implementation and validation gates; do not expand beyond the four-file contract.

## SCOPE

- In scope: formal contract documentation and bounded implementation evidence for the ERP UI Dashboard Pilot.
- In scope: the exact four authorized files only: Dashboard page, Dashboard test, Work Package, and Work Log.
- Later implementation intent: a bounded dashboard pilot, subject to a future implementation authorization and detailed UI contract.
- Out of scope: layouts, shared components, AppShell, Sidebar, navigation, global CSS/tokens, menu, routes, permissions, backend, database/schema/migrations, dependencies/package/lock files, Orders/Logistics/Sync/Finance pages, other docs/work packages, credentials, remote/production, migration, deployment, and any extra file.

## IMPLEMENTATION / VALIDATION GATES

```text
STAGE_0A: formal Work Package and Work Log recorded; complete.
IMPLEMENTATION_GATE: PASS / updated Owner authorization dated 2026-08-07 grants immediate implementation after Stage 0A formalization.
UI_UX_GATE: later UI work must use ui-ux-pro-max and comply with the approved menu and applicable UI/accessibility guidance.
VALIDATION_GATE: focused tests, full frontend tests, typecheck, production build and diff check PASS; authenticated visual/browser QA remains blocked by the local login gate.
OWNER_REVIEW_GATE: required before Draft Delivery PR can become merge-ready.
MERGE_GATE: Owner merge authorization required; not authorized by this package.
MIGRATION_GATE: excluded and not authorized.
UAT_GATE: excluded and not authorized.
DEPLOYMENT_GATE: excluded and not authorized.
```

## ACCEPTANCE CRITERIA

- The Work Package exists at the exact authorized path and is marked `AUTHORIZED / ACTIVE`.
- The Work Package records Owner authorization dated 2026-08-07, exact worktree, branch, baseline HEAD, base branch, no dependencies, parallel safety, and no foundation requirement.
- The Work Package lists the exact authorized files and explicitly prohibits all non-dashboard and downstream actions.
- The Work Package includes a bounded scope, acceptance criteria, validation matrix, planned gates, and one Draft Delivery PR rule.
- The Work Package ends with complete `PROJECT POSITION`, `ROADMAP HOURS`, and `ESTIMATED CODEX HOURS` modules in the required order.
- The Work Log records formalization evidence only, with no unperformed implementation or test claims, and ends with the same complete mandatory footer modules.

## VALIDATION MATRIX

```text
REQUIRED_COMMANDS:
- Verify both exact authorized paths exist.
- Inspect both files for all required formalization gate fields and footer modules.
- Verify git status/diff scope contains only the four authorized files.

EXPECTED_EVIDENCE:
- FORMAL_WORK_PACKAGE_EXISTS
- WORK_LOG_EXISTS
- OWNER_AUTHORIZATION_RECORDED
- BASELINE_RECORDED
- DEPENDENCIES_DECLARED
- PARALLEL_SAFE_RECORDED
- FOUNDATION_GATE_RECORDED
- AUTHORIZED_SCOPE_RECORDED
- PROHIBITED_SCOPE_RECORDED
- ACCEPTANCE_RECORDED
- VALIDATION_PLAN_RECORDED
- PR_RULES_RECORDED
- PROJECT_POSITION_RECORDED
- ROADMAP_HOURS_RECORDED
- ESTIMATED_CODEX_HOURS_RECORDED

HARD_GATES:
- Any file outside the four authorized paths is a scope failure.
- Any implementation outside the Dashboard files, migration, deployment, credential access, merge, or deletion is prohibited. Commit, regular push, and one Draft Delivery PR are authorized for this Work Package.
- Missing or incomplete footer modules block formalization acceptance.
- Actual effective hours remain UNKNOWN WITH EVIDENCE until accepted ledger evidence exists.
```

## COMMIT / PR RULES

```text
COMMIT_MESSAGE: feat(dashboard): redesign ERP operational workspace
PR_TITLE: feat(dashboard): redesign ERP operational workspace
PR_BASE: main
PR_DRAFT_STATE: Draft Delivery PR required; one PR only for this Work Package.
MERGE_AUTHORIZED: NO
RULE: The same Work Package uses one Draft Delivery PR for contract, later authorized implementation, validation, remediation and final pre-merge evidence; no separate Work Package / Implementation / Closure PRs.
```

## FINAL REPORT FORMAT

```text
REQUIRED_FIELDS: Changed Files; Test Results; Unverified Items; Risks; HEAD; Worktree Status; exact formalization checklist; mandatory footer modules.
ALLOWED_FINAL_VERDICT: IMPLEMENTATION COMPLETE / VALIDATION EVIDENCE RECORDED / NOT MERGE-READY UNTIL INDEPENDENT REVIEW AND OWNER MERGE AUTHORIZATION
```

## PROJECT POSITION

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Manual Fulfillment / Tencent Docs Replacement → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Customer / Lead Pilot → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Dev UAT and Production Hardening
CURRENT_TRACK_PHASE_MAP: 001A Foundation Bootstrap COMPLETE / PASS → 001B Authentication / Authorization COMPLETE / PASS → 001C Application Shell COMPLETE / PASS → 001D Runtime / Audit / Sync COMPLETE / PASS → 001E Shared UI / Browser E2E COMPLETE / PASS → 001F Architecture Re-audit COMPLETE / PASS / CLOSED → MVP1 preflight COMPLETE / PASS / CLOSED → MVP1 Foundation COMPLETE / PASS / CLOSED → MVP1 Order Core COMPLETE / PASS / CLOSED → MVP1 Fulfillment active → → ERP-UI-DASHBOARD-PILOT-001 current formalization → MVP1 Manual Sync waiting → Finance Analysis waiting → UAT Release waiting
COMPLETED_PHASES: Architecture Migration / Global Architecture Gate; MVP1 preflight; MVP1 Foundation; MVP1 Order Core; completed shared UI and architecture tracks
CURRENT_PHASE: MVP1 implementation
CURRENT_WORK_PACKAGE: ERP-UI-DASHBOARD-PILOT-001
PACKAGE_STATUS: IMPLEMENTATION COMPLETE / AUTHENTICATED VISUAL QA PASS / OWNER UAT READY
WAITING_PHASES: MVP1 Manual Sync; MVP1 Finance Analysis; MVP1 UAT Release; Dev UAT; Production Hardening
BASELINE: feature/erp-ui-dashboard-pilot-001 at 8da010651ddd1f664d8099f4b1319cc18865cf1c; base main; verified 2026-08-07
GITHUB_STATE: Draft PR #81 is OPEN / DRAFT on feature/erp-ui-dashboard-pilot-001 at 49dcbf5; merge not performed.
SOURCE_CONFLICTS: NONE WITH EVIDENCE / current-context, Living Roadmap and Program Roadmap values checked; this package is newly authorized by Owner on 2026-08-07.
GLOBAL_GATE: PASS / recorded by current repository context and Program Roadmap.
PRODUCTION_DEPLOYMENT_STATUS: NO / repository context records no production deployment and production access is prohibited.
NEXT_DEPENDENCY: Owner manual visual/UAT review and merge authorization; no implementation dependency.
EVIDENCE_AS_OF: 2026-08-07
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: 2.0–4.0h; planning value 2.5h; supplied Dashboard pilot estimate
KNOWN_CURRENT_TRACK_REMAINING: MVP1 Manual Sync; MVP1 Finance Analysis; MVP1 UAT Release; dashboard implementation estimate not issued
ARCHITECTURE_GATE_REMAINING: 0h / complete
MVP1_TOTAL_ESTIMATE: 122–190h
MVP1_PLANNING_BASELINE: 166h
MVP1_MAXIMUM_ENVELOPE: 192h
MVP1_REMAINING: UNKNOWN WITH EVIDENCE / accepted actual ledger and non-overlapping package map incomplete
POST_MVP_KNOWN_INCREMENT: approximately 68–120h beyond MVP1
LONG_TERM_CUMULATIVE_RANGE: 235–402h; 603–1,018h; 1,059–1,802h; 1,595–2,714h; 1,819–3,146h; cumulative horizons never summed
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE / complete accepted actual ledger and overlap mapping are incomplete
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE / no accepted ledger evidence exists for this newly formalized package; project-wide ledger remains incomplete
ESTIMATE_CONFIDENCE: MEDIUM / bounded pilot estimate covers formalization, Dashboard implementation, automated validation and remediation; authenticated visual QA remains externally blocked
ESTIMATE_AS_OF: 2026-08-07
ESTIMATE_METHOD: supplied bounded Dashboard pilot estimate; planning values are classified separately and no unknown is converted to zero
```

## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: 0.3–0.6h; planning value 0.5h
CORE_EXECUTION: 1.0–2.0h; planning value 1.25h
TEST_AND_VALIDATION: 0.4–0.8h; planning value 0.5h
REMEDIATION_BUFFER: 0.3–0.6h; planning value 0.25h
ESTIMATED_EFFECTIVE_HOURS: 2.5h
ESTIMATE_RANGE: 2.0–4.0h
ESTIMATE_BASIS: supplied pilot estimate; component planning values reconcile to 2.5h; includes Stage 0A correction, bounded page/test implementation, frontend validation and remediation buffer; excludes merge, UAT and deployment
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```
