# ERP-LOGISTICS-HISTORICAL-OWNER-UAT-CONTROL-CENTER-001

## Goal
Replace chat-only historical logistics approval with an ERP Owner UAT control center at `/logistics/historical-import`.

## Scope
- Show canonical historical totals: source rows, evidence, historical Orders, original Attempts, rescue Orders/legs, permanent Review, active Shipment contamination.
- Show R5 read-only candidate reconciliation before Preview.
- Allow an authorized logistics operator to freeze R5 Preview from the ERP page.
- Show searchable/paginated R5 rows with ERP Order links, tracking, attempt number, warehouse resolution, raw agent and decision.
- Allow explicit Owner Apply from the ERP page only when frozen SHA still matches and Review=0.
- Automatically create a Database backup before Apply.
- Keep the global R5 Apply gate OFF; the approved request enables it only in-process and restores the prior value.
- Never create an Active Shipment from this control center.

## Safety contract
- Preview and page reads do not create ShipmentAttempts.
- Apply payload must include exact frozen manifest SHA and exact confirmation `APPLY <ready_count>`.
- Apply re-runs R5 inspection before backup/write and fails closed on drift.
- Backup failure aborts Apply.
- R5 service retains its own transaction, evidence/order locks, tracking conflict checks, and idempotency.
- Permanent Review rows remain visible and non-applyable.

## Verification
- Owner UAT control center feature test: PASS.
- R5 focused + control center: 9 tests / 94 assertions PASS.
- Historical Logistics + Fulfillment + Allocation + Inventory: 103 tests / 768 assertions PASS.
- HistoricalImport Vue Vitest: 3/3 PASS.
- Production frontend build: PASS.
- MigrationIndexNameTest: 2/2 / 11 assertions PASS.
- PHP lint: PASS.
- `git diff --check`: PASS.
- ModuleArchitecture A/B: branch and pure main both 3 existing failures / 5 passed / 129 assertions; no new failure class.

## Production gate
Deploy UI + R5 additive schema/code first. R5 Apply remains untouched until the Owner reviews/finally approves from the ERP page.
