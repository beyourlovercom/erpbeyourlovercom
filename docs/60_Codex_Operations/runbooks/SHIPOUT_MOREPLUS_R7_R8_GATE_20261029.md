# ShipOut MorePlus R7/R8 Gate — 2026-10-29

## 0. Release boundary
- Draft PR #532, Owner approval required for any Production merge/write.
- Existing MorePlus ShipOut API transport is fixed to SANDBOX at https://opendev.shipout.com/api. Do not enable for real customer orders.
- In APP_ENV=production, sandbox read, dispatch, label staging, tracking apply are unconditionally rejected, even if env flags are on. Page API actions hidden; sandbox-stage cleanup scheduler excluded.
- A separately audited PRODUCTION ShipOut transport, endpoint, credentials and dedicated single-order allowlist do not yet exist. Thus actual Production Canary is BLOCKED, not merely waiting for an env switch.

## 1. Real ShipOut sandbox evidence
- Mode 6: synthetic VOID PDF label; outbound OB261029-2, provider order status 4/outboundStatus 4, shippedTime and test Tracking present.
- Mode 9: provider-chosen channel, OB261029-3; status 4/4, mock tracking, shippedTime.
- Mode 10: verified serviceGroupId 1993981773496696833 (USPS GA), OB261029-4; status 4/4, mock tracking, shippedTime 2026-10-29 09:40:37.
- Actual UPS mock carrier code 2 on mode 10 remains accurately recorded. Owner explicitly de-scoped sandbox carrier naming investigation. No real paid label/transport is claimed.
- No more provider POSTs or order creation in R7/R8. Real sandbox queries were signed/read-only.

## 2. R7 local complete Dede-to-Vita-to-Woo evidence
- TrackingV2LocalWooE2eTest: 1 real localhost Woo test, 27 assertions PASS.
- New ShipOutLocalWooVitaE2eTest: 1 real localhost Woo test, 24 assertions PASS. Repeated after merging latest main, fresh local Woo order 19, PASS.
- Provider response is a simulated ShipOut GET in the Laravel UAT; no live provider dispatch in this integrated test.
- Dede confirms provider Order 4 / Outbound 4, actual courier, Tracking and shippedTime; changes only isolated ERP. ManualSync stays pending and localhost Woo remains Processing without tracking.
- Dede Woo sync permission denied 403. Vita uses formal operations role, exact one-order manual Tracking Sync V2. Local Woo becomes Completed, tracking verified. Repeating the sync does not add extra outbox rows.
- Real localhost AfterShip plugin inspected tracking, USPS courier, two Woo line items, one package and customer template. AfterShip CLOUD and www NOT checked.
- Provider shippedTime is naive Y-m-d H:i:s: no timezone offset in official fields. New sandbox_shipped_time_timezone defaults EMPTY and blocks ERP apply until a vendor-confirmed IANA timezone is set. Fake local uses UTC; Asia/Shanghai test confirms 10:00 local -> 02:00 UTC. Do NOT assume real vendor UTC.

## 3. R8 preparation & compatibility
- Latest main merged, including unified warehouse dispatch R8-P0 M1, 3SM No_Shipping_Service / OMS status refresh, and formal Tracking V2 role access. Three conflicts resolved without losing either 3SM or MorePlus features.
- Main-merged tests: 189 PHPUnit, 1110 assertions PASS, 2 local opt-in tests skipped in normal CI. Vue MorePlus/3SM 26 PASS. Typecheck PASS.
- Isolated SQLite rollback of label-stage migration 2026_10_29_000002 confirmed table removed while dispatch ledger remained. Reapplied successfully.
- New main migration 2026_10_29_120000_create_warehouse_dispatch_states also applied to isolated SQLite.
- Production ERP login HTTPS read-only 200 and TLS verified. This is only availability, NOT deployment or production DB validation.
- Isolated Dev: READ/DISPATCH/APPLY flags returned OFF, allowlist empty, no live provider credentials. Manual XLSX fallback retained.

## 4. R8 Production release gates, all explicit
1. Owner PASS for merging PR #532 after GitHub CI green at exact HEAD.
2. Owner PASS for Production dark-code deployment, backup and migration review; never activate sandbox API in production.
3. Implement and audit a distinct production ShipOut transport, signed read/write, vendor-confirmed URL, independently protected live credentials and exact one-order/warehouse allowlist.
4. Verify live clientOrgId, warehouseId, Product/SKU Alias and available stock and provider shippedTime IANA timezone.
5. Owner separately selects exact one Production ERP order for outbound Canary and approves one POST; no automatic retry.
6. Dede real workbench order state -> provider status/tracking -> ERP-only confirmation; Vita separate manual Woo Sync V2 and AfterShip cloud readback; no rollout before acceptance.

### Rollback
Prior to any canary, all Production provider actions remain disabled, and earlier app release can be restored. Never drop dispatch ledger once a real POST has been attempted, even on a timeout. Provider state must be reconciled manually. Label stage data can only be dropped after confirming no active tokens.

### Remaining estimated engineering time (excluding external waits/approval)
- Live ShipOut adapter and protected credentials plus read-only preflight 3-5h.
- Dark Production deployment readiness and database verification 1-2h.
- Explicit one-order Production Canary, ERP/Woo/AfterShip verification 2-3h.
- Estimated 6-10h plus external wait. No Production action performed in this branch.
