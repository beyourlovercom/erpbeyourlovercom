---
type: work-package
project: BYL ERP
work_package_id: ERP-CUSTOMER-M1-4-SUPPORT-INTEGRATION-001
status: MERGE_AUTHORIZED
execution_provider: CHINA_CODEX_CLI
verification_tier: HIGH_RISK
baseline: origin/main@94474846c10980cee9bbefaacf1f8cd3bbbefcf2
owner_uat_required: YES
git_delivery_authorized: YES
production_deployment_authorized: NO
---

# Customer M1.4 — Support Integration

## Owner authorization and current evidence

OWNER_IMPLEMENTATION_AUTHORIZATION: GRANTED BY CURRENT CHAT DIRECTIVE 2026-08-30.
GIT_DELIVERY_AUTHORIZATION: GRANTED by Owner after M1.4 validation PASS; commit, push, PR and merge are authorized for this exact M1.4 branch.
PRODUCTION_DEPLOYMENT_AUTHORIZATION: NOT GRANTED.

Preflight on China Mac confirms latest `origin/main` is `94474846c10980cee9bbefaacf1f8cd3bbbefcf2` (PR #126 Marketing M1.0A merge). Customer M1.3 merge `59dd5870c15aea5140a7ddf44bb046e470008eca` / PR #125 is an ancestor. Current main has no `App/Modules/Support`, no `support_tickets` table, and Customer Support surfaces remain placeholders. The dedicated worktree and branch were created from this exact latest main.

## Goal

Create the minimum canonical Support Ticket foundation and integrate it into Customer Dashboard, Customer Library and Customer 360 without creating a second Customer or Support truth.

```text
Support Ticket Authority
        ↓ explicit customer_id only
Customer Dashboard / Library / Customer360
```

This is Support Ticket Foundation, not a complete Helpdesk.

## Authority and frozen truth boundary

- `support_tickets` is the canonical Support Ticket Master.
- `App/Modules/Support` owns Support Ticket writes.
- Customer remains the canonical customer identity surface and consumes Support through a read projection.
- `customer_id` is the only Customer↔Ticket relation authority.
- Never persist `customers.support_status`, `customers.ticket_count`, `customers.last_ticket_*` or equivalent duplicated support facts.
- Never auto-link tickets by email, phone, name, recipient or historical address.
- Support Ticket is customer service / after-sales / customer issue truth and MUST NOT reuse B2B Inquiry Case / inbound inquiry message truth.
- Marketing Subscriber/Subscription authority from Customer M1.3 remains unchanged.

## Frozen schema

Create `support_tickets` with:

```text
id
customer_id nullable FK -> customers.id
subject required string
description nullable text
status required string
priority required string
source_type required string
source_provider nullable string
external_ticket_id nullable string
requester_name nullable string
requester_email nullable string
requester_phone nullable string
assigned_user_id nullable FK -> users.id
opened_at nullable timestamp
status_updated_at nullable timestamp
resolved_at nullable timestamp
closed_at nullable timestamp
last_activity_at nullable timestamp
created_at
updated_at
```

Required indexes include `(customer_id,status)`, `(assigned_user_id,status)`, `(status,priority)`, and external identity indexing/uniqueness on `(source_provider,external_ticket_id)` compatible with nullable manual tickets.

Ticket display identity is derived only from database `id`:

```text
TKT-00000001
```

No separate sequence authority.

## Frozen enums / semantics

Status:

```text
open = 待处理
waiting_customer = 等待客户
waiting_internal = 内部处理中
resolved = 已解决
closed = 已关闭
```

Pending/open KPI includes only `open`, `waiting_customer`, `waiting_internal`.

Priority:

```text
low = 低
normal = 普通
high = 高
urgent = 紧急
```

Default priority is `normal`.

M1.4 manual create always uses:

```text
source_type = manual
source_provider = null
external_ticket_id = null
status = open
opened_at = now
last_activity_at = now
```

No UI may claim Email, WhatsApp, Zendesk, Gorgias, Freshdesk or another external intake is connected.

## Application contract

Create Support module boundaries equivalent to:

```text
app/Modules/Support/
  Application/SupportTicketService.php
  Application/CustomerSupportProjection.php
  Infrastructure/Models/SupportTicket.php
  Providers/SupportServiceProvider.php
  Experience/Controllers/CustomerSupportTicketController.php
  Experience/Routes/web.php
```

`SupportTicketService` owns create/update/status/assignment/audit writes.
`CustomerSupportProjection` owns Customer-scoped read projection and batch counts.
Customer controllers may consume the projection but must not absorb Support persistence logic.
Register the Support provider in `app/Modules/ModuleManifest.php` and update only M1.4-owned architecture canonical sets required by the current architecture tests.

## Permission contract

M1.4 is Customer-scoped and has no standalone Support Level-1 workspace.

```text
read linked tickets: customers.view
create/update linked tickets: customers.operate
```

Do not add `support.view` / `support.operate` or change role presets in this package.
Assigned user choices reuse active ERP users (`users.is_active = true`); do not create `support_agents`.

## Audit contract

Use the existing Audit Authority. Do not create a Support audit table.
Record stable actions for at least:

```text
support_ticket.created
support_ticket.updated
support_ticket.status_changed
support_ticket.assigned
```

Audit evidence includes actor, entity type/id, before/after and stable reason/action semantics. Reuse current `AuditRecorder` / `AuditActor` conventions.

## Customer surfaces

### Customer Dashboard
Replace the M1.4 unavailable Support card with a real pending-ticket metric.

### Customer Library
Replace `open_support_tickets_available=false` / placeholder presentation with real pending-ticket count. Any row-level Support data MUST use batch aggregate and MUST NOT create N+1 queries. An optional `工单` Data Grid column may be added only if it fits the existing Global Data Grid V1.1 contract; default hidden.

### Customer 360
Keep the Owner-approved visual contract:

```text
订阅记录 [full width]
客户支持工单 [next row, full width]
操作审计 [next row]
```

Support table should expose ticket number, subject, status, priority, source, owner, last activity, created time and action. It may only render tickets where `support_tickets.customer_id = current customer.id`.
Honest empty state: `暂无客户支持工单`.

## Customer-scoped create/update

Create routes under the current Customer context equivalent to:

```text
GET  /customers/library/{customer}/support-tickets/create
POST /customers/library/{customer}/support-tickets
GET  /customers/library/{customer}/support-tickets/{ticket}
PATCH /customers/library/{customer}/support-tickets/{ticket}
```

Nested read/update MUST verify `ticket.customer_id === customer.id`; mismatch returns 404.
The Customer-scoped create form MUST NOT allow changing Customer. Required form inputs: subject; optional description, priority and assigned user.
Update supports subject, description, priority, assigned user and status. Allowed status validation is exact. Status changes update `status_updated_at`; resolved/closed set their timestamps; reopening clears incompatible resolved/closed timestamps; every update advances `last_activity_at`.
Use existing `erp-form-*` and Page Header Action Zone patterns.

## Explicit exclusions

- no Email or WhatsApp intake;
- no Support message/thread/attachment tables;
- no SLA, macro, canned response, AI reply or automation;
- no external Helpdesk connector;
- no standalone Support Level-1 menu;
- no B2B Inquiry reuse or mutation;
- no Marketing/Andy Core/KOL changes;
- no Customer M1.5+ work;
- no production deployment or production migration;
- no Git delivery before separate Owner authorization.

## Parallel execution / dependency declaration

```text
WORK_PACKAGE_ID: ERP-CUSTOMER-M1-4-SUPPORT-INTEGRATION-001
BRANCH: feature/erp-customer-m1-4-support-integration-001
WORKTREE: /Users/a/AI/007/worktrees/erp-customer-m1-4-support-integration-001
AGENT_SESSION: CHINA_CODEX_CLI for M1.4 implementation and verification
BASELINE: origin/main@94474846c10980cee9bbefaacf1f8cd3bbbefcf2
EXPECTED_FILE_SCOPE: Support module + Support migration + Customer Support projection wiring + Customer Dashboard/Library/Show + focused tests + M1.4 architecture registration
DEPENDENCIES: PR #125 Customer M1.3 merged; PR #126 Marketing M1.0A merged
DEPENDENCY_TYPE: MERGED_PR
DEPENDENCY_STATUS: SATISFIED
PARALLEL_SAFE: YES; dedicated worktree, no dependency on active Inquiry unmerged code
FOUNDATION_CHANGE_REQUIRED: NO
```

The canonical checkout is currently on an Inquiry branch; this package must not modify or switch that worktree. Do not kill or overwrite unrelated provider sessions.

## Verification contract

HIGH_RISK because this package adds schema/FKs and Customer authorization routes.

Before Owner UAT readiness:

1. Schema/FK/index test.
2. Ticket number accessor test.
3. Manual create fixes explicit `customer_id` and defaults source/status/timestamps.
4. No auto-link by email/phone/name.
5. Customer isolation / no cross-customer ticket leak.
6. Pending status aggregation; resolved/closed excluded.
7. Customer Dashboard real Support metric.
8. Customer Library real pending KPI and no N+1 row querying.
9. Customer360 real ticket projection.
10. `customers.view` read and `customers.operate` write gates.
11. Nested customer/ticket mismatch 404.
12. Status/reopen timestamp semantics.
13. Active ERP user assignment validation.
14. Audit create/update/status/assignment evidence.
15. Customer M1.3 Subscriber projection regression.
16. Customer segmentation and Company regressions.
17. Frontend tests for Dashboard/Library/Show/Create/Edit as applicable.
18. `npm run typecheck`.
19. fresh `npm run build`.
20. E2E typecheck/contracts when current baseline requires them.
21. `git diff --check`.
22. `public/hot` absent.
23. Architecture tests with M1.4-owned drift fixed and unrelated baseline drift classified only.
24. Disposable DB migration rehearsal: migrate → table exists → rollback exact migration → table absent → reapply → table restored.
25. Fresh real-browser UAT runtime on a free isolated port (prefer 18117 if available), using a fresh browser context; do not weaken local admin middleware.

## Owner UAT gate

OWNER_UAT_READINESS: READY on China Mac local isolated runtime `http://127.0.0.1:18117` using disposable SQLite fixture.
AUTOMATED_GATE_EVIDENCE: Support + Customer backend `49 tests / 505 assertions`; focused Customer/Support frontend `20/20`; `npm run typecheck` PASS; fresh Vite build PASS; `git diff --check` PASS; `public/hot` absent; disposable migration rehearsal `1 → 0 → 1` PASS.
ARCHITECTURE_GATE_EVIDENCE: M1.4-owned drift = 0; ModuleProvider `8/8` PASS; ModuleArchitecture retains exactly 3 pre-existing unrelated baseline failures (KOL module directory/file drift, Finance/KOL application-layer drift, existing KOL contents migration baseline drift).
RUNTIME_SMOKE_EVIDENCE: authenticated runtime returns Customer Dashboard / Library / Customer360 / Support Show / Support Edit HTTP 200. Fixture projects Customer pending count `1` on Dashboard, Library aggregate and row; Customer360 shows one pending + one resolved linked ticket while an unlinked open ticket is excluded; Support audit actions are present.
OWNER_UAT_STATUS: PASS — Owner explicitly confirmed validation PASS and authorized M1.4 merge.

Owner must verify Dashboard pending count, Library KPI/performance, Customer360 full-width Support placement and truthful table/empty state, explicit Customer-scoped create, update/status/owner/audit behavior, and that resolved/closed tickets leave pending counts.

Owner-UAT fixture may be disposable local data and must be clearly local/manual. No fake production or fake external-source claims.

## Delivery gate

Implementation and local verification may proceed now. Stop at `OWNER_UAT_READY` / `OWNER_UAT_PASS` as applicable. Commit/push/Draft PR remain unauthorized until Owner explicitly grants Git delivery. Merge always requires a separate explicit Owner gate. Production deployment/migration/tag/release are not authorized.

## PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate [COMPLETE] → ERP-INQUIRY M1.0/M1.1 [COMPLETE] → Order Fulfillment M1 [COMPLETE] → Product Master/SKU [COMPLETE] → Order Create/Edit V2 [COMPLETE] → Inventory M1 [COMPLETE] → MVP1 Manual Fulfillment [COMPLETE] → WooCommerce Inbound [COMPLETE] → Tracking Outbound [COMPLETE] → Sync Orchestration [COMPLETE] → KOL M1.0/M1.1 [COMPLETE] → historical Roadmap Reconciliation/KOL-priority sequence in doc13 → Owner-reactivated Customer V1 track with M1.1/M1.2/M1.3 merged and M1.4 current → remaining Customer M1.5–M1.9 / retained paused tracks → milestone remote release / later expansion.
CURRENT_TRACK_PHASE_MAP: Customer M1.1 [MERGED] → Customer M1.2 [MERGED] → Customer M1.3 [MERGED PR #125] → Customer M1.4 Support Integration [CURRENT] → Customer M1.5 Shipment Projection → Customer M1.6 Advanced Segmentation → Customer M1.7 Manual Groups → Customer M1.8 Full Import/Export → Customer M1.9 Customer360 Closure.
COMPLETED_PHASES: Customer M1.1, M1.2, M1.3; PR #126 Marketing M1.0A is also merged on current main.
CURRENT_PHASE: Customer M1.4 Support Integration.
CURRENT_WORK_PACKAGE: ERP-CUSTOMER-M1-4-SUPPORT-INTEGRATION-001.
PACKAGE_STATUS: MERGE_AUTHORIZED / OWNER_UAT_PASS / GIT_DELIVERY_AUTHORIZED.
WAITING_PHASES: Customer M1.5–M1.9 and all excluded scopes.
BASELINE: origin/main@94474846c10980cee9bbefaacf1f8cd3bbbefcf2.
GITHUB_STATE: PR #125 and PR #126 merged; M1.4 branch has been pushed from the China Mac; Owner has authorized PR creation and merge for the validated M1.4 head.
SOURCE_CONFLICTS: `AI/CURRENT_CONTEXT.md` and `docs/10_Planning_Roadmap/13_PROGRAM_ROADMAP_AND_HOURS.md` remain dated 2026-08-28/29 and still describe Roadmap Reconciliation/KOL as current, while newer merged main contains Customer M1.1–M1.3 and Marketing PR #126 and the Owner currently explicitly directs Customer M1.4. Preserve that stale-governance conflict; this package does not rewrite unrelated roadmap governance. Current Owner task selection + current GitHub main determine this bounded package.
GLOBAL_GATE: Customer M1.4 Owner UAT PASS; Git delivery and merge authorized; production deployment remains gated.
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE; package explicitly excludes production deployment/migration.
NEXT_DEPENDENCY: Merge the exact M1.4 PR, synchronize the resulting latest main to the US Mac, then begin Customer M1.5 in a new isolated worktree.
EVIDENCE_AS_OF: 2026-08-30; China Mac M1.4 implementation/verification + current Owner PASS/merge authorization + origin/main history.

## ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: planning value 8h; range 5–11h.
KNOWN_CURRENT_TRACK_REMAINING: UNKNOWN WITH EVIDENCE; M1.5–M1.9 are named but no complete non-overlapping current estimates are issued in the audited sources.
ARCHITECTURE_GATE_REMAINING: 0h / complete per document 13.
MVP1_TOTAL_ESTIMATE: 122–190h total historical estimate.
MVP1_PLANNING_BASELINE: 166h total planning value.
MVP1_MAXIMUM_ENVELOPE: 192h maximum total planning envelope.
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; accepted actual-hours ledger/overlap map incomplete.
POST_MVP_KNOWN_INCREMENT: approximately 68–120h beyond MVP1 in document 13; historical classification only.
LONG_TERM_CUMULATIVE_RANGE: Architecture 235–402h; first highly automated operations MVP 603–1,018h; Product/SEO growth 1,059–1,802h; Full core ERP 1,595–2,714h; All expansion 1,819–3,146h; cumulative horizons not additive.
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; stale roadmap priority plus incomplete actual/overlap mapping prevents safe subtraction.
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; project-wide accepted ledger incomplete.
ESTIMATE_CONFIDENCE: MEDIUM for bounded M1.4 package; UNKNOWN for program remaining.
ESTIMATE_AS_OF: 2026-08-30.
ESTIMATE_METHOD: bounded frozen schema/UI/test scope; no chat elapsed-time conversion; no cumulative-range summing; no unknown-to-zero conversion.

## ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.5–1h; planning value 0.75h.
CORE_EXECUTION: 3–5h; planning value 4h.
TEST_AND_VALIDATION: 1–3h; planning value 2h.
REMEDIATION_BUFFER: 0.5–2h; planning value 1.25h.
ESTIMATED_EFFECTIVE_HOURS: 8h planning value.
ESTIMATE_RANGE: 5–11h.
ESTIMATE_BASIS: frozen Support CRUD/schema/projection contract across one modular Laravel/Vue package with high-risk migration/authorization verification.
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: INCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: INCLUDED only on disposable/local verification database; production execution excluded
UAT: INCLUDED for preparation/runtime evidence; Owner manual review time excluded
DEPLOYMENT: EXCLUDED
