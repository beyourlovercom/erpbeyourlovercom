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
