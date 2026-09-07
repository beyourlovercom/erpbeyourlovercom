# ERP-INVENTORY-SMGJ-WAREHOUSE-READINESS-001

## Goal
Add a warehouse fulfillment-readiness date gate so SMGJ can exist in Warehouse Master while stock is still inbound.

## Owner facts
- Warehouse: SMGJ / 世迈国际
- Address: 2734 Thompson Creek Rd, Pomona, CA 91767
- Country: US
- Timezone: America/Los_Angeles
- Expected first fulfillment date: 2026-09-14
- SMGJ inventory is still being prepared and must not auto-allocate before readiness is confirmed.

## Contract
- Future `fulfillment_available_on` => preparing / 备货中.
- Date reached without confirmed Full Snapshot => awaiting_inventory / 待库存确认.
- Date reached with confirmed Full Snapshot => ready / 可履约.
- `can_auto_allocate` requires both date readiness and Full Snapshot authority.
- No automatic service-region assignment is introduced by this WP.

## Verification
- Inventory Foundation + Automatic Allocation: 25 tests / 232 assertions PASS.
- Vue typecheck PASS.
- Production build PASS.
- git diff --check PASS.

## Production Gate
Production warehouse creation, readiness date write, service-region assignment, and first Full Snapshot import are separately controlled actions.