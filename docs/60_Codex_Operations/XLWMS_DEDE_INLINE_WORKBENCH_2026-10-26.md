# Dede Oversea Workbench — Inline XLWMS Fulfillment and Status, 2026-10-26

## Owner scope and design correction
The Owner rejected the standalone technical /xlwms preview page as a Dede-facing fulfillment surface, and expressly directed the ERP existing Logistics → Oversea Fulfillment → US-3SM workbench to own API submission, OMS state, Tracking, and inline error handling.

This Draft branch is stacked on Draft #513, with underlying real T2 create-and-cancel UAT #512 and older #501/#504/#510/#511. Do not merge/ship the stack out of order. Standalone /xlwms URL may remain a local administrative debugging path, but the normal workbench no longer links to it. No public main merge and no Production ERP deployment.

## Implemented
- On /logistics/oversea/us-3sm?view=pending/review/all, the old Dede warehouse grid contains a new prominent fixed "世迈 OMS · API 发货 / 状态" order-row column. Other warehouses and their export/tracking workflows remain untouched.
- Existing Dede review/approve/reject, edit carrier/service, manual Tracking and all grid preferences persist.
- For approved pending US-SM orders without any attempt, "API 发货" performs read-only local preflight via new limited GET /xlwms/preflight route. It returns only safe readiness, blockers, hash, warehouse, SKU/quantity, and order id; no recipient, address, API keys or raw request sent to browser.
- The second inline "确认 API 发货" control appears only if the current authenticated operator can operate logistics AND backend config exact XLWMS_LIVE_ORDER_ID matches the row while XLWMS_LIVE_DISPATCH_ENABLED=true. UI double-click lock, backend unique attempt, signed OMS duplicate checks and no blind retry remain enforced. No bulk submit, no default automation.
- A row with an attempt instead displays OMS number, verified status (3=已出库, 4=已取消, other codes unresolved), Tracking read-only (not ERP-applied), and "更新 OMS 状态" call. On missing OMS number / uncertain attempts, operator sees "核对 OMS（不重发）" rather than create.
- Inline red Chinese failures with standard GlobalFeedbackIsland; no transition to an error page. Server-saved last known status is preserved when provider responses are uncertain.
- Existing US-SM manual ship+Woo tracking fallback now must not bypass an OMS API attempt: when attempt exists, it requires provider-confirmed status 3 AND an exact matching verified OMS tracking number. Cancelled/unknown API attempts cannot be completed via the old manual control.
- All new OMS checks are manual, read-only. Periodic status polling, automatic ERP/Woo state transitions and automatic Tracking writeback are explicitly out of scope until a separate reviewed and approved release.

## Real isolated Dev data / UAT
- China Mac local ERP: http://127.0.0.1:8912/logistics/oversea/us-3sm?view=pending
- Existing isolated SQLite test order #8 TEST-XLWMS-001 has one completed API attempt OBS0272610260RS status 4=已取消, none shipped, Woo unchanged. XLWMS_LIVE_DISPATCH_ENABLED=false, T2 flag disabled.
- Live local Inertia server HTML payload validated: component Logistics/Oversea/Show, warehouseKey us-3sm, test order #8 visible, OMS number OBS0272610260RS, status 4, canOperate true, xlwmsAuthorizedOrderId 0. This proves canonical existing workbench data routing, not just a mocked frontend.
- New browser tab opened in China Mac Chrome for live Owner UAT. Browser JavaScript Apple Events did not return a reliable page DOM inspection in this last check; final Owner visual UAT PASS cannot be claimed without the Owner inspection.
- No real OMS write was performed in this branch.

## Regression gates
- PHP scoped regression 89 tests / 692 assertions PASS before final Chinese blocker-label polish; subsequent tests/CI must remain green.
- Vue unit tests: original Oversea Show plus new inline preflight/error/status/single authorize-send tests; old standalone status page tests remain intact.
- TypeScript and Vite build PASS, no production deploy.
- #501 WarehouseShow.vue main conflict still requires careful release integration preserving Inventory inline editing.
- Unified Product SKU Alias V1 is a separate approved feature and must remain the one source for SKU aliases; do NOT revive old per-warehouse SKU Mapping #508 without new approval.
