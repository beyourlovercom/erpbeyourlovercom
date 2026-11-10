# ERP Global Grid V1 — R0 contract and R1–R3 Dev pilot (2026-11-10)

## Owner decision
Adopt a **single shared list/grid engine** for new ERP data lists; do not mandate AG Grid for compact detail tables, audit summaries or mobile cards. The production OMS Grid remains backward compatible: existing routes, account preferences, import/export controls and fulfillment/stock/finance mutations must remain untouched.

## Permanent decision confirmed 2026-11-10

The Owner ratified **Global Grid V1 as the single default component for all future NEW ERP primary business lists**. Implementation is the existing `GlobalGrid.vue` wrapper around `OmsGrid.vue`; preserve the older `BYL_GLOBAL_DATA_GRID_VI_V1_1` visual/data/preference contract. Lightweight small embedded tables on detail pages, confirmation/audit snippets and suitable mobile cards are exempt from mandatory conversion.

This decision **does not authorize** silently replacing all existing lists, changing current Production pilot/allowlist flags or staff defaults, or editing shipment, review, import/export, procurement, inventory, accounting or access-control workflows. Existing page migration is selected separately for R4 batches; preserve old view fallback plus domain-specific UAT, original backend permission gates and explicit Production authorization. The approved source of truth for ERP implementation is `docs/BYL-ERP-GRID-STANDARD-V1.md`; the already-active `AGENTS.md` rule `BYL_GLOBAL_DATA_GRID_VI_V1_1` continues to govern visual, data, query and preference behavior.

## Architecture contract
- `GlobalGrid.vue` wraps existing `OmsGrid.vue`; neither fork nor recreate AG Grid internals.
- A business page supplies column schemas, display projections, links, safe authorized actions and **canonical** server paging. No grid component performs business writes.
- Unique preference namespaces (e.g. `grid.products.global.v1`) and account-scoped settings; never overwrite established `grid.orders.all.oms.v1` or legacy column configurations.
- Standard density 32px, header 31px, adjustable columns/pins, 20/50/100 paging when supported, UTF-8 Chinese labels, keyboard focus, horizontal scroll.
- A single built-in **global server-side search box** replaces the redundant outside keyword search in the three canary views; it never filters only the visible page. Retain warehouse/status selectors in the external filter area, with server query state preserved on navigation. Legacy view retains its original search input. Product/inventory/procurement retains its real route/permission and status semantics.
- **Explicit opt-in canary:** `global_grid=1` works in local/testing/development; after the 2026-11-10 Owner Production release authorization, Production permits this opt-in **only for authenticated ERP Owner ID 1**. Without the query parameter, all users see the original tables. Other Production employees cannot enable the pilot.
- Product standard view only; factory measurements/accessories stay untouched, with their task actions and selections.
- Inventory stock table reuses the repository's existing **server-side** pager; no new inventory queries or projection rewrites.
- Procurement pilot replaces current unbounded `get()` only for pilot mode with `paginate(50)`, while legacy route/response remains unchanged.
- No auto-enabling, new database columns, migrations, account permission broadening, unbounded list export or warehouse/order mutations.
- Keep existing functional fallback for each pilot: omit `global_grid=1`.

## Performance and safety gates
- Avoid loading the grid engine on legacy views (Vue `defineAsyncComponent`). No global layout import.
- Hard ceiling per page: 100 product records, 100 inventory records, 50 procurement records. Do not expose an unbounded “全部” for pilots.
- Server-authoritative sorting/search/pagination where implemented; otherwise disable in-grid sort rather than silently sorting just the current page.
- Pre/post run: exact independent branch/SHA; TypeScript, Vite, relevant Vitest + PHP feature regression; repeatable browser Dev UAT.
- Compare bundle transfer size (gzip), browser loaded entries and timing; report measured values, not invented universal performance claims.
- No deployment to Production or merge to main in R0–R3; require separate Owner approval.

## Rollout ownership
- R0 contract frozen.
- R1 shared adapter and common low-level cells, configurable controls, lazy-loading.
- R2 products (standard list), inventory products, procurement orders canary.
- R3 isolated Chrome UAT, regression and performance evidence, Draft PR with exact head.

## Non-goals
Changing order fulfillment, product mutations, inventory reservations, purchasing API status transitions, account grants, list export contracts, data schemas, existing mobile procurement cards, or all 62 legacy table pages.
