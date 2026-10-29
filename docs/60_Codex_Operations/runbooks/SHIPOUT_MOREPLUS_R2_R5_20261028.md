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

## R4/R5 continued, 2026-10-29 — outbound and tracking only

### Official schema (ShipOut /portal/JSON/order.json)
- POST /open-api/oms/order/batchSubmitAsync: array of OpenCreateOrderRequest
  with sid, orderNo, orderSummary, shipmentForms, warehouseId, toAddress.
  Each shipment requires outboundInfo.sysServiceId, shippingInfo.carrierId,
  shipDate, shipmentSid, signatureType, and productList[].omsSku / qty.
- For U.S. MorePlus default, use warehouse-selected outbound service
  sysServiceId=9, USPS carrierId=1, signatureType=4.
- GET /open-api/oms/order/queryListV2 and /query support order status,
  outboundStatus, labelStatus, carrierId, trackingNumber, shippedTime.
- Async acceptance is never shipped; completed order is not sufficient alone
  without outboundStatus=4, tracking and shippedTime. Unknown/multiple
  shipments, label exception, cancelled/aborted must block auto completion.

### Real sandbox POST
- Local China Mac sandbox-only script sandbox_outbound_once.py issued ONE
  signed POST of a SYNTHETIC order with synthetic address/SKU in the
  isolated ShipOut sandbox. Intent/one-shot sentinel created BEFORE network.
- Synthetic ref BYL-SANDBOX-API-ORDER-20261029-001.
- POST /open-api/oms/order/batchSubmitAsync returned HTTP=200 but the JSON
  was a DICT rather than expected list (per Swagger). The first script did not
  preserve safe business result fields, so provider business outcome is
  UNKNOWN. Do not claim the order was accepted or shipped.
- Two signed GET /open-api/oms/order/queryListV2 readbacks for that exact
  synthetic ref returned HTTP=200, result OK, total=0. No tracking number.
- No automatic retry and NO second POST of that order. No stock available.
- No production API, ERP DB, Woo, WMS or physical shipment modified.

### R5 committed engineering foundation (OFF by default)
- ShipOutOutboundPayloadFactory builds one deterministic OpenCreateOrderRequest
  from ERP authoritative Order + Dede-approved US-LA shipment, source address,
  Product OS verified SKU identity and warehouse order reference.
- ShipOutSandboxOutboundTransport: fixed signed HTTPS sandbox async endpoint,
  single request, exact order/warehouse allowlist, no generic URL, no auto
  retry, no raw credentials or PII emitted.
- ShipOutSandboxDispatchService: provider read-before-write and atomic
  unique local attempt BEFORE POST; refused duplicate or unapproved order.
  Unknown transport result permanently retains uncertain status; manual
  signed OMS reconciliation required before any new attempt decision.
- shipout_dispatch_attempts migration stores non-PII hashes/results only,
  unique ERP order ID and provider order reference. Migration not deployed.
- ShipOutTrackingDecision pure status gate; outputs human-reviewed candidate,
  not a Woo update. Carrier/tracking and actual shipped time required.
- Runtime binds the connector classes using EMPTY-BY-DEFAULT env config.
  All sandbox toggles OFF, allowlist EMPTY; production and UI untouched.
- Tests (US Mac isolated): 27 tests / 59 assertions ShipOut PASS.
  Existing overseas workbench + architecture tests: 45 / 457 PASS after
  linking a test-only Vite build directory (not committed).

### Not yet complete — explicit gates
- Real provider successful outbound creation: NOT ACCEPTED (empty sandbox
  stock and unexpected provider response format, no successful readback).
- Actual tracking number, shippedTime, callback/polling readback: NOT ACCEPTED.
- No API-triggering Dede workbench UI (Owner visual PASS required before
  UI implementation). No production credential provisioning.
- Do not merge this draft into main or deploy until remaining gates and
  separate Production PASS. Production rollout will require a distinct
  credential/config design and application authorization security review.

### Additional safe provider error-shape probe
- POST /oms/order/batchSubmitAsync with an EMPTY array: HTTP 200, JSON list [].
  No business order included, no shipment created by payload.
- POST with one INTENTIONALLY INVALID record missing required orderSummary and
  shipmentForms: HTTP 200, top-level JSON object result ERR with validation
  message identifying missing fields. No valid shipment payload submitted.
- Transport now maps this confirmed ERR envelope to safe reason
  PROVIDER_TOP_LEVEL_VALIDATION_REJECTED. Raw provider message (potential PII,
  trace ID) is never persisted or exposed.
- Earlier one-shot synthetic order remains NOT CONFIRMED: POST HTTP 200 dict
  but exact business result was not captured; signed order query still empty.
  Do not infer the result was the same as the invalid-field probe.

## R5 Dede workbench controlled UAT integration (2026-10-29)
- Integrated directly into EXISTING Logistics/Oversea/Show.vue when warehouse
  key is us-moreplus, never a separate engineering/debug system.
- New ShipOut column: preflight, sandbox-only single-order confirmation,
  read-only OMS status/tracking, and gated operator-approved ERP tracking apply.
- Existing review/approve/reopen/export/file-upload workflow is preserved.
- For Production default-off view, it shows API disabled and retains manual
  MorePlus file workflow. Does not permit shipping via new UI.
- ShipOutMorePlusController has 4 explicit same-site guarded routes:
    GET preflight: logistics.view, safe local preview, no network if disabled;
    POST send: logistics.operate + execution gate + throttled, server-side
      fingerprint+confirmation+unique attempt BEFORE provider request;
    POST status: logistics.view, signed provider queryListV2 / query, read-only,
      no PII emitted, no ERP/Woo shipment update;
    POST apply: logistics.operate + execution gate + throttled, disabled by
      default; fresh provider re-query, confirmed shipped+tracking, exact
      matching OMS identity and local dispatch ledger mandatory.
- Fixed provider detail response mapper: official shipmentList, not
  orderShipments (important for real Tracking visibility).
- Failures are returned with machine-readable error_code, safe Chinese text,
  no raw provider response, retry_same_order=false and status 409/423.
- Tracking apply uses existing ShipmentService to update ERP shipment and
  OverseaWarehouseReview.progress only; writes pending manualSync for Vita
  but DOES NOT push Woo directly, preserving Vita release chain.
- Production mutations forbidden by config default. All 3 flags default false:
  SHIPOUT_SANDBOX_READ_ENABLED
  SHIPOUT_SANDBOX_DISPATCH_ENABLED
  SHIPOUT_SANDBOX_TRACKING_APPLY_ENABLED
  Strict per-order/warehouse allowlists remain required for sandbox dispatch.
- No cron, auto-post, production API base URL, or browser calls without user
  interaction. Sandbox tests use synthetic credentials and Http::fake.
- Actual ShipOut sandbox still has no nonzero available stock or successful
  outbound/shipped tracking evidence: R4 real provider E2E remains OPEN.
- No Dev deployment or human Dede visual UAT yet. Owner Production PASS
  required before merge/deploy and separate release of real credentials.
