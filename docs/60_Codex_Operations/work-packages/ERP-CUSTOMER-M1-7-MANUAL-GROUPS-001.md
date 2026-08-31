---
type: work-package
project: BYL ERP
work_package_id: ERP-CUSTOMER-M1-7-MANUAL-GROUPS-001
status: OWNER_UAT_PASS
execution_provider: US_CODEX_CLI
verification_tier: HIGH_RISK
baseline: origin/main@79ee52cad5c642ea40533efcb6fa44fdfc5700f3
owner_uat_required: YES
git_delivery_authorized: YES
merge_authorized: YES
production_deployment_authorized: NO
---

# Customer M1.7 — Manual Groups

## Dependency
Customer M1.6 / PR #129 is merged. US canonical main is synchronized to `79ee52cad5c642ea40533efcb6fa44fdfc5700f3`.

## Objective
Add persistent manually managed Customer Groups without duplicating or mutating the existing system segmentation authority.

## Frozen architecture boundary
- Manual Group is a Customer-owned managed business object.
- Do not reuse KOL/Orders `saved_views`; those represent saved filter definitions, not Customer membership truth.
- System segments from M1.2/M1.5/M1.6 remain computed/read-only and are not stored as manual memberships.
- Customer remains canonical customer identity; group membership references `customers.id` only.
- No duplicated Customer profile fields in group tables.

## Proposed M1.7 data contract
- `customer_groups`: id, name, optional description, timestamps.
- `customer_group_memberships`: customer_group_id, customer_id, added_by nullable/audit-safe, timestamps.
- Unique membership constraint: `(customer_group_id, customer_id)`.
- Deleting a group deletes memberships only, never Customers.
- Archiving/deleting a Customer must not create dangling memberships.

## Proposed M1.7 experience
1. Customer Groups workspace keeps all existing system segment shortcuts.
2. Add a clearly separated Manual Groups area in the same workspace; no duplicate third-level Customer page.
3. Authorized operator can create, rename and delete a manual group.
4. Opening a manual group filters the existing Customer grid to its persisted members.
5. Add Customers to a manual group through searchable multi-select / bounded add flow.
6. Remove Customer from current manual group without deleting or archiving the Customer.
7. Customer List group filter can select manual groups as well as system segments.
8. Empty manual group has an explicit empty state and Add Customers action.

## Permissions
- `customers.view`: view manual groups and memberships.
- `customers.operate`: create/rename/delete groups and add/remove memberships.
- No new RBAC vocabulary in M1.7 unless existing permission architecture proves insufficient.

## Explicit exclusions
- No CSV/XLSX import/export: Customer M1.8.
- No rule-based auto-membership inside Manual Groups.
- No personal/private per-user groups in M1.7.
- No saved filter view reuse.
- No bulk Marketing action, campaign dispatch, automation, tagging engine or workflow trigger.
- No Customer360 closure work reserved for M1.9.

## Acceptance contract
- CRUD for manual groups is persistent and permission-gated.
- Add/remove membership is idempotent and cannot duplicate a membership.
- Manual group membership does not change when orders, shipment, spend or recency facts change.
- Existing M1.2/M1.5/M1.6 system segments remain semantically unchanged.
- Manual group deletion never deletes Customer records.
- Customer deletion/archive lifecycle cannot leave invalid membership rows.
- Customer Groups and Customer List can both select/open manual groups.
- Focused backend/frontend tests, typecheck, build, diff-check and Owner UAT must pass.

## Execution gates
OWNER_SCOPE_APPROVAL: PASS — Owner explicitly directed execution of Customer M1.7 on 2026-08-30.
IMPLEMENTATION_AUTHORIZATION: GRANTED — Owner explicitly authorized execution on 2026-08-30.
GIT_DELIVERY_AUTHORIZATION: GRANTED — Owner explicitly authorized M1.7 commit / push / PR creation on 2026-08-31. This does not authorize merge or deployment.
MERGE_AUTHORIZATION: GRANTED — Owner explicitly authorized merge of PR #130 on 2026-08-31. This does not authorize deployment, tag or release.
PRODUCTION_DEPLOYMENT_AUTHORIZATION: NOT GRANTED

NEXT_GATE: Exact-head merge of PR #130 under explicit Owner merge authorization. Production deployment, tag and release remain separately unauthorized.

VERIFICATION_TIER_UPGRADE: HIGH_RISK — M1.7 authors new database schema/migration; stronger US-host migration and rollback verification required before Owner UAT. Secondary host remains not required by current repository standard.

OWNER_UAT_STATUS: PASS — Owner confirmed M1.7 functions are normal after reviewing the authenticated US-local Manual Groups UAT; visual consolidation is deferred to Customer M1.7A and does not block M1.7 functional acceptance.

## Deferred visual follow-up — Customer M1.7A
Owner accepted M1.7 functional behavior and explicitly deferred the Groups visual redesign to `Customer M1.7A — Groups UI Consolidation`. M1.7A must use the approved rendered concept as the visual baseline: a left-side unified Groups navigator, separate collapsible System Groups and Manual Groups sections, manual group create/rename/delete/manage actions, click-to-switch the single Customer Grid, and a collapsible entire Groups sidebar to maximize table space. This is a visual/experience follow-up and must not reopen M1.7 data semantics or membership behavior.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate [COMPLETE] → MVP1 Existing Operations / Tencent Docs Replacement → ERP-INQUIRY-M1 B2B Inquiry Foundation → ERP-INQUIRY-M1 Local Milestone UAT → Milestone-gated Remote Release → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Production Hardening / Expansion
CURRENT_TRACK_PHASE_MAP: Customer M1.1 Experience [MERGED] → M1.2 Segmentation Foundation [MERGED] → M1.3 Subscriber Integration [MERGED] → M1.4 Support Integration [MERGED] → M1.5 Shipment Projection [MERGED] → M1.6 Advanced Segmentation [MERGED PR #129] → M1.7 Manual Groups [CURRENT] → M1.8 Full Import/Export [WAITING] → M1.9 Customer360 Closure [WAITING]
COMPLETED_PHASES: Customer M1.1–M1.6 merged; M1.7 scope/implementation authorization granted and implementation in local verification.
CURRENT_PHASE: Customer M1.7 Manual Groups — Owner UAT.
CURRENT_WORK_PACKAGE: ERP-CUSTOMER-M1-7-MANUAL-GROUPS-001
PACKAGE_STATUS: IMPLEMENTATION_COMPLETE / HIGH_RISK_VERIFICATION_PASS / OWNER_UAT_PASS / GIT_DELIVERY_AUTHORIZED / MERGE_AUTHORIZED / DEPLOYMENT_NOT_AUTHORIZED.
WAITING_PHASES: Customer M1.7 Owner UAT and Git delivery gates; M1.8 Full Import/Export; M1.9 Customer360 Closure.
BASELINE: origin/main@79ee52cad5c642ea40533efcb6fa44fdfc5700f3
GITHUB_STATE: M1.7 branch/worktree local only; no M1.7 commit, push or PR authorized or created.
SOURCE_CONFLICTS: AI/CURRENT_CONTEXT.md and historical program-roadmap documents retain stale earlier track selection; current Owner directive explicitly selects Customer M1.7 and merged GitHub main supplies the bounded baseline. Architecture suite also has 3 pre-existing KOL/file-manifest drift failures reproduced on baseline main; M1.7 does not normalize them.
GLOBAL_GATE: HIGH_RISK US-local verification PASS; OWNER_UAT=PASS; Git delivery authorized; Owner merge authorization granted for PR #130. Deployment remains separately gated.
PRODUCTION_DEPLOYMENT_STATUS: NO — no production deployment, migration, tag or release authorized for M1.7.
NEXT_DEPENDENCY: Owner UAT → separate Owner Git delivery authorization.
EVIDENCE_AS_OF: 2026-08-30; current Owner directive, main@79ee52cad5c642ea40533efcb6fa44fdfc5700f3, M1.7 worktree diff, US-local verification evidence.

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: UNKNOWN WITH EVIDENCE — no accepted M1.7 planning-hours estimate was issued in this Work Package before implementation.
KNOWN_CURRENT_TRACK_REMAINING: Customer M1.8 Full Import/Export and M1.9 Customer360 Closure are frozen next phases; hours UNKNOWN WITH EVIDENCE.
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE — no accepted remaining-hours ledger supplied for this bounded package.
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE — not recalculated by M1.7.
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE — no authoritative total planning value is established by this package.
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE — not established by this package.
MVP1_REMAINING: UNKNOWN WITH EVIDENCE — accepted actual-hours ledger/overlap map not supplied.
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE — outside M1.7 scope.
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE — outside M1.7 scope.
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE — no safe arithmetic basis in this package.
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — no accepted Work Log ledger supplied.
ESTIMATE_CONFIDENCE: UNKNOWN — no issued M1.7 hours estimate.
ESTIMATE_AS_OF: 2026-08-30
ESTIMATE_METHOD: No accepted Work Log or issued package-hours estimate; unknown values preserved rather than invented.

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: UNKNOWN
CORE_EXECUTION: UNKNOWN
TEST_AND_VALIDATION: UNKNOWN
REMEDIATION_BUFFER: UNKNOWN
ESTIMATED_EFFECTIVE_HOURS: UNKNOWN
ESTIMATE_RANGE: UNKNOWN
ESTIMATE_BASIS: No accepted M1.7 hours estimate was issued before implementation; repository evidence does not support retrospective invention.
OWNER_REVIEW: INCLUDED — Owner UAT is an explicit acceptance gate for the Owner-directed M1.7 execution.
MERGE: EXCLUDED — separately requires explicit Owner authorization after Git delivery.
MIGRATION: INCLUDED — migration file authoring and isolated US-local verification are part of the explicitly authorized M1.7 schema contract; production migration is not authorized.
MIGRATION_FILE_AUTHORING: INCLUDED — customer_groups/customer_group_memberships schema is frozen M1.7 scope.
MIGRATION_EXECUTION: INCLUDED — isolated US-local verification only; remote/production migration remains unauthorized.
UAT: INCLUDED — Owner-directed M1.7 execution includes the frozen Owner UAT acceptance gate.
DEPLOYMENT: EXCLUDED — no production deployment authorization.
