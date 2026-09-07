# ERP-LOGISTICS-HISTORICAL-RESCUE-SHIPMENT-ATTEMPT-PROJECTION-M1-001

STATUS: IMPLEMENTED / VERIFICATION_PASS / PRODUCTION_PREVIEW_PENDING
BRANCH: feature/erp-logistics-historical-rescue-attempt-projection-m1-001
BASELINE: origin/main@2273ede4f18486042acbfa30dc6623779b186322
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK

## Objective
Project the 244 frozen rescue tracking legs from 121 applied historical rescue Orders into historical-only ShipmentAttempt snapshots without creating active Shipment state.

## Frozen Production facts
- R4 Orders: 121
- Tracking legs: 244 / unique tracking: 244
- Existing ShipmentAttempt tracking hits: 0
- Existing Shipment tracking hits: 0
- Legs per Order: 2x1, 116x2, 2x3, 1x4
- Factory 国内仓: 243 legs -> FACTORY exact
- Tingon外采仓: 1 leg -> unresolved / warehouse_id=null

## Contract
- attempt_number is deterministic leg_index 1..N per Order.
- shipment_id=null and shipment_status=historical_evidence.
- shipped_at/carrier/service remain null.
- warehouse exact only through frozen canonical mapping; unknown warehouse remains null.
- no Shipment creation, no Order lifecycle mutation, no Inventory mutation.

## Safety / drift controls
- Preview and Apply require logistics.operate.
- Apply gate defaults OFF.
- Apply locks R5 batch, R4 source rows, source evidence, and source Orders.
- R4 Order ERP identity and exact historical external reference are revalidated.
- tracking, evidence hash, source checksum, leg_index and leg_count are revalidated.
- tracking/attempt-number conflicts fail closed per leg.
- active Shipment on a rescue Order blocks projection.
- Apply is transactional and idempotent.

## Verification
- Focused R5: 8 tests / 77 assertions PASS.
- Historical Logistics + Fulfillment + Allocation + Inventory chain: 102 tests / 750 assertions PASS.
- MigrationIndexNameTest: 2 tests / 11 assertions PASS.
- PHP lint: PASS.
- git diff --check: PASS.
- Architecture branch: 3 failed / 5 passed / 129 assertions.
- Pure main A/B: same 3 failed / 5 passed / 129 assertions.
- No new Architecture failure class; baseline not changed in this WP.

## Production gate
Deploy Preview-first only. Apply gate remains OFF until exact Production Preview proves 244 Ready legs, 243 exact FACTORY, 1 unresolved warehouse, zero conflicts, stable manifest SHA, and zero business mutation.
