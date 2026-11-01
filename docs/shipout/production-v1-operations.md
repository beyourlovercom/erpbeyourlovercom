# ShipOut Production V1 — US-LA / MorePlus ERP standard workflow

Related: GitHub Issue #589. Supersedes the engineering loop in #584.
**Source of truth:** current Production runtime and GitHub merged source.

## Release scope
- Standard Dede-approved US-LA orders, US addresses, exact verified physical SKU
  and real ShipOut AVAILABLE stock; first rollout uses warehouse-selected Mode 9.
- Reuses R10 signed Production OpenAPI, immutable once-per-order dispatch ledger,
  preview SHA-256, read-only OMS reconciliation, and existing ERP review workflow.
- Sandbox and 3SM logistics routes remain separate. No automatic job, bulk POST
  or retry, no automatic Woo/AfterShip writes, and ACK is **not** confirmation of shipment.

## Business flow
Vita allocation -> Dede review -> official US-LA single-order preview ->
authorized logistics operator confirms one submission -> ShipOut OMS readback ->
warehouse actually ships and returns authentic Tracking -> controlled ERP
confirmation -> Vita syncs website independently.

The API provides **single-order manual send**, not automatically scheduled dispatch.
No new SKU alias substitution, no cross-warehouse routing, no mode 6 or mode 10
in this first Production release.

## Independent default-OFF gates
- Read: SHIPOUT_PRODUCTION_READ_ENABLED **and**
  SHIPOUT_PRODUCTION_V1_READ_ENABLED.
- Send: both READ gates above, plus SHIPOUT_PRODUCTION_DISPATCH_ENABLED,
  SHIPOUT_PRODUCTION_V1_DISPATCH_ENABLED, and
  SHIPOUT_PRODUCTION_V1_OWNER_APPROVED.
- Production V1 exact operator allowlist: SHIPOUT_PRODUCTION_V1_OPERATOR_USER_IDS.
  Supply three distinct approved ERP user IDs (Andy=1, Vita=18, Dede=19).
  Missing or malformed lists deny all sends. Each operator must remain active
  and retain logistics.view; Vita does not receive global logistics.operate.
  The prior single-user SHIPOUT_PRODUCTION_V1_OPERATOR_USER_ID setting remains
  legacy/inert, preserving rollback compatibility.
- The V1 send route alone uses RequireShipOutProductionV1Operator; all other
  overseas warehouse actions retain the existing logistics permissions.
- First supported ERP order identity: SHIPOUT_PRODUCTION_V1_MIN_ORDER_ID.
  Default 0 denies V1 preflight and send. Historical orders stay on manual flow.
- Every operator POST is reauthorized inside both controller and signed V1 gate,
  in addition to existing logistics.operate permissions. No automatic refresh/cron.
- SHIPOUT_PRODUCTION_TRACKING_APPLY_ENABLED: stays **OFF**.
- Original R10 SHIPOUT_PRODUCTION_CANARY_* flags stay **OFF**.
- Original SHIPOUT_PRODUCTION_OPERATOR_CLI_ENABLED stays **OFF**.
- Credentials remain in Production secret config; **never** expose their values.
- Release of new **code** is separate from enabling real Provider writes;
  previously blocked actual ChatGPT tool submissions must not be rerouted
  through the new UI to evade an active safety restriction.

## Controlled rollout
1. Isolated fake-HTTP regression (including legacy Sandbox) + Vue/TypeScript/build.
2. Exact-HEAD GitHub CI and approved code-only Production release, with immutable
   rollback pointer and health/readback checks.
3. Optional separately approved READ-only activation: verify UI preview and
   signed provider GET without reserving any attempt.
4. Only when a permitted business execution path exists and expressly approved,
   activate Production dispatch gates for the authorized operation; do not
   activate through an API/CLI workaround if execution is platform-blocked.
5. After first real acceptance, compare provider ID, OMS order reference,
   durable attempt count and status; an uncertain timeout is **manual review
   only**, never a retry.
6. Preserve manual tracking return pathway until reliable Shipped/Tracking
   evidence supports separate production status-apply approval.

## Scope exclusions
- Historical orders and canceled R10 Canary candidates.
- Automatic order push and auto-reship.
- Carrier or service-group inference from USPS labels.
- Production Tracking writes on async ACK.
- Customer PII, signed credentials or payload logs.


## OMS-created list projection (2026-11-01 status bucket fix)

MorePlus Production V1 reads only the immutable \`shipout_dispatch_attempts\`
ledger to project the **warehouse dispatch stage**; it does not use the
Shipment shipped-state or live provider calls when rendering the list.

- Signed Production \`acknowledged\` attempt with a nonempty real provider
  order ID and exact US-LA provider warehouse ID: show \`OMS 已建单 · 待实际出库\`
  under \`OMS 已建单\` rather than \`待发货\`.
- Not yet attempted: stay in \`待发货\` and, where applicable, \`待下发\`.
- Attempt with uncertain/failed acknowledgment: move to \`异常订单\`;
  explicitly no automatic retry, no new provider POST, manual OMS reconciliation.
- Acknowledgment alone is not warehouse departure: \`shipments.shipment_status\`,
  \`orders.order_status\`, shipped time, Tracking, Woo/AfterShip remain untouched.
  Actual shipment still requires trustworthy warehouse outbound evidence.
- MorePlus state is separate from US-3SM and every other warehouse. No live
  API requests occur as a side effect of changing tabs or reading counts.
- Browser navigates to \`OMS 已建单\` after a successful user-initiated submit
  response containing a provider ID, without sending again. A refreshed page
  reads the durable ledger, so state survives a new login or browser session.

The first real acknowledged C41941-007 / ERP 7791 order has a provider OMS ID
\`2116863614032625665\` but no Tracking or shipped-at evidence; this change
corrects only its workbench classification.

### R11 Production OMS cancellation readback

A successful \`acknowledged\` provider POST and real OMS order ID establish
*order creation only*. Follow-up signed read-only status may reveal cancelled
or aborted OMS order status codes \`6\` or \`11\`. R11 must show a prominent
cancellation/abort alert after an operator clicks **查询正式 OMS**, preventing
any mistaken assumption of imminent shipping. Do not infer the cancellation
reason from the status number; the provider detail cancellation reason must be
verified separately and must not be logged or exposed raw if it may contain PII.

This readback never changes the immutable one-shot ledger, Shipment, Order,
Tracking or Woo/AfterShip. Existing \`OMS 已建单\` tab is based on the durable
original acceptance record; it should not be interpreted as current warehouse
processing. A separate approved design is required for durable supplier status
snapshots and moving cancelled orders into the Exception tab without turning
read requests into writes.

First real order C41941-007 / OMS 2116863614032625665: signed production GET
returned status \`6\` (cancelled/aborted); Shipment is still pending with no
Tracking. Hold R12/R13; do not resend.
