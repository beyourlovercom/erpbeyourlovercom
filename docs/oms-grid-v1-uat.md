# BYL ERP OMS Grid V1 / R0–R2 UAT

## Scope and safety
- Source: AG Grid Community 36.3.0 + AG Grid Vue 3; Balham theme.
- Entry: `/_uat/oms-grid` (authenticated, only local/staging/development/testing APP_ENV).
- Preview orders are synthetic and client-side. No real ERP orders, warehouse stock, fulfillment or accounting endpoints are modified.
- No Production route or data writes are introduced. Demo preferences use local browser storage only.
- This is a shared component plus preview, **not** a migration of existing order/inventory/fulfillment lists.

## UAT acceptance checks
1. Confirm rows stay compact (32px), group headers and floating column filters show.
2. Drag header edges to resize, drag header cells to reorder, sort, filter, search, select rows.
3. Open right-side column settings: hide/show, reorder with drag; mandatory orderNumber and actions cannot be hidden.
4. Set left pinned 2, right pinned 1; scroll horizontally; confirm both sides stay fixed.
5. Set excessive pins and confirm a middle scrollable column remains; hidden columns are not pinned.
6. Click **最大化**: ERP navigation is visually covered by a full-window grid; Esc restores the page.
7. Switch density / page size, reload: browser-local view preference is restored.
8. Export CSV; import a local sample CSV with header `sku,quantity,customer`. Validate these operations affect demo only.
9. Use a slow machine to inspect scrolling, sorting, and filtering 1,330 demo rows.
10. Confirm no Enterprise module or license is bundled.

## Future production adapter
- Use `<OmsGrid>` with domain-specific column definitions and row data.
- Preserve server pagination, filter/sort contracts, export endpoints, permissions and workflow actions.
- Account-level preference mode uses the existing `/ui/preferences/{namespace}` API.
- For large / authoritative datasets, implement a server-side adapter; the preview's client-side 1,330 rows are **not** a replacement for back-end pagination.
- Keep Production changes gated behind separate owner approval.
