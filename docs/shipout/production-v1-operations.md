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
