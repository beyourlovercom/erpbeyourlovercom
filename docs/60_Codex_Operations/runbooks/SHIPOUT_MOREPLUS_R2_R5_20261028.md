# MorePlus / ShipOut R2-R5 sandbox-gated integration
Canonical ERP warehouse: US-LA (legacy slug us-moreplus). US-SM is distinct ShiMai.
Owner authorization: isolated development/testing only, NO Production release or real writes.

## Observed R1 (2026-10-28)
- Signed warehouse GET HTTP 200 result OK, records=1.\n- OWNER R2 live: warehouseId 1531580902677626882, warehouseName ShipOut-Warehouse-Test-OpenAPI.
- Signed stock GET HTTP 200 result OK, records=0 total=0.
- Signature and sandbox Bearer accepted. No verified real provider SKU quantities.
- Do not share/commit Bearer, AppSecret, order recipient details.

## R5 backend foundation
- ShipOutSignature: signed v1 request.
- ShipOutReadOnlyGateway: ONLY the 2 sandbox GET endpoints, fail closed, off by default.
- ShipOutMorePlusStockPreflight: US-LA read-only projection, verified Product OS alias;
  duplicate provider SKU -> one canonical ERP product blocked, empty means unknown.
- No scheduler, live Dede workbench, WarehouseInventory mutation, or outbound dispatch.
- Production release is a separate Owner PASS gate.

## R2 warehouse identified; product list not yet verified
- China Mac operator reruns local script (copy sandbox Bearer LAST, press up/Enter).
- Capture only nonsecret warehouse ID/name and SKU counts. Never print JWT.\n- Product GET endpoint: /open-api/oms/product/queryList per vendor public Swagger. PHP adapter now supports an allowlisted read-only first page; China Mac script prepared to check actual sandbox catalog.\n- Warehouse ID is sandbox only and MUST NOT be adopted as US-LA Production warehouse ID.
- Need confirmed product listing endpoint/spec before product API calls.

## R3 pending warehouse-assisted inventory acceptance
- Create a unique sandbox-only test SKU after verifying product schema.
- Create test inbound, then ask ShipOut support to RECEIVE sandbox inbound.
  Saving inbound is NOT available stock. Verify availableQuantity separately.
- SKU immutable approval/packaging and SKU Alias physical identity review.

## R4 pending outbound acceptance
- Official POST /open-api/oms/order/batchSubmitAsync.
- Require valid SKU, nonzero stock, exact warehouse ID, configured carrier,
  unique order number, preview and idempotency before first sandbox POST.
- Async acknowledgement is not a shipped order; query final state and exceptions.
- ShipOut support may need to progress sandbox order manually.

## GATE
No ERP Production data writes or scheduled sync, no automatic dispatch, no claim
that real MorePlus inventory/order fulfillment has been verified.


## R3 real sandbox acceptance, 2026-10-29
- China Mac secure token file: ~/.config/byl/shipout/sandbox_bearer.token (chmod 600).
  Secret stayed local; no value saved to GitHub.
- Product POST batchCreate: HTTP 200, result OK:
  - OMS SKU BYL-SHIPOUT-UAT-20261028-001
  - SKU ID 2115631489013882881
  - Warehouse ID 1531580902677626882
  - Subsequent GET queryList: total 1; linked WMS auditStatus=2 (approved).
- OMS inbound:
  - Wrong /open-api/shipout/inbound/listInbound endpoint returned HTTP 200 with
    gateway message "Can not find healthy upstream url" (not an authentication error).
    Never use that API path for this sandbox.
  - Correct OMS GET /open-api/oms/inbound/list: HTTP 200, result OK, total 0.
  - Correct OMS POST /open-api/oms/inbound/saveOmsInboundOrder: HTTP 200,
    item result OK; inboundBizUniqueNO BYL-SHIPOUT-UAT-IN-20261029-001;
    inboundOrderNO IB261028-1; inboundingId 2115633184438902785;
    SKU count 5, carton count 1.
  - Post-create GET /open-api/oms/inbound/list: total 1, status=2 (pending inbound).
  - Stock GET remains total 0. Vendor warehouse has NOT received goods.
- Idempotency: local sandbox one-shot sentinel exists; no blind POST retry.
- R3 is partially accepted: actual create/query PASS; physical receipt and
  availableQuantity>0 NOT ACCEPTED.
- R4 still BLOCKED on vendor sandbox receiving/fulfillment data.
- Added read-only OMS inbound status GET to ShipOutReadOnlyGateway, no
  real ERP inventory writes, outbound dispatch or Production calls.

## Owner scope correction (2026-10-29) — overrides prior R3/R4 dependency wording
**ERP integration owns OUTBOUND + STATUS/TRACKING only.**
- Inbound creation, physical receipt, putaway, warehouse inventory adjustment,
  WMS access/role or workflow and warehouse billing are OUT OF SCOPE.
- Historical sandbox inbound IB261028-1 may remain as isolated test data; no
  further receipt testing or additional inbound API implementation is planned.
- In-scope: ERP US-LA allocation -> Dede approval -> manual operator API submit
  -> idempotent ShipOut order reference -> OMS order status/outbound status
  -> carrier/tracking/shipped time polling or callback -> ERP -> Vita Woo sync.
- Inventory read only may help availability checks; this is not inventory
  management or warehouse inbound write authority.
- Provider API confirmed for outbound: POST /open-api/oms/order/batchSubmitAsync.
  Signed read-only tracking: GET /open-api/oms/order/queryListV2 and /query.
  Read-only sandbox orderListV2 probe returned HTTP 200 OK, 0 matching records.
- Async submit response and callback SUCCESS do NOT prove shipment dispatched.
  Use orderShipments[].outboundStatus, labelStatus, trackingNumber and
  shippedTime with state reconciliation; failures must be visible to Dede.
- If a SUCCESSFUL sandbox dispatch requires stocked test SKU, ask provider to
  furnish a testable pre-stocked SKU or seed fixture; NOT an ERP inbound feature.
  Negative tests can run without provider stock, but may never be marked PASS
  for successful dispatch or actual tracking data.
- Callback authorization and replay checks required before any use; fallback
  to signed polling. No Woo updates until shipment state actually confirmed.
- Existing OMS inbound status GET was REMOVED from ERP bridge per owner correction.
- No automatic order POST or production host currently exists in the PR.
