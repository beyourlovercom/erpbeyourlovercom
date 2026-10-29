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

## Isolated Dede Dev browser UAT launched (2026-10-29)
- US Mac mini ONLY: standalone Laravel built frontend, independent migrated
  sqlite file shipout-dede-uat.sqlite and local/testing UAT seed (synthetic data).
  No production database connection, orders, or customer information.
- Server listens only on Tailscale 100.94.52.109:18841; external public
  publishing NOT performed. Temporary employee UAT credentials are stored
  OUTSIDE Git and never committed to this repository.
- Synthetic Dede user: logistics@example.test, role logistics.operate.
- Synthetic approved US-LA order: 007-UAT-MOREPLUS-001; separate from live ERP.
- APP_ENV=local, debug OFF, strict local sqlite DB, no Woo credentials.
- All ShipOut READ, DISPATCH, and TRACKING_APPLY toggles OFF and allowlist EMPTY.
  Tracking Sync V2 and XLWMS live dispatch stay disabled.
- Browser automation verified login redirects /dashboard, authenticated
  US-LA pending view HTTP 200, displays MorePlus ShipOut column,
  API preflight and read-only status entry, synthetic pending order.
- Clicking preflight displayed sandbox disabled and NO submit confirmation.
- China Mac Tailnet connectivity: login HTTP 200, unauthorized protected
  MorePlus view HTTP 302 to login. No public UAT domain deployed.
- Local UAT database dispatch attempt count = 0.
- Live sandbox GET: one product SKU approved, stock list total 0.
- Vendor docs: OMS product CRUD and stock GET do not provide an exposed
  OMS stock-set API. WMS manual inventory adjustment is a distinct
  warehouse-permission operation, not part of ERP outbound integration.
- R5 isolated Dev browser function smoke PASS; **human Dede visual UAT
  remains NOT signed off**. R4 successful sandbox fulfillment/tracking also
  remains NOT signed off. Do not merge or release to Production.

## Owner screenshot UAT review and fully autonomous Dev recheck (2026-10-29)
- Owner opened the private Tailscale Dev instance and showed the Dede page;
  asked assistant to complete UAT instead of requesting manual button checks.
- The screenshot revealed a UX regression: disabled API still displayed a
  clickable "API 发货预检" and duplicated preflight blocker text (preview plus
  error alert), overwhelming the shipping row.
- Corrected existing MorePlus column for full default-off configuration:
  compact "API 发货未开通" and manual XLSX fallback instruction, NO API buttons,
  NO duplicated red blocker. When authorized flags are enabled, retains single
  preflight and read-only tracking controls; all API mutations remain gated.
- Rebuilt isolation-only Vite assets and performed real Playwright browser
  login as the synthetic Dede account: MorePlus page 200, synthetic order
  visible, compact disabled message shown, no API action buttons and no
  red alert; workbench stays usable. No production UI changes.
- Browser negative test confirmed preflight/status read-only endpoints with
  API disabled. Direct send/apply probes hit the endpoint's request throttle
  (429 HTML response), while isolated dispatch attempt ledger stayed zero.
  Unit and route tests independently establish disabled dispatch/apply gates.
- Playwright confirmed manual MorePlus export link exists. Initial synthetic
  fixture had INVALID ERP order reference format, so export correctly returned
  422; fixed ONLY isolated SQLite test fixture to "007-UAT-c99999".
  Then browser export returned HTTP 200 and XLSX download content type.
  No changes to the original MorePlus export application.
- Revalidated after UI fix: Vue 20/20 PASS, typecheck PASS, original ERP
  overseas/tracking plus ShipOut backend 107 tests / 695 assertions PASS.
- UAT is still preview-only. No real ShipOut stocked SKU / successful provider
  outbound / real Tracking readback. Production remains untouched, Draft PR
  is not authorized to merge.

## R4 Real stocked sandbox outbound results (2026-10-29; verified China Mac signed API)
- Warehouse staff received prior IB261028-1 in their test WMS.
  OMS stock GET now returns SKU BYL-SHIPOUT-UAT-20261028-001,
  warehouse 1531580902677626882, availableQuantity=5,
  standardQuantity=5, outboundingQuantity=0, inboundingQuantity=0.
  R3 physical receipt/usable-stock gate PASS; no new inbound API implementation.
- First stocked outbound test: synthetic order
  BYL-SANDBOX-API-ORDER-20261029-002, 1 item.
  POST batchSubmitAsync returned HTTP 200 but business result ERR:
  "When sysServiceId value is 9, the carrierId parameter can only be 9".
  Provider order GET total 0; stock unchanged at 5.
- Corrected outbound payload for warehouse-selected service to
  sysServiceId="9" AND shippingInfo.carrierId=9, not USPS=1.
  Carrier/actual shipping service will be determined by warehouse;
  never promise USPS Priority from this request.
- Second distinct one-shot synthetic order
  BYL-SANDBOX-API-ORDER-20261029-003 (1 item), POST HTTP 200,
  response list[0].result=OK, provider orderId 2115708339011026946.
  Signed OMS queryListV2 confirmed exact orderNO and orderId (total=1).
  Initial order status=10 (Submitting); outboundStatus=0,
  no tracking. R4 API creation/identity readback PASS.
- Shortly afterward OMS order queryListV2 status moved to 6 (Cancelled).
  Read-only query/order detail returned exact cancellation reason:
  "Account Balance or Credit insufficient, Please add balance before proceed".
  shipment outboundStatus=0; no tracking/shipped time. Inventory still
  available=5, outbounding=0.
  R4 actual fulfillment/tracking still BLOCKED by sandbox OMS client
  balance/credit, NOT lack of stock, authentication, or SKU approval.
- Official ShipOut OMS Help Center documents this error and the need for
  sufficient client balance or WMS credit limit to submit orders.
  Do NOT attempt any real payment or self-authorize billing.
  Request provider to seed sandbox-only credit/limit; once confirmed,
  create a NEW unique sandbox order. Never replay or resurrect canceled
  or uncertain order references.
- Added safe known cancellation classification
  PROVIDER_ACCOUNT_BALANCE_INSUFFICIENT to status GET and Dede workbench.
  Real cancelled orders may have shipment.warehouseId=null. For
  cancelled/aborted statuses, no shipment can be trusted as shipped.
  Do not expose free-text cancelReason or trace data to ERP/API browser.
  Added gateway, decision, controller test coverage; no Woo/Production writes.

## R4 two outbound shipping modes, real ShipOut Sandbox UAT (2026-10-29)

Owner approved two independent modes (sandbox only):
1. Customer-provided shipping label: sysServiceId=6.
2. Warehouse-selected shipping service / channel label: sysServiceId=9.
   NOTE sysServiceId=10 means CLIENT SPECIFIES a preconfigured channel,
   which needs a real serviceGroupId or serviceGroupCode and is NOT the same
   as mode 9. Do not claim mode 10 accepted from these results.

Both submissions were sent from China Mac with the stored local sandbox JWT.
One unique order per mode, durable local one-shot attempt markers before POST.
No production API, no real customer address, no ERP, Woo or billing writes.

Mode 6: BYL-SANDBOX-API-ORDER-20261029-004
- One test SKU, 1 unit, test PDF marked clearly VOID / SANDBOX;
  labelSource=OWN_CHANNEL, embedded PDF base64, tracking=UATSHIPOUTVOID004.
- HTTP 200, response array [0].result=OK,
  provider orderId 2115713400097308673;
  signed queryListV2 returned status 3 Processing,
  outboundStatus 2, labelStatus 0, carrierId 1;
  outboundId 2115713405838123009; outboundNO OB261029-2;
  trackingNumber UATSHIPOUTVOID004, shippedTime null.
- GET /open-api/oms/outbound/parcel/downloadShippingLabel with this
  outboundId returned HTTP 200, PDF ~79914 bytes. The uploaded
  PDF is NOT a valid carrier/paid postage label; do not physically ship.
- PASS for API acceptance, order and outbound creation, tracking field
  and readable PDF; NOT PASS for actual carrier transport or shipped state.

Mode 9: BYL-SANDBOX-API-ORDER-20261029-005
- Warehouse-selected service; sysServiceId=9 AND carrierId=9 on POST.
- HTTP 200, response array [0].result=OK,
  provider orderId 2115713496931205122;
  signed queryListV2 returned status 3 Processing,
  outboundStatus 2, labelStatus 2;
  provider chose ACTUAL carrierId 2 (UPS) despite carrierId=9 for
  selection request. Provider generated a synthetic mock_2_ tracking.
  outboundId 2115713504353935361; outboundNO OB261029-3;
  shippedTime null.
- GET /open-api/oms/outbound/parcel/downloadShippingLabel returned
  HTTP 200, PDF ~27642 bytes.
- PASS for API acceptance, provider channel selection, label generation
  and tracking readback; NOT PASS for physical shipment.
- This demonstrates warehouse-selected channel is NOT guaranteed USPS.
  Don't hardcode USPS or Priority when warehouse selects it.

Stock GET after both: standardQuantity=5, availableQuantity=3,
outboundingQuantity=2, consistent with one unit reserved for each order.
Do not create duplicate orders or issue unrelated inventory operations.

IMPORTANT LIVE-SCHEMA CORRECTION:
- GET /open-api/oms/order/queryListV2 returns shipmentList.
- GET /open-api/oms/order/query returns orderShipments.
- Both endpoints verified with real orders 004/005. Older gateway read
  shipmentList for BOTH and silently lost tracking from order/query.
- Fixed orderDetail mapping to orderShipments; added/updated tests.

All privacy and safety gates remain: no direct Woo updates,
no Dede/ERP production mutation, no real shipping label used,
and no automatic status=shipped based only on the provider's
order acceptance, label generation or outboundStatus=2.

Remaining: verify both actual finished status/outboundStatus=4 and
shippedTime from vendor's test WMS. For the VOID label mode, only
synthetic WMS state simulation is acceptable, never real postage.
Mode 10 (operator-specified channel) remains separate and requires
a verified serviceGroupId/serviceGroupCode; not tested by modes 6/9.

## R4 specified shipping channel sysServiceId=10, LIVE sandbox UAT (2026-10-29)
- Owner explicitly requested mode 10 as a third independent sandbox case;
  its serviceGroupId is NOT to be invented or copied from a provider example.
- Located OMS frontend's genuine READ-ONLY warehouse channel-list call:
  GET /api/wms-business/oms/outbound/querySysService
  with warehouseId=1531580902677626882 via omstest.shipout.com.
  Sandbox bearer authenticated. HTTP 200 / result OK.
- Six selectable OMS shipping entries were returned for this warehouse.
  One actual named group: USPS GA, sysServiceId=1993981773496696833.
  OMS frontend source maps a long sysServiceId to serviceGroupId.
- NOTE the channel-list endpoint belongs to the OMS web application,
  NOT a listed public OpenAPI resource. Do not silently rely on it for a
  Production ERP auto-sync without a documented/stable contract from vendor.
- Submitted ONE unique synthetic API order using
  sysServiceId="10", serviceGroupId="1993981773496696833",
  requested carrierId=1, SKU BYL-SHIPOUT-UAT-20261028-001 qty=1.
  Local one-shot sentinel reserved before POST. No retry.
- Order number BYL-SANDBOX-API-ORDER-20261029-006.
  POST /open-api/oms/order/batchSubmitAsync -> HTTP 200, array result OK,
  provider orderId 2115725000397127681. OMS queryListV2 readback
  matched order number/ID and created outbound OB261029-4,
  outboundId 2115725010151890945.
- Signed POST to the documented READ-ONLY
  /open-api/oms/outbound/parcel/queryByOutboundIds:
  HTTP 200 result OK, selected shipmentInfo.sysServiceId=10 and
  shipmentInfo.serviceGroupId=1993981773496696833 (confirmed retained).
- The sandbox actually produced carrierId=2 (UPS), despite the OMS
  selected service group being NAMED "USPS GA" (normally carrierId=1).
  Provider mock tracking starts mock_2_. Thus the requested service group
  ID was retained but carrier/channel mapping is NOT ACCEPTED as correct;
  vendor should explain mock routing or inspect sandbox automation rules.
  Never present that UPS mock shipment as USPS GA service.
- Actual order status 3 (processing), outboundStatus 2, labelStatus 2,
  tracking generated (mock only), shippedTime null at latest readback.
- Signed GET /open-api/oms/outbound/parcel/downloadShippingLabel for
  outboundId 2115725010151890945 returned HTTP 200, PDF header+EOF,
  27,642 bytes. No real shipping/purchase and no data file committed.
- Stock after three test submissions: 2 units available, 2 outbound-
  reserved, 4 standard; the first two 004/005 have by then simulated
  completed (one unit each deducted). Do not misstate all three as
  reserved or all orders as physically shipped.
- At the same read-only check, orders 004 and 005 had OMS status=4,
  outboundStatus=4, and nonempty shippedTime.
  This signs off provider's simulated shipped state for the first two
  modes (still NOT physical carrier transport, because test labels/mock
  tracking are non-postage).
- Owner authorized no Production changes. No merge or real WMS writes;
  only sandbox order 006 was created for this explicit mode 10 test.
  Next accept mode 10 only after shippedTime status and carrier mapping
  questions resolved.

## R4 final closure and R5 mode 10 controlled integration (2026-10-29)
- ShipOut signed real sandbox re-query of order
  BYL-SANDBOX-API-ORDER-20261029-006 / outbound OB261029-4
  confirmed order status 4, outboundStatus 4, tracking and
  shippedTime "2026-10-29 09:40:37". No re-POST.
- Earlier sandbox orders OB261029-2 (mode 6 uploaded VOID PDF) and
  OB261029-3 (mode 9 warehouse-selected) also confirmed status 4,
  outboundStatus 4, tracking and shippedTime. R4 provider-side
  simulated outbound/status readback is now PASS for all 3 modes.
  This does not mean real mail/physical carrier acceptance.
- Owner explicitly de-scoped sandbox USPS GA -> UPS mock carrier
  discrepancy. Preserve raw *actual* carrierId (2 = UPS), never
  rewrite provider UPS to USPS GA for the sake of display.
- Updated ShipOutTrackingApplyService to map actual carrierId
  1 USPS / 2 UPS / 3 FedEx / 4 DHL, fail closed on unknown or
  synthetic mock_/UATSHIPOUTVOID... tracking and leave Vita
  WooCommerce sync pending. Updated ShipmentService so an
  explicitly changed carrier cannot inherit a different carrier's
  recommended service or carrier_service_id.
- Added configurable exact service-group allowlist for sandbox
  specified-channel sysServiceId=10:
  SHIPOUT_SANDBOX_ENABLED_MODES (default 9 only),
  SHIPOUT_SANDBOX_SERVICE_GROUP_ID (empty by default),
  SHIPOUT_SANDBOX_SERVICE_GROUP_LABEL (empty by default).
  The verified test-group 1993981773496696833 (USPS GA) is
  deliberately NOT hardcoded in Production config.
- Controlled Dede US-LA workbench now offers 9 vs 10 only when
  configured/allowed; choosing a different mode invalidates the
  preflight fingerprint. POST validates exact mode and group;
  no arbitrary browser serviceGroupId accepted.
  Dedicated route UAT verifies mode tampering rejects before POST.
- Mode 6 remains proven in a one-shot sandbox harness only;
  it still requires a safe per-order label-upload UI, signed,
  validated PDF storage/expiry, tracking validation and guardrails
  before a 3-mode ERP workbench can be accepted.
- All provider sandbox mutating calls in this scope already
  completed. No new provider orders sent by this commit.
- Latest ERP main Finance Facts Drawer and Tracking Sync V2
  merged into the isolated branch; no conflicting changes.

### Remaining R5-R8 release roadmap (engineer-hour estimates)
R5.1 - implement the missing Dede mode 6 own-label file upload:
        preview, MIME/size/Tracking validation, expiring one-use
        label token, server-side hash, sandbox-only transport:
        3-5 hours.
R5.2 - SKU Alias -> exact MorePlus provider SKU validation,
        provider stock/warehouse identity, no-stock clear
        explanation and safe manual override policy: 2-3 hours.
R6 - all 3 modes local isolated Dev browser/UAT with Dede role,
     approval/duplicate/422/timeout/partial/multi-item and
     XLSX manual fallback, plus mode 10 label: 2-3 hours.
R7 - provider tracking/actual carrier/ship time readback,
     ERP-only write, Vita Tracking Sync V2 Woo state plus
     rejected/mismatched/mock safeguards: 2-3 hours.
R8 - production readiness: new credential boundary, MySQL
     migrations & rollback, last CI, signed Owner PASS, one
     explicitly whitelisted real-production canary; 2-4 hours.
TOTAL remaining from this engineering baseline: 11-18 hours,
excluding external warehouse lag, review/approval waiting,
and any unrelated unified Logistics R8-P0 scope expansion.
No Production merge, credential activation, Woo or ERP live writes.
