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
