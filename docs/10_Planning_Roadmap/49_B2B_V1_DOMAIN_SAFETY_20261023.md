# B2B V1 CI release gate: product-sync UI authorization, evidence cost, shipment reopen audit

Date: 2026-10-23. Isolated release-candidate Draft branch stacked on #492; **not** merged, deployed or activated for real customer/shipment/Finance writes.

## 1. Product single-item Woo refresh: protect true dual permission
Existing POST /products/{id}/sync-woocommerce route requires BOTH products.operate AND sync.operate. Product detail had misleading canSync UI flag based ONLY on sync.operate, so clicking the visible button returned HTTP 403. ProductController now computes canSync from both permissions. Test asserts viewer 403; sync-only operator 403, canSync=false and canOperate=false; user with BOTH permissions can use action and sees canSync=true, canOperate=true. **No wider permission grants, route bypass or role changes.**

## 2. 4PX actual shipping weight still requires packaging cost evidence
Existing FulfillmentPlanCostEstimator first requires standard product packaging cost + currency for all segments, THEN for 4PX factory uses approved shipping reference weight even without product packaging dimensions. Two legacy tests omitted standard packaging cost and failed at the first safety gate. Synthetic test fixtures now supply RMB 24.00 standard packaging cost/CNY; for approved 0.5 kg, assert shipping component 84.5, packaging component 24 and total RMB 132.5. In missing-weight test packaging cost is available, so explicit missing_shipping_reference_weight reason is validated. **No fake weight, no price bypass, no production cost writes.**

## 3. Actual Shipment shipped-to-pending reopen must be audited once
Real ShipmentService::reopen called snapshotAndReset before capturing before-state and checking isDirty. snapshotAndReset itself saved Shipment as pending, so original shipped->pending transition was invisible to audit; duplicate repeat calls could not fix this. Capture original Shipment snapshot and hadActiveShipment bool **before** snapshotAndReset, then mark changed based on the real original state. Preserve all shipment-attempt snapshots, payment/fulfillment guards, manualSync reopen and repeat idempotency. MVP1 test now asserts exactly one shipment.reopened and one manual_sync.reopened event, with precise audit.before.shipment_status=shipped and audit.after.shipment_status=pending.

## Regression and gates
- Focused MVP1 + 4PX weight + Woo product inbound suites: **24 tests, 258 assertions PASS**.
- Prior stacked #492 full suite: 1,973 tests, 18,194 assertions, zero errors, 11 failures.
- After these changes: **1,973 tests, 18,227 assertions, zero errors, 7 failures**, six skipped. Full CI is **not PASS**. Remaining failures: 3 frozen Architecture inventories (#474), 2 owner-frozen Logistics carrier aliases/services (#486), 1 carrier catalog same-instance stale active cache, 1 wait-stock page double externalRef reads under payment evidence fresh-load.
- No production/shipment/order/Finance DB operations executed. Actual Owner authenticated browser/M14, Finance manual-settlement signoff, hosted GitHub Actions/CI, exact-head merge and production deployment remain required.
