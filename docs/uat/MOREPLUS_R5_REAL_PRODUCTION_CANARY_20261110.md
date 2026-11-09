# R5 — MorePlus Real Production Single-Order Canary

**Execution:** November 10, 2026 Beijing time / November 9, 2026 Los Angeles time.
**Approval:** Owner explicitly authorized one real Production Canary verification for MorePlus OMS.

## Exact scope
- Use **existing Production V1 single-order dispatch service**, not PR #656 batch code.
- Real WooCommerce-origin ERP order: **007-1106-c42141** (ERP internal ID 7903).
- One ShipOut Production order reference: **C42141-007**.
- Warehouse provider ID: **1265864414750314497**.
- SKU: **MF414A**, quantity **1**.
- Ship date requested to OMS: **2026-11-10 (Los Angeles)**.
- Shipping mode: **9, ShipOut auto-channel**. The order's ERP service field is USPS Priority Mail but the auto-channel does **not** guarantee that exact service. Must verify final service separately.

## Gate evidence
- Prior to dispatch: ERP order pending_shipment; shipment pending; Dede's reviewed warehouse and shipment warehouse match; USPS selected; tracking absent; no prior ShipOut attempt record.
- Production V1 read/dispatch and owner approval gates ON (pre-existing); batch-specific feature flags not shipped.
- Approved operator from existing exact allowlist; existing gate independently returned FINAL_GATE_PASS.
- Live signed preflight verified warehouse, SKU identity, availability, and no duplicate OMS order at that time.

## Real Production one-shot submit
- Existing guarded Production V1 service ShipOutProductionCanaryDispatchService::dispatchApprovedOrder used.
- Exactly one real POST attempt performed. No automatic retry.
- Provider async response: acknowledged, result code ASYNC_ACCEPTED_QUERY_REQUIRED.
- Returned real provider ID: **2119935047966355458**.
- Durable Production shipout_dispatch_attempts: 1 row, id 16, status acknowledged, order number C42141-007, provider id same as above. Created at 2026-11-09 23:27:56 (DB timestamp).

## Independent signed OMS readback
- Production ShipOutProductionCanaryReconcileService::inspect(7903).
- Returned:
  - order_no: C42141-007;
  - provider_order_id: 2119935047966355458;
  - provider_status: 10;
  - reconciliation: ORDER_VISIBLE_MANUAL_REVIEW_ONLY;
  - shipped: false;
  - tracking_apply: false.
- Same ID as provider POST acknowledgement: **PASS**.

## ERP state verified after readback
- Order pending_shipment.
- Shipment pending, tracking_number = null, shipped_at = null.
- No Woo, AfterShip or tracking write performed by this Canary.

## Explicit non-goals / release gate remains closed
- **Not a batch-production test**: PR #656 remains separate and must not be merged/deployed without an additional production batch release authorization.
- No mass dispatch, no production migration or config edits, no Woo synchronization, no synthetic shipment confirmation.
- Real order was accepted by OMS but **not yet physically shipped**, and the mode-9 actual carrier/service requires separate verification.
- R5 validates existing V1 Production single-order provider POST -> ledger -> provider GET readback and duplicate protection. It does not validate the proposed PR #656 queued bulk path in Production.
