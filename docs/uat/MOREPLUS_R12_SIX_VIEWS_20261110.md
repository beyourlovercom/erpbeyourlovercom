# R12 Overseas fulfillment: six queues and signed OMS evidence

## Immutable safety scope
- Source main 67d4f283, preserving R8 one-shot ledger, R9 content-based duplicate risk, R10 one-order Canary and R11 selected-or-all preview.
- The Production MorePlus batch flags MUST remain OFF. No changes here authorize a ShipOut POST, auto resubmission, Woo update, or shipment/tracking mutation.
- C42199 / ERP 7960 and C42149 / ERP 7913: confirmed provider duplicate status 6; each original immutable POST remains one. C42141 / ERP 7903: EzeeShip cancellation, Dede manual path only after existing OMS review. C42203 / ERP 7963: OMS status 3 visible, physical shipment not established. Do not resubmit any of these orders.

## R12-A immutable ACK plus sanitized signed OMS observations
- New append-only shipout_oms_readbacks table: ERP order_id, original bound provider ID, numeric provider status, safe state, safe reason code and checked_at. No recipient data, raw provider responses, address, bearer, customer notes or Tracking.
- ShipOutProductionOmsReadbackService uses the existing signed GET-only reconcile service. It validates the original immutable attempt and matching provider ID. Batch readback and user-initiated status GET both append sanitized evidence without altering the original ACK.
- One visible OMS readback is provisional and remains 下发中; a second independent signed GET at least 120 seconds later on the same uncancelled OMS ID becomes OMS-confirmed, shown 已下发 but NOT physically shipped or website synchronized.
- Provider statuses 6 and 11 become sticky cancelled and shown 下发异常. Unavailable, ambiguous or mismatched results cannot count as success; human review is required. No submitted order ever becomes automatically eligible for a second POST.
- Browser pagination, counts, and list projections use local audit records only: no per-row external HTTP requests.

## R12-B six nonoverlapping operational tabs
- Canonical tabs: review 待审核, pending 待发货, issued 已下发, shipped 已发货, exceptions 异常订单, all 全部订单.
- Old pending_dispatch and dispatched URLs map to pending and issued respectively for bookmarks.
- MorePlus pending: never-submitted eligible rows and ACK awaiting OMS confirmation; attempted rows are not selectable for a second POST. Latest verified OMS-confirmed orders enter issued; cancelled or uncertain status enter exceptions.
- 3SM status 0 draft stays pending, 2/3 verified OMS processing/outbound enter issued, and 4/5 cancellation or exception enter exceptions; manual OMS export is not offered on the issued tab.
- Red row-level 下发异常 reason in pending-related exceptions with real Tracking plus outbound-reference input in issued and exception views only when Dede has verified genuine physical shipment. Confirmed provider duplicate orders remain blocked.
- Original real-shipment marking and the existing 30-minute Woo Tracking Sync OS remain unchanged; ACK and OMS confirmation never mark shipped.

## R12-C GET-only scheduled reconciliation
- New independent env switch SHIPOUT_OMS_RECONCILIATION_ENABLED defaults OFF.
- shipout:reconcile-oms without --apply previews only. --apply is guarded to Production with signed read credentials and the independent R12 switch. It performs up to 20 signed GETs per run (hard cap 50), writes only sanitized local OMS status, every 30 minutes with overlap protection, and never dispatches OMS orders or syncs Woo.
- Release migration adds a table; it is independent of any ShipOut batch enablement. Do not turn on the batch POST flags during migration, staging, readback, or deployment.
- The P0 duplicate-discrepancy Issue 658 remains OPEN until MorePlus provides the matched historic OMS ID, exact de-duplication rule and reliable pre-submit visibility.

## Dev evidence
- MorePlus backend tests 562 assertions, overseas workbench 832 assertions, Vue 55/55, Vite production build and 14-file Pint all PASS.
- Covered signed-first / second-read transition, cancellation after OMS confirmed, cancellation stickiness, unknown provider status, provider ID mismatch, immutable attempt count, 3SM draft-processing-cancel split, and disabled Dev scheduled job.
- An unrelated pre-existing XlwmsSingleOrderDispatchTest case expects unauthenticated POST redirect while returning 401 on BOTH original main and R12; not changed here.

## Pre-merge defense-in-depth
- The single-order manual shipment backend (not just row-button visibility) checks latest sanitized OMS readback. A direct single-order API dispatch later classified PROVIDER_DUPLICATE_ORDER is blocked with HTTP 409 even when no batch item exists. Regression verifies no Tracking/shipment update, no provider HTTP, and immutable attempt remains one.
- Scheduled signed GET query removes redundant DISTINCT because the immutable order_id is already unique; MySQL 8 strict rejects SELECT DISTINCT order_id ORDER BY a.id. Ordering remains bounded/chronological.

## Numeric status-code caution
- The ShipOut public asynchronous documentation separates initial ACK from a later success notification. A visible OMS entry cannot demonstrate physical dispatch.
- This implementation currently recognizes numeric OMS code 3 for a provisional two-read visibility progression only; draft/early numeric codes 0/1/2 remain pending and unknown numeric codes remain unverified. No such state can by itself mark ERP shipped or send Woo Tracking.
- Confirm the precise semantics and accepted-state mapping with MorePlus before changing R13/R14 automatic-POST policy.
