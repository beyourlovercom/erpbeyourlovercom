# US-zone synthetic address test fixture repair — 2026-10-23

This is test-fixture-only. No change to US postal zone mapping, WarehouseRecommendationService, FulfillmentPaymentGate, inventory reservations, warehouse precedence, actual Woo/ERP Orders, shipping services or Production.

The existing ManualFulfillmentIntegrationTest scenario created synthetic customer orders with Country US but omitted postal code and state. The current Owner-frozen US physical zone policy is fail-closed: no unambiguous five-digit ZIP => no east/west recommendation; AssistedFulfillmentService appropriately returned HTTP 409 to protect cross-region shipping. Legacy tests instead expected a recommended warehouse/reservation.

Update only shared test scenario synthetic address: province_state CA, postal_code 90001. Both resolve to US West using the configured ZIP/state consistency rules. The seeded US-LA is prioritized in that fixture (US-SM is not stocked). Preserve full validation, reservation inventory, 409 forbidden cross-warehouse test, idempotence and shipment audit assertions. No physical order created or changed.

Targeted suite: 13 tests / 167 assertions, **one remaining pre-existing performance assertion failure**, no recommendation/Reservation failure. Full isolated V1 suite before: 1,973 tests, 18,147 assertions, 0 errors, 19 failures. After: **1,973 tests, 18,180 assertions, 0 errors, 12 failures**, six skipped. Full CI is **NOT PASS**.

Remaining performance query contract: wait-stock UI currently triggers two batched order_external_references lookups. One comes from the page preloading its Orders and another from current payment-state authority refresh, which deliberately reloads settlement state instead of trusting stale models. Do not simply remove payment evidence refresh or weaken the one-query assertion without Logistics/Orders owner review, query provenance verification, privacy and security evidence. Remaining separate Issue #474 Architecture frozen inventory and #486 Logistics carrier policy still block release.

The fix is stacked after operational test contracts Draft #490, not directly to main. The consolidated B2B Trade V1 Draft #483, two private isolated Dev services, real Finance cash, Production DB and release switches are unchanged.
