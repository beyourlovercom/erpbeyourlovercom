# ERP SiteOps / Vben R6-A — owner-approved navigation recovery (2026-11-15)

## Exact source and frozen semantics
- Source: ERP canonical GitHub `main` at `0508a1263e51cfeae8b2f3036bc4f3d83df52386`, file `config/erp_shell_navigation.php`. The version-pinned snapshot is in `overrides/src/router/erp-menu-source.ts`.
- Snapshot contains **13 top-level categories and 71 configured secondary routes**. This count is different from the earlier Excel V3 snapshot; newly introduced main entries are retained. `accounting` displays **算账** without changing the original Laravel URL `/accounting`.
- **No BYL ERP sidebar grouping**: each original first-level business module is a Vben top-level route. The app's masthead still displays a product name, which is not a navigation category.
- Orders is a root group with a dedicated `订单总表` child mapped to the existing `/orders` source URL. This adds **one navigation-only view** to the 71 canonical children. `/#/erp/orders` redirects to `/#/erp/orders/orders-master` for old 007 bookmarks; the `/#/erp/overview` default route remains.
- All other ERP children retain original labels, grouping, order, relative URL reference and source implementation status; no business button is invented.
- **Dynamic inventory warehouse shortcuts** in `ErpShellNavigation::for(user)` cannot be truthfully reconstructed from `erp_shell_navigation.php` alone. They remain an R2-B runtime server-authorized navigation dependency, not guessed or statically created in R6.

## What R6-A does and does not do
- This is an **Owner-only local Dev preview** on 007windows `http://127.0.0.1:18786`. It does not enforce Laravel's staff permission filtering yet; only local Owner access is allowed.
- Child pages other than the Orders master are explicit *not-yet-migrated* containers displaying the original ERP path and source status. Do not claim that the backend business features have been migrated.
- Original ERP navigation authorization relies on `ErpShellNavigation::for(user)`, with `permission`, `permissions_all`, `permissions_any`, and active warehouse data. R2-B must retrieve that server-side after Laravel Dev authentication and render permitted subsets only.
- Original ERP production UI, server 30+3 tabs and SiteOps Vben V9.1 site registry are unchanged.

## UAT evidence
- 007windows `cmd /c build.cmd` passed Vue typecheck and Vite build.
- 007windows browser passed: 13 first-level categories; visible secondary names including logistics, order master and 算账; `/#/erp/orders` redirect works; Orders master AG Grid renders. Logistics / China, inventory / list, accounting / overview routes show correct titles and source links.
- The prior legacy browser smoke `getByText('UAT-ORDER-001')` is strict-ambiguous immediately after redirect due to two short-lived visible components; the same direct master route shows one grid. Use the new `nav-r6-uat.cjs` for R6; fix transient double mount separately before claiming complete no-tab regression.

## Mandatory R2-B acceptance
1. Current Laravel Dev (not the older Mac `erp.b` SQLite) with per-user session, role-scoped original `GET /orders`, server-side paginator and no writes.
2. Dynamic warehouses from original `ErpShellNavigation::for(user)` for authenticated staff; no client-made permissions.
3. Order number, workflow state, KOL row scope, archived/deleted filtering, server search/sort, page sizes 20/50/100.
4. 007 Chrome PC/mobile visual test; no PR merge or Production publish without separate Owner authorization.
