# BYL ERP OMS Grid V1 — R3 Orders real-data adapter UAT

Status: R3 implementation / isolated Windows validation completed; **Laravel DB/role UAT pending**.
Related: Draft PR #628. Frozen standard: `docs/BYL-ERP-GRID-STANDARD-V1.md`.

## Deployment gate
- No change to existing `/orders` by default. In non-Production environments only, users with `orders.view` see "体验 OMS Grid".
- Pilot URL: `/orders?oms_grid=1`. "返回原列表" removes the opt-in.
- `/customers/orders`, `/kol/orders`, and external-warehouse procurement orders remain on the legacy grid.
- Production ignores opt-in and never renders the new live Orders grid.
- Do not merge or deploy to Production before Owner real-data UAT PASS.

## Reused ERP authorities
- Same `OrderController@index`, `OrderService::filteredQuery`, same saved views and permissions.
- Existing `/orders/{id}/workflow-status` PATCH and revision conflict handling retained via `OrderWorkflowStatusPill`; no new mutation endpoints.
- Order number and View actions preserve `/customers/orders/{id}` and `/kol/orders/{id}` navigation.
- Existing restricted export endpoint remains unchanged and only visible to authorized users.
- Server pagination stays authoritative; source count is `orders.total`, never `orders.data.length`.

## Server-grid behaviors
- Column title row only, no group titles, no client floating filters in server mode.
- Server allowlist sorting: `order_number, date, source`; date desc default. Unsupported columns are not sortable in R3.
- Page options 20, 50, 100, 全部. "全部" loads at most 200 filtered orders; if total > 200, UI refuses before navigating; backend also clamps direct URL attempts.
- Server queries determine the final global sort/filter result. AG Grid may briefly reorder the loaded page during a header click before the server navigation completes; existing page form remains the authoritative search/filter interface.
- Left/right fixed counts, column order/visibility, resize, maximize and account preferences reuse R2 shared component.
- Order Grid is loaded as a separate lazy JS chunk to avoid imposing AG Grid on all ERP routes.

## Completed isolated verification
- TypeScript typecheck: PASS.
- Build: PASS; `OrderOmsGrid` and `OmsGrid` emitted as lazy chunks (existing base bundle size warning remains).
- Vue/Vitest: 14 tests PASS (Orders legacy + pilot opt-in + shared grid + real-data adapter).
- PHP syntax checks: PASS (controller, service and Feature test).
- Headless Chrome server-grid synthetic UAT: one visible footer, correct page-change intent, "全部" >200 refused, maximize/Esc works, zero JS exceptions.
- Laravel DB Feature test was added but not executed on Windows isolated copy because `vendor/autoload.php` is unavailable.

## Required Dev real-data acceptance before merge
1. Login as orders.view-only staff and open `/orders?oms_grid=1`; confirm no edit permission, and compare totals/rows to legacy `/orders`.
2. Login as authorized orders.editor; change one harmless test order workflow status through the existing dropdown; confirm success, concurrency/version, and 409 rollback paths.
3. Test original ERP order number/detail navigation, Woo external-order identity tooltip, KOL-derived rows where permitted, status pills and owner.
4. Test real server page 1 → 2, page size 20/50/100, sort order_number/date/source, and all saved business views; compare to legacy.
5. Test "全部" on filtered result <=200 and refusal >200; attempt direct URL `per_page=all` with >200 to verify backend fallback.
6. Confirm export, Woo sync controls, saved-view create/delete, orders permissions and archive links remain unchanged.
7. Confirm column pinning, drag order, account persistence and full-screen mode; check desktop/midsize viewport.
8. Only after Owner PASS prepare separate exact-head main merge and controlled Dev/Production rollouts.
