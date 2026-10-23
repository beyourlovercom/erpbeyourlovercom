# Logistics carrier tests — actual SQLite fixture setup (2026-10-23)

## Scope
**TEST-ONLY** on a stacked isolated Draft. Do not modify existing warehouse routing policy, carrier registry, shipping services, production data, or official frozen menu. This change is proposed under GitHub Issue #486.

Two unit classes previously issued real CarrierServiceCatalog model queries against logistics_carriers even though their in-memory SQLite database had not been migrated. Add Laravel RefreshDatabase to the two test cases; migrations and seeded carrier catalog are performed inside each ephemeral in-memory test DB.

Affected tests: tests/Unit/FulfillmentPlanPreviewServiceTest.php and tests/Unit/WarehouseRoutingPolicyTest.php.

## Verified outcomes
- The targeted suite now executes **16 tests, 258 assertions**, with zero missing-table errors and **two genuine historical policy assertion failures**.
- Full matched isolated V1 test suite before these test-only changes (Woo fixture Draft #485): **1,973 tests, 17,886 assertions, 11 errors, 26 failures**, six skipped.
- Full suite with these changes: **1,973 tests, 18,001 assertions, 2 errors, 28 failures**, six skipped.
- Nine historical SQLite table errors are resolved, but **two new visible assertion failures are exposed**, so this PR alone does NOT make CI green.
- Issue #486 contains the exact separate Logistics Owner decision requests: configured Mexico Weship service is null but seeded catalog supplies Standard, and frozen FedEx display alias says 联邦 while seeded catalog says 联邦快递. **No test expectations were weakened.**
- The two remaining errors outside these unit classes are Inventory route/warehouse UI and ShipmentService service catalog integration and require separate analysis.

## Gates
Architecture Issue #474 and global full-suite, hosted CI, real Owner/M14/browser and Finance signoff remain HOLD. This test-only draft is not a production shipping/routing authorization and is stacked upon Woo fixture Draft #485. B2B V1 main-targeted #483 is still Draft/unmerged and its two write flags remain OFF by default.
