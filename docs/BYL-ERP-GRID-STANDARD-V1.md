# BYL ERP Grid Standard V1 — Frozen

Status: OWNER APPROVED / FROZEN (2026-11-05)
Scope: BYL ERP front-end list / table pages; this is the single standard for new tables and phased legacy migrations.

## Owner-confirmed permanent implementation rule · 2026-11-10

- **For every NEW ERP primary business list**, use `resources/js/Components/DataGrid/GlobalGrid.vue` as the shared component; it wraps `OmsGrid.vue` and retains the one AG Grid Community core. It is the **implementation** of the existing `BYL_GLOBAL_DATA_GRID_VI_V1_1` layout, query and preference contract, not a second conflicting standard.
- Domain pages provide authorized column schemas, row identities, canonical server-side query adapters, status labels and permitted actions. New full-size native `<table>` business lists or copy/pasted `OmsGrid` forks are non-compliant without an explicit, documented Owner-approved exception.
- **No blanket replacement of detail-page mini tables**, audit/preview summaries, data-entry sub-tables or appropriate mobile cards. These can retain small lightweight tables. Existing production lists are migrated **only when selected and accepted page by page**.
- **No changes to the authority of orders, fulfillment, inventory, procurement, audits, approvals, imports/exports, finance, or permissions.** All writes and permission gates remain in their original servers/routes. A list style upgrade must not introduce or bypass business mutations.
- Search is embedded in the shared grid and covers all **authorized backend records**. Remove duplicate external keyword boxes in migrated list views, retain independent status/warehouse/domain filters. Server paginates and allowlists query fields; do not promise local-only sorting/filtering as full-data results.
- Preserve existing account-scoped column preferences and their unique namespaces; current canary flags/Owner ID 1 opt-in and legacy fallback remain effective. Changing company-wide defaults or migrating already-existing pages is a separate Owner-approved batch and UAT/release decision.
- Full contract: `AGENTS.md` rule `ERP_GLOBAL_GRID_V1_NEW_BUSINESS_LIST_STANDARD` and `docs/10_Planning_Roadmap/26_BYL_ERP_GLOBAL_DATA_GRID_VI_V1_1.md`.

## 1. Core standard
- Use `ag-grid-community` + `ag-grid-vue3`, Balham theme, via the shared `OmsGrid` component.
- Do not create per-page standalone table frameworks. Domain pages supply column definitions, data adapters, authorized actions and links.
- One flat column-title row and, for searchable data columns, a separate AG Grid Floating Filters input row; compact one-record-per-row layout. Do not add grouped headers or embed input boxes inside the title row.
- Column resize, drag order, hide/show, persisted preferences, left/right pinned counts, maximize/Esc restore, sorting and filtering follow shared behavior.
- Exactly one pagination/footer region. Page size 20 / 50 / 100 / 全部 where safe; prompt about browser cost before 全部.
- Import/export use each domain's authorized backend adapters. Imports must offer a downloadable fixed-header template, no-write preflight, explicit confirm, row-level errors, auditing, duplicate protection and operation-specific permissions. Never create records directly in the AG Grid frontend; export must be server-authoritative over the full filtered dataset, not merely the browser's current page.

## 2. Workflow and permission authority
- Grid UI is presentation only: Orders, Logistics, Inventory, Finance, Procurement and KOL retain their own API endpoints and permission gates.
- Workflows, canonical order status, archived/split order identity, allocation and shipment mutations MUST remain unchanged unless a separately approved task authorizes changes.
- A clickable row / action must navigate to an authorized canonical detail route. Mutating actions must remain permission-gated in the server as well as UI.
- In-page editing must use the existing backend API, concurrency tokens, error handling and rollback.

## 3. Large datasets
- For authoritative production lists, page, sort and filter on the server. Do not treat the current browser page as the entire dataset. New OMS Grid global/column keyword search uses contains-match (including numeric fragments); explicitly named DB-ID syntax remains exact. Preserve documented legacy-search contracts on pages not yet migrated.
- An "all rows" option must never silently fetch unlimited records. Support only bounded filtered results or provide an explicit refusal and instruction to narrow filters; export continues through authorized export endpoints.
- Prefer existing server pagination and explicit allowlists for sort / filter fields. Keep total counts authoritative.

## 4. Rollout and UAT gates
- R2: prototype and visual acceptance — Owner PASS.
- R3: real Orders list adapter with existing views, permissions, status, export, detail navigation and server-side pagination preserved; keep a rollback/fallback route until real-data UAT PASS.
- R4+: progressively migrate Inventory, Domestic Logistics, Overseas Review, Procurement, Products, KOL and Finance only after domain regression tests.
- Require UI/endpoint/permission/save automation and human acceptance before Production merges/deployments.
- No new Enterprise AG Grid dependency or license without Owner approval.

## 5. Change control
- Changes to the shared grid require regression checks on existing adopted modules.
- Exceptions must be documented with the business-specific reason; a page may customize cells/actions but must not duplicate the engine.



## Order-line view mode — R3.5
- Orders with multiple items render one visual row per SKU item. **No new ERP order is created** for extra lines; server pagination, totals, archived/fulfillment state and workflow identity remain order-scoped.
- Keep the same clickable ERP order number on each line to communicate grouping. Duplicate status mutations and action controls must be hidden on continuation lines.
- Import/export files express one SKU per row with an explicit repeated canonical grouping key (`external_order_number`), inherited and consistency-checked order-level fields, and a preview grouped into canonical orders. Preview must show both order count and line count.


## R3 performance acceptance standard
- Resolve ERP Vue pages lazily through Inertia/Vite. Do not eagerly bundle unrelated list, Finance, Inventory and Logistics page components into the application entry.
- Publish hashed JS/CSS assets with gzip/Brotli and long immutable caching ONLY for the hashed /build/assets/ files; do not cache authenticated HTML or Inertia API JSON as static content.
- Measure both data-query time and cross-region browser asset transfer before treating a slow page as a grid-rendering problem. Large canonical order counts must not disable bounded server pagination.
- Run baseline and scale tests with actual item-line multiplicity, and verify permissions, legacy-page route resolution and rollback before Production release.

## R3 final release gates
- R3 must pass a Production canary behind per-account allowlisting before R4 migrations begin. Production flags default OFF and bulk import/export have independent enabling gates.
- A multi-SKU order remains one canonical Order for paging/status/actions. Filtering SKU/product/line quantity must match the **same SKU item line** and display/export only matching item lines; the full parent order's total remains available separately.
- The browser UI and server must enforce the same allowed operations and permissions. New import endpoints must not become enabled simply because the read-only grid is enabled.
