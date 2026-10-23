# Warehouse Bill Reconciliation on-demand read-only test alignment

Date: 2026-10-23. Stacked isolated test-only cleanup; no actual bills, finance events, settlement, or Production changes.

The existing WarehouseBillReconciliationService::history returns applied/imported batches as records with reconciliation=null and reconciliation_mode=on_demand. Dashboard summary totals report real imported batches and rows but matched_shipments/matched_orders remain unknown (null) until reconciliation is calculated on demand. The Warehouse Controller stores an explicitly requested preview in session under billReconciliationPreview, not in immutable batch history.

Legacy InventoryWarehouseBillReconciliationV1Test incorrectly asserted that a manager preview immediately populated the history reconciliation.status=ready and dashboard matched shipment/order counts. That implied a computed or finalized reconciliation where none exists. The corrected test retains manager permission for preview, viewer 403, batch id, supported=true, one imported batch and three imported rows. It now asserts historical reconciliation is null and on-demand, dashboard matched counts are null (unknown, not zero or settled), and session preview contains the correct batch id.

No application service, amount, finance write gate or production contract was changed. Focused test suite: **5 tests / 104 assertions PASS**. Full prior candidate: 1,973 tests / 18,180 assertions / 0 errors / 12 failures. After: **1,973 tests / 18,194 assertions / 0 errors / 11 failures** (6 skipped). Full CI remains NOT PASS; global Architecture and Logistics/Payment/Weight/Order failures still require owner review.

Draft only, stacked after #491. Consolidated main-targeted B2B V1 #483 remains unmerged. Two independent private Dev servers unchanged, Finance/Trade production write flags default OFF.
