# ERP US-SM to XLWMS OpenAPI: Manual Status Query V1 (Read-only)

**Branch:** feature/xlwms-order-status-readonly-20261026, based on #512 then #511 / #510 / #504 / #501.
**Scope:** Continue developing gated API fulfillment and obtaining OMS order status. Production send, main merge and T3 fulfillment are separately gated.

## Live OMS API contract (only authenticated READ calls)
- Official endpoint: POST https://api.xlwms.com/openapi/v1/outboundOrder/pageList, signed.
- Exact OMS number query: {"page":1,"pageSize":10,"outboundOrderNos":"OBS0272610260RS"}.
- Live read returned precisely 1 record with total=1, exact outboundOrderNo, whCode, thirdOrderNo, referOrderNo, platformOrderNo, status, logisticsTrackNo.
- Real cancelled sample OBS0272610260RS returned status=4 and OMS UI confirmed 已取消. Existing OMS status=3 corresponded to 已出库. Do not infer numeric states 0/1/2/5+, show 待核实.
- Real China Mac Dev status query checkStatus(8) returned fresh=true, exact OMS order, status=4, label 已取消, no tracking. ERP order pending_shipment, Shipment pending and attempt count=1. NO outboundOrder/create call.

## Implementation
- Gateway readExactOrderStatus validates OMS order number, signed exact filter, response shape, single match, warehouse binding, numeric status. Empty/unreadable/ambiguous result fails closed.
- SingleOrderDispatch.checkStatus requires a prior dispatch attempt with known OMS number and unchanged warehouse. Checks OMS thirdOrderNo or exact ERP reference/platform number; preserves previous confirmed status on error with a safe failure code.
- Migration records oms_status_code, oms_status_checked_at, oms_tracking_no, oms_status_failure_code. No raw response, addresses or API credentials stored/logged.
- POST /logistics/oversea/us-3sm/orders/{order}/xlwms/status uses existing logistics.operate permission, execution guard, 5/min throttle, no-store cache.
- No scheduled sync, Woo writes, Shipment status changes or fabricated delivery times. Real outbound API dispatch stays OFF except Owner authorized exact order.
- Existing Vue UI is NOT altered: AGENTS.md demands prior OWNER_VISUAL_PASS before UI implementation. Proposed OMS Status block plus read-only button requires Owner visual PASS.

## UAT and release gates
- Tests cover exact filter, warehouse identity, manual reference, status 3/4, unknown status, absent order, previous status preservation, no network without attempt and no API create during status reads.
- Run full targeted Logistics/Woo regression, TypeScript check, Vite build and GitHub CI.
- SKU Alias V1 is approved separately for Product OS; avoid reviving the per-warehouse mapping PR #508.
- PR #501 currently conflicts with main WarehouseShow.vue and must retain existing inline inventory editing.
- Formal release requires integrated candidate, UAT/Owner visual PASS, explicit Production approval, read-only Production smoke and per-order Owner approval before physical dispatch.

## Owner visual design PASS, Dev UI implementation
- Owner explicitly replied pass to the compact OMS status panel visual proposal. Final live-page Owner UAT remains an independent acceptance gate.
- Existing Logistics/Oversea/XlwmsOrder.vue now includes an inline read-only panel with OMS number, confirmed OMS state, tracking, unchanged ERP state, last successful check, and manual read-only refresh.
- Manual refresh invokes ONLY the status endpoint. Failures appear as on-page red text and through the existing global feedback island. No navigation or outbound-create retries.
- Code 3 is confirmed 已出库 and 4 is confirmed 已取消; unknown states are literal numeric values labeled 待核实.
- POST status route requires only logistics.view (read-only), 5/min rate limit. It never grants logistics.operate nor enables create.
- China Mac authenticated ERP Dev: http://127.0.0.1:8912/logistics/oversea/us-3sm/orders/8/xlwms
- Actual browser click of 查询 OMS 最新状态（只读） yielded OBS0272610260RS 已取消, Tracking 暂无, ERP 待发货 and an OMS 状态已更新 global island success. No OMS write, no Woo mutation.
- 85 scoped PHP tests / 635 assertions PASS; 5 Vue component tests PASS; TS and Vite build PASS. GitHub CI must pass.
- Dev page opened in China Mac for final Owner UAT. Do not mark Owner post-implementation PASS without further explicit reply. Main and Production unchanged.
