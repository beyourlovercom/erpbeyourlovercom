---
type: work-package
project: BYL ERP
work_package_id: ERP-CUSTOMER-M1-9-CUSTOMER360-CLOSURE-001
status: OWNER_UAT_PASS
execution_provider: US_CODEX_CLI
verification_tier: HIGH_RISK
baseline: origin/main@e627f7391cf9975fbc509d23724967d67a6e0f9b
owner_uat_required: YES
git_delivery_authorized: NO
merge_authorized: NO
production_deployment_authorized: NO
---

# Customer M1.9 — Customer360 Closure

## Goal
Close Customer V1 by turning the existing Customer360 detail page into the final integrated Customer workspace for M1.1–M1.8, while preserving canonical ownership in Customer, Orders, Logistics, Marketing, Support, Audit and Identity.

M1.9 is a closure package, not a new CRM platform. It must consolidate already-delivered truths, close known cross-module read-boundary debt, improve final Customer360 usability, run cross-phase regression, and deliver one final Owner UAT gate.

## Baseline
- Customer M1.1–M1.8 are merged.
- Canonical baseline is `origin/main@e627f7391cf9975fbc509d23724967d67a6e0f9b`.
- No production deployment, release, tag or migration execution is authorized.
## Current closure findings
1. Customer360 already renders canonical Customer identity, Billing/Shipping addresses, order KPIs/history, Logistics shipment projection, Marketing subscription facts, Support tickets and audit records.
2. M1.7 Manual Groups and M1.2/M1.6 system segmentation are not projected on Customer360, so the detail page does not show the customer's current grouping context.
3. The audit panel exposes raw action codes and `actor_id`, not a human-readable activity timeline.
4. Subscriber facts are present, but Customer360 lacks a compact summary that distinguishes identity match/fact count from any synthesized consent state.
5. `CustomerController::show()` still directly reads Orders/Audit Infrastructure. Logistics, Support and Subscriber already use owned Application projections. M1.9 should close this inconsistency.
6. Customer360 is one long vertical stack with no local section navigation; it works, but closure should make the integrated page practical at high information density.

## Frozen authority boundaries
- Customer owns Customer master, addresses, archive state, owner and notes.
- Orders owns transaction truth and order/item revenue facts.
- Logistics owns Shipment truth.
- Marketing owns Subscriber/subscription/consent facts.
- Support owns Support Ticket truth.
- Audit owns immutable audit truth.
- Identity owns user identity/authorization truth.
- Customer360 may project these facts; it must not duplicate them.
- No fuzzy identity matching or historical backfill.
- No cross-currency revenue summation without existing FX authority.
## Scope A — Customer360 composition
Customer360 remains `/customers/library/{customer}` and keeps one canonical Customer detail route.

Final information architecture:
- Profile / identity hero with Customer Number, contact data, source, owner and archive state.
- Compact relationship strip: system segments + manual groups.
- Six existing commerce/service KPIs remain truthful; add a compact Subscriber identity/fact summary outside the revenue cards rather than inventing a global consent status.
- Billing / Shipping addresses.
- Purchase history.
- Shipment records.
- Subscription facts.
- Support tickets.
- Human-readable activity timeline.

Add a lightweight local section navigator for the long Customer360 page. It may be sticky/collapsible on desktop and degrade naturally on smaller widths. It changes navigation only; it does not create duplicate pages or tabs with separate data loading.

## Scope B — system/manual group projection
- Show every system segment currently true for this Customer using the same frozen segment semantics used by Customer Groups.
- Show current persisted Manual Groups from `customer_group_memberships`.
- System segments remain computed/read-only.
- Manual groups remain persisted membership truth.
- Customer360 may deep-link to `/customers/groups`; it does not create a second membership editor in M1.9.
## Scope C — cross-module read-boundary closure
- Replace direct Orders Infrastructure reads in `CustomerController::show()` with an Orders-owned public Application projection/query for Customer order summary, currency-separated revenue, recent linked customer orders and shipment-status join inputs as needed.
- Replace direct Audit Infrastructure reads in Customer360 with an Audit-owned public Application projection/query.
- Resolve audit actor display through an approved Identity Application surface; do not add new user/actor columns to audit records.
- Preserve existing Logistics `CustomerShipmentProjection`, Support `CustomerSupportProjection`, and Marketing subscriber projection boundaries.
- Do not change transaction, shipment, support, marketing, audit or identity write behavior.

## Scope D — activity timeline closure
Replace raw audit presentation with a readable timeline while keeping immutable Audit as truth.

At minimum support human labels for:
- customer.created / updated / archived / restored;
- support_ticket.created / updated / status_changed / assigned;
- imported Customer create/update activity where the existing Customer audit path records it.

Each rendered activity shows action label, actor display when resolvable, timestamp, and safe reason/context where already present. Unknown action codes remain visible as fallback rather than being discarded.

## Scope E — final usability polish
- Preserve existing Page Header Action Zone: back, edit, archive/restore.
- Preserve existing deep links to Orders and Support.
- Use current BYL ERP visual tokens and Chinese labels.
- Keep honest empty states for every projection.
- Responsive behavior must keep the Customer360 page usable without hiding capabilities.
## Explicit exclusions
- No new Customer, Company, Order, Shipment, Subscriber, Support, Audit or Identity tables.
- No Company↔Customer relationship is invented; current repository has no canonical relation.
- No rule-based group membership, tagging engine, campaign automation or workflow triggers.
- No editing Order, Shipment, Marketing subscription or Audit truth from Customer360.
- No WooCommerce identity inference/backfill/fuzzy matching.
- No new RBAC keys; use existing `customers.view` / `customers.operate` and existing nested resource permissions.
- No production deployment, tag, release, or production migration.

## Verification — HIGH_RISK cross-phase closure
1. Customer M1.1–M1.8 backend regression.
2. Customer360 focused backend contract tests for grouping, projections, actor/activity rendering and ownership boundaries.
3. Customer frontend suite plus expanded `Library/Show.test.ts`.
4. Permission tests for read-only vs operator behavior and no capability leak.
5. ModuleProvider + ModuleArchitecture exact-main comparison; unrelated historical drift remains out of scope.
6. `npm run typecheck`, production build, `git diff --check`, `public/hot` absent.
7. No new migration expected; verify migration inventory unchanged by M1.9.
8. Authenticated US-local Owner UAT with bypass=false and realistic Customer360 fixture spanning orders, shipments, subscriptions, support, system segments, manual groups and audit.

## Owner UAT acceptance
- One Customer360 page gives a coherent view of identity, groups, commerce, fulfillment, marketing facts, support and activity.
- System segments and manual groups are accurate and visually distinguishable.
- Revenue remains currency-separated; no false consent/global subscription status appears.
- Order/Shipment/Support/Subscription data deep-links and empty states remain correct.
- Activity timeline is human-readable and still traceable to immutable audit evidence.
- Reader/operator controls remain correctly gated.
## Delivery gates
OWNER_SCOPE_DIRECTION: GRANTED — Owner explicitly started Customer M1.9 Customer360 Closure on 2026-08-31.
IMPLEMENTATION_AUTHORIZATION: GRANTED — Owner explicitly authorized M1.9 implementation on 2026-08-31, including Customer URL/navigation closure amendment. Git delivery, merge and deployment remain separately unauthorized.
GIT_DELIVERY_AUTHORIZATION: GRANTED — Owner explicitly authorized M1.9 commit / push / PR creation on 2026-08-31. This does not authorize merge or deployment.
MERGE_AUTHORIZATION: GRANTED — Owner explicitly authorized merge of PR #133 on 2026-08-31. This does not authorize deployment.
PRODUCTION_DEPLOYMENT_AUTHORIZATION: NOT GRANTED.

NEXT_GATE: Merge authorized on 2026-08-31 → exact-head merge PR #133 → canonical main synchronization. Deployment remains separately unauthorized.

PROJECT POSITION
PROGRAM_ROADMAP: Customer M1.1–M1.8 [MERGED] → Customer M1.9 Customer360 Closure [CURRENT].
CURRENT_TRACK_PHASE_MAP: M1.1 Experience → M1.2 Segmentation → M1.3 Subscriber → M1.4 Support → M1.5 Shipment → M1.6 Advanced Segmentation → M1.7 Manual Groups → M1.7A Groups UI → M1.8 Import/Export [ALL MERGED] → M1.9 Customer360 Closure [MERGE_AUTHORIZED].
CURRENT_WORK_PACKAGE: ERP-CUSTOMER-M1-9-CUSTOMER360-CLOSURE-001.
PACKAGE_STATUS: IMPLEMENTATION_COMPLETE / HIGH_RISK_VERIFICATION_PASS_WITH_EXACT_MAIN_ARCH_BASELINE / OWNER_UAT_PASS / GIT_DELIVERY_AUTHORIZED / MERGE_AUTHORIZED / DEPLOYMENT_NOT_AUTHORIZED.
BASELINE: origin/main@e627f7391cf9975fbc509d23724967d67a6e0f9b.
PRODUCTION_DEPLOYMENT_STATUS: NO.

ROADMAP HOURS
PREFLIGHT_AND_SCOPE: 1–2h.
CORE_EXECUTION: 6–10h.
TEST_AND_VALIDATION: 2–4h.
REMEDIATION_BUFFER: 1–2h.
ESTIMATED_EFFECTIVE_HOURS: 11–18h.
ESTIMATE_CONFIDENCE: MEDIUM-HIGH; no schema migration is planned, but cross-module read projections and final cross-phase regression make this larger than a visual-only package.
MERGE: EXCLUDED / SEPARATE OWNER GATE.
DEPLOYMENT: EXCLUDED.

## Scope F — Customer URL / navigation closure (Owner amendment 2026-08-31)

M1.9 MUST close discoverability gaps across all Customer capabilities delivered before closure. There is no separately delivered formal Customer M1.0 package in canonical history: the undelivered Customer Core / `ERP-CUSTOMER-MASTER-M1-CLEAN-001` was explicitly absorbed into M1.1. Therefore this inventory treats that absorbed Core + M1.1–M1.8 as the pre-M1.9 Customer surface.

### Human-facing workspace/page inventory

| Surface | Canonical URL | Navigation role |
|---|---|---|
| Customer dashboard / overview | `/customers` | Level-1 Customer root; canonical overview |
| Customer library | `/customers/library` | Customer Level-2 workspace |
| New Customer | `/customers/library/create` | action/deep page from dashboard/library; not required as Level-2 |
| Customer360 | `/customers/library/{customer}` | row/deep link; primary per-customer aggregation surface |
| Edit Customer | `/customers/library/{customer}/edit` | Customer360 action/deep page |
| Customer Groups | `/customers/groups` | Customer Level-2 workspace |
| Companies | `/customers/companies` | **missing Level-2 entry today; M1.9 must add it** |
| New Company | `/customers/companies/create` | action/deep page from Companies; not Level-2 |
| Company detail | `/customers/companies/{company}` | row/deep link |
| Company edit | `/customers/companies/{company}/edit` | action/deep page |
| Customer Import | `/customers/import` | Customer Level-2 workspace |
| Customer Import alias | `/sync/customers/import` | Sync Level-2 alias to same Import workspace |
| Customer Orders | `/customers/orders` | Customer Level-2 workspace; duplicate shortcut under Orders is valid |
| New Customer Order | `/customers/orders/create` | action page from Customer Orders; may remain a shortcut only if menu contract intentionally retains action shortcuts |
| Customer Order detail | `/customers/orders/{order}` | row/deep link from Customer360/Orders |
| Customer Support create | `/customers/library/{customer}/support-tickets/create` | Customer360 action/deep page only |
| Customer Support detail | `/customers/library/{customer}/support-tickets/{ticket}` | Customer360 deep link only |
| Customer Support edit | `/customers/library/{customer}/support-tickets/{ticket}/edit` | Support detail action/deep page only |

### Utility/action endpoints — never sidebar menu items

- `/customers/library/export` — filtered Customer CSV/XLSX export.
- `/customers/orders/export` — Customer-order export.
- `/customers/import/template` — import template download.
- `/customers/library/import` — guarded import preview compatibility endpoint.
- `/customers/import/preview` — Preview API.
- `/customers/import/{batch}/confirm` — confirm API.
- Customer group create/rename/delete/member/search endpoints.
- Customer/company create/update/archive/restore mutation endpoints.
- Customer support create/update mutation endpoints.

### Stale / legacy navigation finding

`/customers/orders/mine` / route `customers.orders.mine` is present in formal menu configuration and approved-menu metadata but has no current canonical Orders controller route. It is a legacy/prototype navigation artifact, not a completed Customer V1 workspace. M1.9 MUST remove it from the formal Customer menu/baseline rather than presenting it as a real feature. Do not invent a new "My Orders" implementation inside Customer closure.

### Frozen Customer information architecture after M1.9

Customer Level-1 `/customers` remains the clickable Customer overview/dashboard. Its stable Level-2 workspaces SHALL be organized around discoverable business surfaces, not mutation forms:

1. `客户列表` → `/customers/library`
2. `客户分组` → `/customers/groups`
3. `公司` → `/customers/companies`
4. `客户订单` → `/customers/orders`
5. `客户导入` → `/customers/import` (double permission gate preserved)

`新建客户`, `新建公司`, `新建订单`, Edit, Archive/Restore, Export, Support actions, Import confirm and other mutations remain contextual page actions/deep links rather than mandatory Level-2 navigation. The existing Orders → 客户订单 duplicate shortcut and Sync → 客户导入 alias are intentional multi-entry aliases to the same canonical pages, not duplicate data or duplicate implementations.

### Customer360 anti-island links

Customer360 SHALL expose contextual navigation to the canonical related facts already shown on the page: Order detail from purchase/shipment rows; Support ticket detail/create; Manual/System group context; and clear route back to Customer Library. Subscriber/Shipment remain read projections with no Customer-owned mutation route. Company is not linked to Customer360 until a canonical Customer↔Company relation is explicitly designed in a future package.

### Customer Hub quick-entry closure

`/customers` remains the Customer Level-1 dashboard and SHALL become the explicit Customer Hub. In addition to its existing real KPI cards, it must expose a compact quick-entry area to the stable Customer workspaces: Customer Library, Customer Groups, Companies, Customer Orders, and Customer Import. Customer Import quick entry remains hidden unless the existing dual import/admin permission gate passes. No new permission vocabulary is introduced.

The Customer Level-2 menu SHALL remove action-only `新建订单` because `/customers/orders` already exposes its canonical `新建客户订单` page action. It SHALL also remove the stale legacy `我的订单` entry. Create/edit forms remain discoverable from their owning workspace/page headers.
## Verification evidence — 2026-08-31
- `git diff --check`: PASS.
- `npm run typecheck`: PASS.
- Production `npm run build`: PASS; expected chunk-size warning only; `public/hot` absent.
- Customer backend: 83 tests / 904 assertions; no failures (existing file_get_contents warning class only).
- Customer frontend: 8 files / 36 tests PASS.
- Approved Menu + AppShell + ModuleProvider: 29 tests / 1622 assertions PASS.
- ModuleArchitecture: 3 failed / 5 passed / 87 assertions; exact `main@e627f7391cf9975fbc509d23724967d67a6e0f9b` produces the same 3 named failures / 87 assertions. M1.9 architecture regression = 0.
- M1.9 introduces no migration diff.

## Owner UAT runtime — READY
- US Mac isolated runtime: `http://127.0.0.1:18121`.
- Bypass: `ERP_LOCAL_ADMIN_BYPASS=false`.
- Real authenticated user: `admin@local.test` (super-admin fixture).
- Isolated SQLite: `database/m19-uat.sqlite`.
- Customer360 fixture: `/customers/library/1` with Manual Group, current system segment, order/revenue, shipment, Marketing subscription fact, pending Support ticket and human-readable Audit activity.
- Browser-level self-check: Customer, group, order, shipment, subscription, support, activity, all five Customer Level-2 entries and section navigator markers = PASS.
- Git delivery / merge / production deployment remain NOT AUTHORIZED.



## Owner UAT result
OWNER_UAT_STATUS: PASS
OWNER_UAT_DATE: 2026-08-31
UAT_RUNTIME: authenticated US-local runtime at `http://127.0.0.1:18121` with `ERP_LOCAL_ADMIN_BYPASS=false`.
UAT_SURFACE: `/customers/library/1` plus Customer Hub/navigation inspection.
UAT_FIXTURE: customer identity, system/manual groups, addresses, linked customer order, shipment, Marketing subscription fact, Support ticket and human-readable Audit activity.
UAT_NAVIGATION: Customer Level-2 `客户列表 / 客户分组 / 公司 / 客户订单 / 客户导入`; legacy `/customers/orders/mine` absent.
OWNER_DECISION: PASS.
GIT_DELIVERY_AUTHORIZATION: GRANTED — Owner explicitly authorized M1.9 commit / push / PR creation on 2026-08-31. This does not authorize merge or deployment.
MERGE_AUTHORIZATION: GRANTED — Owner explicitly authorized merge of PR #133 on 2026-08-31. This does not authorize deployment.
PRODUCTION_DEPLOYMENT_AUTHORIZATION: NOT GRANTED.

## Git delivery evidence — 2026-08-31
- Owner UAT: PASS.
- Delivery recheck: Customer360 focused 23 assertions PASS; Menu/AppShell/ModuleProvider 1622 assertions PASS; Dashboard/Show frontend 4/4 PASS.
- Typecheck: PASS.
- Production build: PASS from pre-UAT final gate; no product code changed after that gate.
- `git diff --check`: PASS.
- `public/hot`: ABSENT.
- Architecture: exact-main comparison remains the same historical 3 failures / 5 passes / 87 assertions; M1.9 regression = 0.
- No migration diff, no dependency lock diff, no UAT artifacts retained.
- Merge and production deployment remain separately unauthorized.

## Git delivery receipt
- PR: #133 — Customer M1.9 — Customer360 Closure.
- PR URL: https://github.com/beyourlovercom/erpbeyourlovercom/pull/133
- Business delivery commit: `a2e19c1766432ee35d659b38330625063ef8af11`.
- Base at PR creation: `main@e627f7391cf9975fbc509d23724967d67a6e0f9b`.
- PR creation state: OPEN / CLEAN / MERGEABLE.
- GitHub status checks at creation: none configured / none pending.
- NEXT_GATE: exact-head merge PR #133, then canonical main synchronization; deployment remains separate.
- Merge is Owner-authorized for PR #133; production deployment remains NOT AUTHORIZED.

OWNER_MERGE_DECISION: GRANTED — PR #133.
