---
type: work-package
project: BYL ERP
work_package_id: ERP-KOL-M1-CRM-001
title: KOL M1.1 CRM — Opportunities + Communications
status: FORMALIZED / OWNER AUTHORIZED
updated: 2026-08-28
execution_provider: CLAUDE_CODE
---

# ERP-KOL-M1-CRM-001

## Objective
Deliver the frozen KOL M1.1 CRM capability on top of merged M1.0: `合作机会` + `沟通跟进`, preserving one canonical `kol_id`, Owner/data-scope enforcement, auditability, and the approved KOL menu.

## Authority / baseline
- Owner sequence: M1.0 PASS -> M1.1 CRM -> M1.2 Collaboration/Fulfillment -> M1.3 Content -> M1.4 Finance/Affiliate.
- M1.0 is merged by PR #103; shared AppShell back-navigation is merged by PR #107.
- BASELINE: `main@50ecf4de1f270efdd95b44c5a301db5cb0a1f77b`.
- Canonical contract: `docs/10_Planning_Roadmap/21_ERP_KOL_MODULE_V1_CONTRACT.md`.
- Form authority: `docs/10_Planning_Roadmap/18_BYL_ERP_FORM_DESIGN_STANDARD_V1.md`.
- Menu authority: `menu/README.md` + `menu/APPROVED_MENU.json`.

## Frozen scope
### Opportunities
Implement `kol_opportunities` with:
- `id`, `kol_id`, `owner_id`;
- `stage` as a queryable string value managed by application/UI options, not a DB enum;
- `proposed_cooperation_type` nullable;
- `quoted_amount` nullable + `currency` nullable;
- `next_follow_up_at` nullable;
- `status`, `notes`, timestamps.

Operational UI:
- `/kol/opportunities` dense list with search/filter/sort/pagination;
- create, show and edit level-3 pages using ERP Form VI;
- filters for Owner, stage, status, cooperation type, follow-up due state;
- KOL identity links back to canonical KOL detail.

Default behavior:
- new opportunity Owner defaults to the KOL Owner;
- specialists remain own-scope only;
- Admin/Operations may reassign to authorized KOL operators;
- monetary values remain optional and do not create Finance truth.

### Communications
Implement `kol_communications` with:
- `id`, `kol_id`, `opportunity_id` nullable, `collaboration_id` nullable;
- `owner_id`, `channel`, `occurred_at`;
- `result` nullable, `notes` nullable;
- `next_follow_up_at` nullable;
- timestamps.

Initial foreign-KOL communication channels exposed in UI:
`email | whatsapp | phone | instagram | tiktok | youtube | x | other`.

Operational UI:
- `/kol/communications` timeline/list with search/filter/sort/pagination;
- create and edit level-3 forms using ERP Form VI;
- filter by KOL, Owner, channel, linked opportunity and follow-up due state;
- KOL detail projects recent opportunities, recent communications and next follow-up without duplicating facts into `kols`.

### Follow-up semantics
- `待跟进` is driven by actionable `next_follow_up_at <= now()` on open CRM facts when such facts exist.
- `沟通中` means the KOL has an active/open opportunity or recent active communication state; do not infer `已合作` from communication alone.
- CRM records never create a second KOL, Order, Collaboration, Commission or Affiliate truth.

## Authorization / data scope
Reuse the M1.0 `KolScope` authority. Every opportunity/communication read and mutation must first resolve its canonical KOL and enforce the user's KOL data scope server-side.

- Administrator: all KOL CRM data + operate.
- Operations: all KOL CRM data + operate.
- KOL Specialist: own-KOL CRM data only + operate within own scope.
- Owner reassignment remains Admin/Operations only.
- UI filters and hidden controls are not authorization.
- Audit create/update actions with canonical KOL and CRM entity identifiers.

## Menu impact
Owner-approved menu codes already exist as planned placeholders:
- `kol-opportunities` -> `合作机会` -> `/kol/opportunities`
- `kol-communications` -> `沟通跟进` -> `/kol/communications`

Implementation must atomically update `menu/APPROVED_MENU.json`, runtime navigation, menu tests and this Work Package from planned/placeholder to implemented. Labels/order/slugs/permissions stay unchanged unless Owner separately approves a change.

## Explicit exclusions
NO collaboration engine, sample/shipment projection, content/publication engine, commission/payment/Affiliate engine, public social ingestion, OAuth, Audience Analytics, scoring/Intelligence, automated outreach, email/WhatsApp sending integration, production deployment or production migration.

## Acceptance criteria
1. Opportunity and communication schemas migrate cleanly on a fresh local database.
2. Lists/forms/detail pages use canonical KOL relations and Chinese UI labels.
3. Admin/Operations can see all scoped CRM data; Specialist cannot read or mutate another Owner's KOL CRM facts.
4. Opportunity Owner defaults correctly and reassignment is permission-gated.
5. Communication may link to an opportunity but never requires one.
6. Due follow-up facts are filterable and visible on the relevant KOL detail/projection.
7. `合作机会` and `沟通跟进` menu items become functional without changing approved labels/order/slugs.
8. No M1.2-M1.4 or Intelligence engine is introduced.
9. Existing M1.0 KOL behavior and global AppShell regressions remain green.
10. US Local UAT is prepared with `admin/password` and realistic foreign-KOL CRM sample facts.

## Validation
VERIFICATION_TIER = OWNER_OVERRIDE
Reason: schema/migration work requires full US migration rehearsal and regression; China verification remains special-case only under the standing Owner policy unless a concrete cross-machine defect appears.

Required before Owner UAT:
- focused PHP feature tests for KOL CRM + authorization;
- focused frontend Vitest for new KOL CRM pages/forms;
- fresh SQLite migration + LocalUatSeeder/CRM seed rehearsal;
- full PHPUnit suite;
- full Vitest suite;
- `npm run typecheck`;
- `npm run build`;
- `git diff --check`;
- US browser smoke for Admin + Specialist scope.

# PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration [COMPLETE] -> MVP1 Existing Operations -> ERP-INQUIRY-M1 -> milestone release -> MVP1.1 WooCommerce Inbound -> MVP1.2 Tracking Outbound -> MVP1.3 Sync Orchestration -> Product/Media -> Content/SEO -> Full Order Workspace -> Logistics OS -> KOL OS -> Inventory/Procurement -> Accounting/ROI -> Integrations -> Production Hardening
CURRENT_TRACK_PHASE_MAP: KOL M1.0 Foundation [COMPLETE] -> KOL M1.1 CRM [CURRENT] -> KOL M1.2 Collaboration/Fulfillment -> KOL M1.3 Content -> KOL M1.4 Finance/Affiliate
COMPLETED_PHASES: KOL M1.0 / PR #103; AppShell back navigation / PR #107
CURRENT_PHASE: KOL M1.1 CRM
CURRENT_WORK_PACKAGE: ERP-KOL-M1-CRM-001
PACKAGE_STATUS: FORMALIZED / OWNER AUTHORIZED
WAITING_PHASES: KOL M1.2, M1.3, M1.4
BASELINE: main@50ecf4de1f270efdd95b44c5a301db5cb0a1f77b
GITHUB_STATE: dedicated branch/worktree; Draft delivery PR to be opened
SOURCE_CONFLICTS: NONE WITH EVIDENCE; GitHub main and merged KOL contract agree on M1.1 sequence
GLOBAL_GATE: PASS
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: Claude implementation + US verification + Owner UAT
EVIDENCE_AS_OF: 2026-08-28

# ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 4.0h planning value; 3.0-5.0h range
KNOWN_CURRENT_TRACK_REMAINING: M1.1 3-5h + M1.2 4-6h + M1.3 3-5h + M1.4 4-6h + closure buffer 2-3h
ARCHITECTURE_GATE_REMAINING: 0h for M1.1 contract; implementation/validation remains
MVP1_TOTAL_ESTIMATE: historical value unchanged
MVP1_PLANNING_BASELINE: historical value unchanged
MVP1_MAXIMUM_ENVELOPE: historical value unchanged
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; KOL track is not used to recalculate MVP1
POST_MVP_KNOWN_INCREMENT: KOL M1.1-M1.4 as stated above
LONG_TERM_CUMULATIVE_RANGE: unchanged from canonical roadmap
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE
ESTIMATE_CONFIDENCE: MEDIUM-HIGH
ESTIMATE_AS_OF: 2026-08-28
ESTIMATE_METHOD: two relational CRM entities + CRUD/list/filter UI + authorization + migration/regression/UAT preparation

# ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.5h
CORE_EXECUTION: 2.25h
TEST_AND_VALIDATION: 0.75h
REMEDIATION_BUFFER: 0.5h
ESTIMATED_EFFECTIVE_HOURS: 4.0h
ESTIMATE_RANGE: 3.0-5.0h
ESTIMATE_BASIS: two bounded CRM entities, KOL-scope authorization, Form VI pages, approved menu activation, regression and US Local UAT preparation
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: INCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: INCLUDED
DEPLOYMENT: EXCLUDED
