# ERP-HISTORICAL-FULFILLMENT-RECONCILIATION-HFR6-001 — FINAL

## Status
CLOSED / OWNER UAT PASS — 2026-09-16.

HFR-6 implementation was delivered in PR #336 (`HFR-6: recover exact historical carrier/service evidence`) and merged as `9caab2a7290d002efcfc6a041143933b0ab00d01`. The implementation preserved the HFR-3 Shipment-only write boundary and required a fresh post-merge Production preflight before any write.

## Authorized Production Data Write Gate
Owner authorized the exact HFR-6 Production gate and authorized subsequent HFR-6 gates through Owner UAT:

- source checksum: `4778ff1e33ba30d80873f3226dd9388d8c0aa143654bc41ca715076b826a4e91`
- source scan SHA: `281bf06d3aca3ad251502db4b9b3e7c4fe554a1471dbc9d76c80fce14b394568`
- plan SHA: `61a23a916ff472e20c23493b0e6a402ea54ac5fef8c84cd88b210ba7c26600d3`
- preflight: READY 530 / REVIEW 3,627 / SKIP 1,197
- READY manifest SHA: `633859603d7aa052347c520a69dd477abc9175ec7af0144bd516bac840d2d200`

## Production apply result
The exact-gated HFR-3 backfill contract was applied only to the authorized 530 READY rows.

- Shipments created: 530
- Shipments total: 1,216 -> 1,746
- immutable HFR apply audits: 1,171 -> 1,701
- target ShipmentAttempt rows created: 0
- target Woo sync outbox rows created: 0
- target Order business-state hash unchanged: yes
- pre/post Order business-state hash: `781d9c007d7c50661a68898a4d1e8011b52b9220520c856a313cbd70531ea208`
- unique target Orders: 530
- unique target tracking numbers: 530

Warehouse distribution:
- US-LA / warehouse id 1: 262
- US-NY / warehouse id 2: 268

Historical service distribution:
- `USPS First Class Mail`: 5
- `USPS Ground Advantage`: 256
- `USPS Priority Mail`: 2
- `USPS-NJ(USPS-NJ)`: 266
- `USPS-NJ-01(LP00006053)`: 1

All applied rows use carrier `USPS`, shipped Shipment status, exact historical warehouse/carrier selection sources, and nonblank tracking/service values.

## Independent post-apply audit
A second Production DB audit, independent of the apply process result, passed:

- target rows: 530
- canonical Shipments found: 530
- unique Orders: 530
- unique tracking numbers: 530
- bad rows: 0
- ShipmentAttempts: 0
- matching immutable apply audits: 530
- Order state hash exactly matched the pre-apply hash
- warehouse distribution exactly matched 262 US-LA / 268 US-NY
- service distribution exactly matched the authorized historical evidence set

## Idempotency proof
A post-apply preview returned:

- READY: 0
- REVIEW: 3,627
- SKIP: 1,727
- already applied: 530

The 530 rows must not be applied again.

## Owner UAT
Authenticated Production Owner UAT used a real HFR-6 row:

- ERP order: `007-0126-c33157`
- Order id: 3488
- tracking: `9214490358940004754936`
- warehouse: US-NY / 美东仓
- carrier: `USPS`
- historical service: `USPS-NJ(USPS-NJ)`

The Production order detail rendered the recovered canonical fulfillment facts and the Owner replied `pass` on 2026-09-16.

## Closure boundary
HFR-6 is CLOSED / UAT PASS.

The remaining 3,627 REVIEW candidates stay untouched. They are not silently promoted or backfilled. Any later evidence-recovery package must regenerate a fresh Production preflight and use a separate exact Production Data Write Gate.

No Production deploy is part of this closure. No additional Production data write is part of this closure. No Woo sync is queued. No changelog entry is created under the manual-only changelog policy.
