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
- Single column-title row plus a distinct **AG Grid floating-filter row**; seven business-data columns (order number, source, date, recipient, country, work status and owner) have server-backed partial-match inputs. The non-data action column is not searchable.
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

## R3 2026-11-05 US Mac isolated end-to-end execution

Test root: `/Users/a/ai/uat/erp-oms-grid-r3-20261105`. Exact tested branch HEAD at setup: `610bc19fa26cb1e6236a499cc8623b147f344b0f`. Local APP_ENV=local, isolated SQLite file `database/oms-grid-uat.sqlite`, 225 synthetic customer orders, temporary local UAT operator with `orders.view` and `orders.edit`. No Production DB, API credentials, or customer records were used.

Executed:
- `composer install --no-scripts --no-plugins`, `npm ci`, `npm run build`: PASS.
- `vendor/bin/phpunit tests/Feature/Orders`: **82/82 PASS, 1,092 assertions**.
- `vendor/bin/phpunit tests/Feature/UserUiPreferenceTest.php`: **3/3 PASS, 11 assertions**.
- Authenticated Chrome headless flow at `http://127.0.0.1:8198/orders?oms_grid=1`: PASS:
  - 225 orders across 5 pages by default; page 2 shows different orders;
  - switching to 20 rows yields 12 server pages; requesting 全部 on 225 rows is refused and preserves 20;
  - filtering `source_type=manual` yields 112 rows; confirmed 全部 yields one server page of 112;
  - existing workflow dropdown PATCH to `renew` returned HTTP 200 on **synthetic test order only**;
  - hiding Source column persisted through a reload; reset brought it back;
  - fixed-left cells rendered; maximize uses fixed viewport and Esc restores;
  - canonical `/customers/orders/{id}` detail link navigated correctly;
  - 0 browser JavaScript page errors.
- A 403 appeared only on the login redirect's `/dashboard` for the limited-permission UAT operator; the authorized `/orders` URL opened and all R3 checks passed. Do not misreport as 0 HTTP errors.

Existing whole Vue suite result (isolated Windows): 731/736 PASS. Five failing tests are unchanged legacy Inventory pages; the test files and `WarehouseShow.vue` SHA match `main`. No unrelated Inventory scope expansion.

**Remaining approval gate:** Owner review of the R3 real Orders page in an accessible controlled Dev/staging deployment, ideally with a read-only or sanitized order dataset; confirm desired display of Woo external reference in one-line grid. Do not merge `main` or deploy Production until this visual UAT and exact-head authorization.
## R3 Search UX fix — 2026-11-05
- Root cause: R3 switched to authoritative server pagination, which intentionally disabled the R2 client-only quick search and floating filters and left search in the legacy page toolbar.
- Restored global quick search inside the OMS Grid toolbar with 450 ms debounced server navigation, Enter submit and explicit clear. Legacy top search input is hidden only when OMS Grid is enabled. Fixed numeric-only search bypass: `40223` now matches `007-1105-40223` by substring, while explicit `dbid:<number>` remains exact.
- Replaced the interim custom-header inputs with the original AG Grid floating-filter row: seven independent server-backed filters (order number, source, date, recipient, country, work status, owner). The action/checkbox columns do not have search meaning. No filtering of only the currently loaded page.
- Server sanitizes input to text only (up to 80 chars), supports date substrings and ignores array-shaped input; column filters are ignored on legacy views and Production where OMS pilot is disabled.
- Combined with the existing business views, search, export, sorting and pagination. Columns remain resizable, movable, pinnable, and grid can maximize.
- **UAT evidence:** authenticated Chrome with 225 synthetic SQLite orders: global search `R3-UAT-0123` returned 1, order-number filter `40223` returned 1, source filter `manual` returned 112, clear reset to 225; six inputs visible (before the seventh work-status filter was added), old duplicate search absent, 0 JS exceptions.
- **Regression:** `npm run typecheck` PASS; `npm run build` PASS; Vue scoped tests PASS; Laravel Orders + User UI Preferences **86 tests, 1,169 assertions PASS**.
- PR remains Draft and non-Production. Owner visual review is the last acceptance gate.

## R3.2 numeric substring + AG Grid floating filters — isolated acceptance
- Cause: OrderService explicitly treated numeric-only search as exact external order/reference/tracking lookup, bypassing the ordinary LIKE expression (including erp_order_id).
- Fix: keep the legacy exact-numeric contract untouched, but enable substring search in the opt-in OMS Grid mode only; explicit `dbid:NNN` remains exact in both modes.
- AG Grid server mode now uses its real floating-filter row (rather than title-inside-header inputs); seven data columns have per-column inputs routed to the server. The action column is not a data-search column.
- Authenticated Chrome UAT on SQLite synthetic orders: 40223 finds the matching ERP order (1/225), column-order-number 40223 matches 1, source manual matches 112, work-status 待发 matches 168; 7 floating search fields detected; zero JS page errors.
- Regression checks: PHP feature test covers OMS-only numeric substring, preserved legacy exact-number semantics, dbid exact, partial date input and status-column matching.
