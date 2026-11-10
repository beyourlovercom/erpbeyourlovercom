# ERP Global Grid V1 R4-L1: Logistics first Dev implementation (2026-11-10)

Scope: 3 of the 51 Owner-selected list entries, in canonical execution priority:
1. /logistics/china (all 9 existing operational tab keys; selected China 7-view group)
2. /logistics/wait-stock (main/all tab Grid only, local specialist subfilters stay native)
3. /logistics/review (main review list; original required decision/note form retained)

## Engineering design

- Reuse GlobalGrid.vue / OmsGrid.vue and existing GlobalGridCell; one thin useGlobalGridListAdapter.ts for canonical route query/search/paging/toggle, preserving exact URLs and server-authorized data. No new Grid engine.
- The initial L1 build used opt-in for all three screens. After the Owner adjustment, China domestic ONLY defaults to Global Grid for existing authorized logistics users and retains explicit ?global_grid=0 fallback. Allocation and review retain the prior owner opt-in gate. No new permissions or current Production rollout.
- Backend search is server-side over the authorized query before pagination: ERP order number, original external number and recipient name. Existing business view filters run first; search never crosses authorized warehouse/role boundaries.
- Paging 20/50/100 only inside canary, legacy 50 intact. Existing source order numbers, domestic SKU/quantity/amount, carrier, Tracking and sync-status data are projected. No business mutation or schema.
- China: all existing tabs can display read-only Global Grid as the main listing. Each Grid action opens the exact original operational row below; the original full-batch table is still available through Use Original Batch Operations. This is an operational bridge, not a reimplementation of carrier selection, stockout/restock, Tracking draft/confirm, batch export/upload or web sync. Existing native actions remain connected to original routes.
- Wait-stock: the full tab uses Grid; specialist local tabs stay native until separately proven server-filtered. The existing View Plan / confirmation and stock-exception dialog continue unchanged.
- Review: Grid lists pending reviews, but only roles passing LogisticsExecutionAccess see Fill Review. Clicking reveals the exact existing decision/note form for that one order. Super-admin is deliberately read-only for execution and must not acquire a write button.
- Retain old view fallback and full page-specific business field / permission tests. No real ShipOut/Woo sync, outbound dispatch, stock confirmation or review POST during Chrome UAT.

## Checked gates (branch local)

- npm run typecheck PASS, npm run typecheck:e2e PASS, npm run build PASS.
- Domestic Index Vue 20 tests + shared Global Grid 4 tests: 24/24 PASS.
- Four domain PHP test suites: 67 PASS / 1 skipped, 782 assertions, including domestic review/stockout/shipping rules, workbench exports, allocation and tracking state.
- New R4-L1 feature tests: 2 PASS, 111 assertions, covering server search, 20/50/100 bounded paging, default old-list data and permission barrier.
- Real local Chrome + disposable SQLite synthetic fixture: old/new domestic, original row actions/batch toggle, allocation search and fallback, super-admin review read-only, old table fallback; whole Global Grid suite 4/4 PASS, external disposable test files cleaned.
- Owner **visual UAT pending**. CI for exact final PR head pending until GitHub PR is created.
- Dev PR must remain Draft. Do not merge or deploy Production unless separately authorized after Owner UAT.

## Owner's two domestic workbench adjustments - 2026-11-10

1. Domestic-only default: The authorized domestic logistics GET /logistics/china chooses Global Grid V1 when no global_grid query is present, including an eventual approved Production release for existing authorized logistics employees. Explicit ?global_grid=0 is a one-click legacy fallback. Other list pages retain their original GlobalGridPilot canary restrictions. No Production deployment occurs here.
2. Title-row placement: The '返回原表' button is now the final, rightmost button in the top domestic page header (.domestic-head-actions, beside 待分配 →), not a separate strip immediately above the data table. In legacy mode the same location reads '切换新版表'.
3. Tab persistence: China shipping views preserve ?global_grid=0 when using the legacy table. New navigation without opt-out defaults to new Grid. Roles, shipment, review, XLSX import/export, OMS and Tracking writes unchanged.
4. Verified: PHP focused test 1 PASS (58 assertions), Vitest 24/24 PASS, Vue typecheck PASS, Vite build PASS, Chrome all Grid pilot suite 4/4 PASS including default, header placement, original fallback and cross-tab retention. CI to rerun on updated PR head.

## Owner UAT checklist

- China: review, stockout, pending, today, today-4px, today-weship, exceptions, 4px and weship view parity; ERP source number, SKU, quantity, currency, Tracking, sync status; single-order/native batch operations and one-click legacy switch.
- Allocation: full all tab Grid, view plan modal, original specialist tabs and stock exception gate.
- Review: true read-only admin, genuine logistics operator form via existing workflow, mandatory destination/note, no hidden write privilege.
- Browser needs authenticated Dev test URL; no Production action.
