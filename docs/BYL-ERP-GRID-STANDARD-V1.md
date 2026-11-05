# BYL ERP Grid Standard V1 — Frozen

Status: OWNER APPROVED / FROZEN (2026-11-05)
Scope: BYL ERP front-end list / table pages; this is the single standard for new tables and phased legacy migrations.

## 1. Core standard
- Use `ag-grid-community` + `ag-grid-vue3`, Balham theme, via the shared `OmsGrid` component.
- Do not create per-page standalone table frameworks. Domain pages supply column definitions, data adapters, authorized actions and links.
- One flat column-title row and, for searchable data columns, a separate AG Grid Floating Filters input row; compact one-record-per-row layout. Do not add grouped headers or embed input boxes inside the title row.
- Column resize, drag order, hide/show, persisted preferences, left/right pinned counts, maximize/Esc restore, sorting and filtering follow shared behavior.
- Exactly one pagination/footer region. Page size 20 / 50 / 100 / 全部 where safe; prompt about browser cost before 全部.
- Import/export are provided by the domain's existing backend authorities, not invented by the grid.

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
