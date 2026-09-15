# ERP-HISTORICAL-FULFILLMENT-RECONCILIATION-HFR3-001 FINAL

## Scope
Customer Shipment Backfill V2 guarded mutation boundary for HFR normalized candidates. No Production apply performed.

## Implementation
- `HistoricalFulfillmentShipmentBackfillService`
- `logistics:hfr-backfill-customer-shipments`
- HFR3 feature tests
- command registration only in Logistics provider

## Safety contract
HFR3 re-runs the HFR-1 classifier and never trusts caller-supplied ready labels. Class A candidates are then rechecked against live DB state; apply repeats the checks under transaction + row locks.

Only canonical Shipment creation plus audit is permitted. Orders, ShipmentAttempts, Woo, Inventory, Accounting/Finance/P6, schema, UI, scheduler and changelog are outside scope.

## Apply gates
Production apply requires:
- process-local `ERP_LOGISTICS_HFR3_PRODUCTION_APPLY_ENABLED=true`;
- exact source SHA-256;
- exact plan SHA-256;
- exact ready/review/skip counts.

`ready_count = 0` is a hard `blocked_no_ready_candidates` gate and cannot enter the write transaction.

## Verification
- HFR3 + HFR1 + HFR2 focused suite: 24 tests / 106 assertions PASS before final extra gate test.
- HFR3 + legacy Customer Backfill V1 + Historical Import regression: 20 tests / 162 assertions, zero failures after local Vite build.
- legacy warnings are fixture/file loading warnings already present in historical suites.
- Pint PASS.
- `git diff --check` PASS.
- no Production data write during implementation or verification.
