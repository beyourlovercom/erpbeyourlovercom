# ERP SiteOps R6-A → R2-B — 007windows current-main Dev read-only UAT (2026-11-15)

## Release scope and provenance

- **No Production deployment and no main merge**. This work lives in Draft PR #766 under `apps/erp-siteops-vben`, isolated 007windows `D:\ai\erp-siteops\frontend` and `http://127.0.0.1:18786/`. Original SiteOps on 18778 and official ERP Production are unchanged.
- Original ERP source: canonical Laravel `beyourlovercom/erpbeyourlovercom` at **`0508a1263e51cfeae8b2f3036bc4f3d83df52386`**, the main revision after PR #780's approved `会计 → 算账` release.
- R6-A snapshot of `config/erp_shell_navigation.php`: 13 business first-level categories, 71 original second-level entries, one locally added `订单总表` child mapped to canonical GET `/orders`; no fake `BYL ERP` menu ancestor. `/#/erp/orders` redirects to `/#/erp/orders/orders-master`.
- Child routes not yet migrated intentionally show the canonical path, status and a clear *not-yet-migrated* message; they do not simulate business features. R3/R4 migration still pending.
- True authorized inventory warehouse shortcuts and per-role menus are computed by original server `ErpShellNavigation::for(user)`, **not** invented from the frozen static menu source.

## R2-B: source, session, permissions

- Exact current-main Laravel worktree on Mac mini: `/Users/a/ai/uat/erp-siteops-r2b-current-0508a126` served at private tailnet `http://100.94.52.109:18810`; `APP_ENV=local`, `DB_CONNECTION=sqlite`, database `/Users/a/ai/uat/erp-siteops-r2b-current-0508.sqlite`.
- This database is an **isolated UAT fixture** with 126 generated test orders, 3 archived/deleted/operationally archived exclusions, 11 active Dev warehouses and four role-specific `@local.invalid` test accounts. It is **not copied from ERP Production** and its rows must never be described as real customers or live orders.
- Independent 007 Node Owner UAT gateway `runtime/gateway.cjs` proxies only allowlisted GET `/api/erp/current/orders`. New `runtime/r2b-current-gateway.cjs` uses the original Laravel login form with CSRF and session cookies, then performs only **existing** GET `/orders` or GET `/customers/orders`; no Laravel business controller, policy, query, or route changes.
- Backend code calls `KolOrderAccessScope::scopeFilters` and `OrderService::filteredQuery()->eligibleForOperations()`, preserving the original staff/KOL owner, archive/delete, order number/status/workflow logic. No access tokens or Laravel session cookies are shipped to the frontend browser bundle. User identities in the test selector are controlled UAT fixtures; **not real employee impersonation or production authentication**.
- Projection removes addresses, phone, email, customer data, notes, price/revenue, original identities not needed, and all write endpoints. Only order identifiers, type, status, date, shipment warehouse, SKU and tracking survive.
- Strict server GET-only; local gateway POST business endpoints return 405; anonymous returns 401; invalid selectors return 400. Old Dev bridge remains separately present but the local flag `VITE_ERP_DEV_BRIDGE_ENABLED=0` disables it for R2-B. `private/\*.json`, `.env.production.local` and Mac `.env`, SQLite and passwords **must never be committed**.

## Verified results on 007windows

- PHP Laravel **original** controller + session result:
  - Owner UAT: 123 authorized orders, 50 rows per default page, 7 authorized first-level groups, 11 active warehouse shortcuts.
  - Operations staff UAT: 123 orders, 4 authorized groups, no inventory warehouse shortcuts.
  - KOL specialist UAT: 20 owner-only KOL orders, 3 authorized groups and no inventory shortcuts.
  - KOL manager UAT: 41 KOL orders (broader KOL management scope), 3 authorized groups.
- Search for `EX-R2-00042` from the original Laravel GET returns 1 match; clearing search restores 123 for Owner. The visible deleted/archived check is 126 inserted vs 123 operationally eligible. Original `/customers/orders` supports 20 per page and has 82 customer-only visible rows.
- Ordinary `/orders` **fixes page size to 50** while the OMS Grid canary is disabled; intentionally do **not** force `oms_grid=1` from Vben, even when 20/100 would be convenient. 20/50/100 on existing customer grid can be used where backend supports it.
- `runtime/r2b-current-smoke.cjs` checks 401/405/400, 4-role totals, fields scrubbed, dynamic warehouse navigation, customer per_page20. `runtime/r2b-current-vben-uat.cjs` checks actual 007 Edge UI, source switch, 4 roles, dynamic sidebar, KOL hides inventory, AG Grid, search and pagination, no synthetic row fallback and horizontal layout.
- 007 Dev R6 static navigation test `runtime/nav-r6-uat.cjs`: 13 groups, routes for logistics, accounting and inventory and deep-link redirect. TypeScript/Vite build success.
- The full Vben vendor base is still a locally approved source snapshot; this branch carries a versioned **overlay**, not all vendor files. Exact full baseline pin/reproducibility and final employee-session/role matrix remain future gates.

## Caveats / gates

- Sidebar is full approved owner taxonomy on initial local UAT login; it is replaced by the actual Laravel-authorized subset **after selecting a Dev role / loading orders**. Role switching is Dev-only. There is no Production employee session in this preview.
- Actual warehouse shortcuts only come from Laravel after a valid current-Dev read; never synthesize names or duplicate warehouse records from menu config.
- Real Production orders, role provisioning, WordPress/WooCommerce, OMS, Warehouse/Logistics business writes, background workers and migrations are OUT OF SCOPE.
- Previous R6 skeleton can show a transient duplicate grid for a legacy redirect during route transitions. Direct master route stabilizes; do not silently claim full workspace tab behavior is accepted.
- Before any ERP frontend Production cutover: pinned Vben baseline, actual staff 30-tab/3 pinned equivalent, all module route contracts, role visibility at initial login, 007 Chrome desktop+mobile Owner visual PASS, no business write regression, and separate Owner merge/release approval.
