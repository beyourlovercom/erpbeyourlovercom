---
type: work-package
project: BYL ERP
work_package_id: ERP-CUSTOMER-M1-1-EXPERIENCE-001
status: OWNER_UAT_PASS / DELIVERY_AUTHORIZED
execution_provider: CLAUDE_CODE
final_exact_remediation_provider: GPT_WEB_OWNER_OVERRIDE
baseline: origin/main@1a7347f94938be2db29cbcd1fad6822aedcd9052
supersedes_pre_delivery_wp: ERP-CUSTOMER-MASTER-M1-CLEAN-001
---

# ERP Customer M1.1 — Customer Experience

## Goal
Deliver one coherent Customer V1 experience foundation in a single branch/PR: canonical Customer identity + addresses + dashboard + scalable customer list + Customer 360 detail + edit experience. The previous Customer Core implementation was never committed or delivered and is absorbed into this package.

## Canonical contract
- Customer is canonical customer identity; Order is transaction truth; Shipment is fulfillment truth.
- Customer relations use explicit IDs only. Never backfill or infer identity from names, recipients, fuzzy similarity, or historical address text.
- Historical `orders.customer_id = null` remains valid unknown identity.
- Order addresses remain immutable transaction snapshots; Customer billing/shipping addresses are separate customer master data.
- Revenue is never summed across currencies without an explicit FX authority. M1.1 uses currency-separated projections.
## In scope — Core foundation
- `customers` table, Customer model/service/controller, CRUD, archive/restore, audit.
- Nullable `orders.customer_id` FK/index and explicit Customer ↔ Order relation.
- `/customers/library` Index/Create/Show/Edit.
- Existing `customers.view` and `customers.operate` remain the general Customer permissions.

## In scope — Customer addresses
Add canonical `customer_addresses` with one `billing` and one `shipping` record per customer.
Fields: customer_id, address_type, name, company, phone, country_code, province_state, city, address_line_1, address_line_2, postal_code, timestamps.
- Unique `(customer_id, address_type)`.
- Create/Edit can persist both address types transactionally with Customer changes.
- Empty address data remains empty; never copy historical OrderAddress automatically.

## In scope — `/customers` dashboard
Replace the shell placeholder with a real Chinese Customer dashboard.
Show real-data cards for total customers, customers created this month, customer orders this month, and this-month revenue grouped by currency.
Reserve Subscriber and unresolved Support Ticket KPI cards as explicit unavailable/empty integration states until their canonical modules enter main. Do not fabricate counts.
Add practical links to Customer List and New Customer where permission allows.
## In scope — Customer List workspace
Redesign `/customers/library` using the Owner-approved customer-list direction with Chinese frontend titles and existing BYL ERP visual tokens.
- Paginated customer table; do not load all customers into the browser.
- Default columns: Customer Number, name, email, phone, country, source, owner, explicit customer-order count, currency-separated revenue summary, recent order date, actions.
- Existing search/country/source/archive filters remain real and server-driven.
- Reserve the Customer Group selector location for M1.2 segmentation; do not fake segment results in M1.1.
- Add the `↕️` list-maximize control. It hides/shows both statistics and filter modules while leaving the table visible and enlarged.
- Persist only this UI preference locally (for example `customer_list_compact_mode`); it is not business data.
- Page Header Action Zone remains canonical for page-level actions.

## Admin-only Import / Export capability gate
Add dedicated permissions `customers.import` and `customers.export` to AuthorizationKeys and PermissionCatalog.
- Administrator preset receives them automatically through the existing all-permission contract.
- Sales/Operations/other presets must not receive them unless explicitly changed by future Owner authority.
- Import/Export buttons are visible only when the current user has the matching permission; other users see no such buttons.
- Full CSV/XLSX import/export engine is deferred to Customer M1.8. M1.1 may expose guarded admin-only reserved endpoints/status, but must not claim a completed data engine.
- Any reserved endpoint must enforce server-side permission and return 403 for unauthorized users.
## In scope — Customer 360 Show
Redesign `/customers/library/{id}` to the Owner-approved Customer Profile direction with Chinese frontend titles.
- Profile hero: Customer Number, name, email, phone, country, language, source, owner.
- KPI: explicit order count; revenue and average order value by currency; last purchase; shipment count; Support/Subscriber integration states.
- Billing and Shipping address cards from `customer_addresses`.
- Purchase History: linked customer orders only; order number links to `/customers/orders/{order_id}`; show date, currency, amount, order status, shipment status.
- Shipment Records: project through Customer → Order → Shipment; show carrier, tracking, shipped date, status; no duplicated Customer shipment table.
- Support Tickets and Subscription Record panels stay visible but show a truthful unavailable/empty integration state in M1.1.
- Audit / recent Customer activity remains real Customer audit data.

## In scope — Customer Edit
Keep current frozen `erp-form-*` Form Design Standard and Page Header Action Zone.
Editable sections: basic information, Billing Address, Shipping Address.
Related Orders/Shipments are read-only projections with deep links; Support/Subscriber remain unavailable until integrated.
Archive remains confirmation-protected and visually destructive.

## Explicit exclusions
- Customer segmentation logic (New/Repeat/Multi-product/etc.) — M1.2.
- Subscriber schema/service/data — M1.3.
- Support ticket schema/service/data — M1.4.
- Full import/export processing engine — M1.8.
- Woo identity matching/backfill, fuzzy matching, production deployment. Order business logic is excluded except the Owner-authorized Customer-order CSV Admin-only export gate.
- KOL, Inventory and Logistics business-logic changes beyond read-only Shipment projection. Finance changes are limited to the Owner-authorized reference-only ECB FX display service.
## Required validation
- Customer feature coverage for CRUD, addresses, dashboard metrics, pagination, explicit order relation, multi-currency, shipment projection, permissions, archive/restore, audit.
- Identity permission-matrix regression proving only Administrator preset receives Customer import/export by default.
- Company regression because Customers routes/provider are shared.
- Page Header Action architecture includes Customer Dashboard/List/Create/Edit/Show as applicable.
- Module architecture entries only for this Customer package; classify unrelated current-main KOL drift, do not patch it here.
- Disposable SQLite migrate fresh → rollback latest → reapply.
- `npm run typecheck`, focused frontend tests where added, fresh `npm run build`, manifest/bundle signature checks, `public/hot` check.
- Exact isolated Local UAT runtime for Dashboard/List/Create/Edit/Show.
- `git diff --check`.

## Delivery gates
- No commit/push/PR until technical verification and Owner UAT pass.
- No production DB migration/deployment.
- No merge without separate explicit Owner authorization.
- Preserve `ONE_WORK_PACKAGE_ONE_BRANCH_ONE_PR`; this branch contains the undelivered Core foundation plus M1.1 Experience as one consolidated package.

## Parallel safety
KOL remains an independent parallel track. Do not edit KOL implementation files or use Customer work to repair KOL architecture baseline drift.

## Owner UAT amendment — 2026-08-29
- Customer Library filter module is a single horizontal row only.
- Filter controls never wrap to a second row; overflow is horizontally scrollable.
- Filter submit action remains available within the same row/module.
- Customer import and customer export remain strict Administrator-only.
- Customer-order CSV export (`/customers/orders/export`) is also strict Administrator-only.
- Administrator-only requires both the dedicated customer export authority and `system.permissions.manage`.
- Non-admin users must not see customer-order CSV export buttons/links on Orders or Sync export surfaces and must receive HTTP 403 from the direct URL.
- `/orders/export` and `/orders/procurement/export` are outside this amendment and retain their existing authority.

## Owner UAT Amendment — RMB Monthly Revenue + Compact KPI Row

- `/customers/library` monthly sales KPI must show one RMB-estimated total, converted from the existing currency-separated monthly revenue.
- Preserve original order currencies and original revenue projections; conversion is display-only and must never mutate Order/Finance facts.
- Introduce a reusable Finance reference-FX service; do not hardcode exchange rates in Vue or Customer controllers.
- Use ECB official daily euro reference rates as the reference source. Derive cross-rates to CNY, support CNY=1, and cache/resiliently reuse the last successful reference snapshot when a refresh fails.
- If a nonzero source currency is unsupported and no valid conversion exists, the RMB total must be unavailable rather than a misleading partial total.
- Expose reference date/source/stale status and original currency breakdown for tooltip/auditability; do not consume vertical card space for those details.
- The Customer Library six-card KPI strip must be visually compact: every card renders no more than two visible text rows (label + value). Remove visible currency sub-lines and unavailable explanatory notes; use title/aria-label tooltips instead.
- Add focused tests for EUR/USD/CNY conversion math, unsupported currency behavior, cached fallback, controller payload, and compact frontend markers. Do not create accounting FX persistence or transaction settlement logic.