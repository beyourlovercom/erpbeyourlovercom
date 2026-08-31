---
type: work-package
project: BYL ERP
work_package_id: ERP-CUSTOMER-M1-7A-GROUPS-UI-CONSOLIDATION-001
status: OWNER_UAT_PASS
execution_provider: US_CODEX_CLI
verification_tier: DAILY
baseline: origin/main@336c3c411e022ed63e5e31e4c0187d2adf538dab
owner_uat_required: YES
git_delivery_authorized: YES
merge_authorized: YES
production_deployment_authorized: NO
---

# Customer M1.7A — Groups UI Consolidation

## Dependency
Customer M1.7 / PR #130 is merged. Canonical main and this worktree start from `336c3c411e022ed63e5e31e4c0187d2adf538dab`.

## Owner-approved visual baseline
The sole visual direction is the Owner-approved BYL ERP Customer Groups render accepted in conversation on 2026-08-31. Implementation should reproduce its layout hierarchy, density, spacing and interaction model as closely as practical inside the existing BYL ERP shell rather than inventing a second design.

## Objective
Replace the stacked top shortcuts + Manual Group card area with one unified Groups Workspace: a collapsible left group navigator and one right-side Customer Grid.

## Frozen visual contract
1. Customer Groups page is a two-pane workspace inside the existing ERP shell.
2. Left pane is the dedicated `分组` navigator; right pane is the existing Customer data workspace/grid.
3. Left pane contains two visually unified sections: `系统分组` and `手动分组`.
4. Both sections can independently collapse/expand.
5. The entire Groups navigator can collapse to a narrow rail so the Customer Grid can use maximum width.
6. Current group has a strong selected-row state matching the approved render.
7. Every visible group shows its customer count.
8. System groups use consistent icons/counts and remain computed/read-only.
9. Manual groups use the same row language but preserve M1.7 create/rename/delete/member-management semantics.
10. Manual section exposes a compact `+` / create control; destructive actions are not permanently visually dominant.
11. The right header shows current group name, group type badge (`系统分组` or `手动分组`), description where applicable, and current member count.
12. Manual-group header exposes Add Customer / Rename / Delete / overflow actions when `customers.operate` is granted.
13. System-group header must not expose membership editing or delete actions.
14. Search/filter/column-setting controls sit above the grid in one compact toolbar.
15. Only one Customer Grid exists; selecting another group changes query/context, not page architecture.
16. Pagination/table density should prioritize maximum visible rows and horizontal data space.

## Behavior contract
- `系统分组` uses existing M1.2/M1.5/M1.6 computed segment semantics unchanged.
- `手动分组` uses M1.7 `customer_groups` + `customer_group_memberships` unchanged.
- Clicking any group switches the right grid to that group.
- Counts must represent the same membership/query semantics as opening that group; no approximate or duplicated truth.
- Manual-group CRUD and add/remove membership remain permission-gated by existing Customer permissions.
- Existing account-synced Customer Grid column preferences remain authoritative.
- Existing Customer List page remains a separate list experience; this package restructures the Customer Groups workspace only unless a shared component change is strictly necessary.

## Collapse/state contract
- `系统分组` section collapse state is independent from `手动分组`.
- Whole navigator collapse is independent from section collapse states.
- Collapse/expand is visual state only and must never mutate group data or filters.
- State may be persisted through the existing account UI-preference mechanism if it can be done without new schema; otherwise use bounded local UI state. No new persistence table.

## Explicit exclusions
- No migration/schema changes.
- No changes to M1.7 membership truth.
- No new system segmentation semantics or threshold changes.
- No M1.8 CSV/XLSX import/export work.
- No M1.9 Customer360 closure work.
- No Marketing campaign/action automation.
- No personal/private group model.
- No duplicate Saved View architecture.
- No redesign of the global ERP shell/navigation outside what is necessary to fit the Groups workspace.

## Acceptance contract
- Visual structure closely matches the Owner-approved render in desktop UAT.
- Left unified navigator shows system and manual groups, correct counts and selected state.
- Both sections independently collapse/expand.
- Entire navigator collapses/expands and right grid visibly gains width.
- Selecting system/manual groups updates the single right grid correctly.
- Manual actions are available only for authorized users and continue to work.
- System groups cannot be manually edited/deleted.
- Existing M1.2/M1.5/M1.6/M1.7 tests remain semantically green.
- Focused frontend tests cover selected state, both collapse levels, whole-sidebar collapse, group switching and permission-sensitive actions.
- Backend tests cover any count projection added for the sidebar and prove equality with existing group filter semantics.
- Typecheck, build and `git diff --check` pass.
- Owner receives an automatically authenticated US-local UAT page; bypass is disabled for UAT.

## Execution gates
OWNER_SCOPE_DIRECTION: PASS — Owner explicitly approved the rendered Groups Workspace as the M1.7A base design and directed M1.7A after M1.7 closure.
IMPLEMENTATION_AUTHORIZATION: GRANTED — Owner explicitly directed M1.7A to enter IMPLEMENTATION_AUTHORIZED and begin page replication on 2026-08-31.
GIT_DELIVERY_AUTHORIZATION: GRANTED — Owner explicitly authorized M1.7A commit / push / PR creation on 2026-08-31.
MERGE_AUTHORIZATION: GRANTED — Owner explicitly authorized merge PR #131 on 2026-08-31.
PRODUCTION_DEPLOYMENT_AUTHORIZATION: NOT GRANTED

NEXT_GATE: Exact-head merge PR #131, then close Customer M1.7A. Production deployment remains separately unauthorized.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate [COMPLETE] → MVP1 Existing Operations / Tencent Docs Replacement → ERP-INQUIRY-M1 B2B Inquiry Foundation → ERP-INQUIRY-M1 Local Milestone UAT → Milestone-gated Remote Release → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Production Hardening / Expansion
CURRENT_TRACK_PHASE_MAP: Customer M1.1–M1.7 [MERGED] → M1.7A Groups UI Consolidation [CURRENT] → M1.8 Full Import/Export [WAITING] → M1.9 Customer360 Closure [WAITING]
COMPLETED_PHASES: Customer M1.1 through M1.7 merged; PR #130 merge commit 336c3c411e022ed63e5e31e4c0187d2adf538dab.
CURRENT_PHASE: Customer M1.7A Groups UI Consolidation — merge authorized.
CURRENT_WORK_PACKAGE: ERP-CUSTOMER-M1-7A-GROUPS-UI-CONSOLIDATION-001
PACKAGE_STATUS: OWNER_UAT_PASS / GIT_DELIVERED_PR_131 / MERGE_AUTHORIZED.
WAITING_PHASES: M1.7A exact-head merge; M1.8 Full Import/Export; M1.9 Customer360 Closure.
BASELINE: origin/main@336c3c411e022ed63e5e31e4c0187d2adf538dab
GITHUB_STATE: PR #130 MERGED; M1.7A PR #131 OPEN/CLEAN from `feature/erp-customer-m1-7a-groups-ui-consolidation-001`; Owner merge authorization granted. Production deployment remains unauthorized.
SOURCE_CONFLICTS: Historical roadmap/current-context documents may retain older track labels; current Owner direction and merged GitHub main govern this bounded Customer follow-up. NONE affecting the M1.7A visual contract.
GLOBAL_GATE: M1.7A implementation, US-local verification, Owner UAT and Git delivery to PR #131 are complete. Owner has authorized exact-head merge; production deployment remains unauthorized.
PRODUCTION_DEPLOYMENT_STATUS: NO — no M1.7A production deployment, tag or release authorized.
NEXT_DEPENDENCY: Exact-head merge PR #131 → close M1.7A → M1.8 Full Import/Export.
EVIDENCE_AS_OF: 2026-08-31; Owner-approved render, PR #130 merge state, origin/main@336c3c411e022ed63e5e31e4c0187d2adf538dab.

OWNER_UAT_STATUS: PASS — Owner explicitly accepted the M1.7A Groups Workspace visual and interaction UAT on 2026-08-31. UAT ran through a real authenticated session with `ERP_LOCAL_ADMIN_BYPASS=false`.
OWNER_UAT_FIXTURE: selected manual group `2026 黑五重点客户` has 8 members; 7 manual groups, 12 Customer records and 23 linked customer orders are present for visual/density verification.
OWNER_UAT_ACCEPTED_AT: 2026-08-30 20:00:50 -0700 (2026-08-31 11:00:50 +0800).
US_LOCAL_VERIFICATION: Customer backend 51 tests / 603 assertions PASS; Customer frontend 22/22 PASS; `npm run typecheck` PASS; `npm run build` PASS; ModuleProvider 8/8 / 39 assertions PASS; `git diff --check` PASS; `public/hot` ABSENT.
ARCHITECTURE_BASELINE_CLASSIFICATION: M1.7A worktree and exact canonical main@336c3c411e022ed63e5e31e4c0187d2adf538dab both report 3 failed / 5 passed / 86 assertions in ModuleArchitectureTest; M1.7A-owned architecture regression = 0.
GIT_DELIVERY_AUTHORIZATION: EXECUTED — PR #131 created from delivery commit `a9ede7e05b9dee016d48fc72c31bcc3e1496986e`.
MERGE_AUTHORIZATION: GRANTED — merge PR #131 only. PRODUCTION_DEPLOYMENT_AUTHORIZATION remains NOT GRANTED.

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: UNKNOWN WITH EVIDENCE — no accepted M1.7A hours estimate issued.
KNOWN_CURRENT_TRACK_REMAINING: M1.7A, M1.8 and M1.9 are known bounded Customer phases; hours UNKNOWN WITH EVIDENCE.
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE — not recalculated in this UI package.
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE — not recalculated by M1.7A.
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE — not established by this package.
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE — not established by this package.
MVP1_REMAINING: UNKNOWN WITH EVIDENCE — accepted actual-hours ledger/overlap map not supplied.
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE — outside M1.7A scope.
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE — outside M1.7A scope.
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE — no safe arithmetic basis in this package.
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — no accepted Work Log ledger supplied.
ESTIMATE_CONFIDENCE: UNKNOWN — no issued M1.7A hours estimate.
ESTIMATE_AS_OF: 2026-08-31
ESTIMATE_METHOD: No accepted package-hours estimate; unknown values preserved rather than invented.

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: UNKNOWN
CORE_EXECUTION: UNKNOWN
TEST_AND_VALIDATION: UNKNOWN
REMEDIATION_BUFFER: UNKNOWN
ESTIMATED_EFFECTIVE_HOURS: UNKNOWN
ESTIMATE_RANGE: UNKNOWN
ESTIMATE_BASIS: No accepted M1.7A hours estimate issued.
OWNER_REVIEW: INCLUDED — Owner UAT required.
MERGE: EXCLUDED — separately gated.
MIGRATION: EXCLUDED — UI consolidation package forbids schema changes.
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: INCLUDED — Owner-directed visual acceptance required.
DEPLOYMENT: EXCLUDED — no production deployment authorization.
