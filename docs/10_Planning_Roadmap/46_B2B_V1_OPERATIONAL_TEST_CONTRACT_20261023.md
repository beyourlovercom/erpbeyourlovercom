# Owner-existing operational contracts — test-only CI alignment

Date: 2026-10-23. Isolated test-only branch, stacked on #489. No application, routing, role, accounting, data, source Markdown or production writes.

Four inherited test cases had expectations older than their source-of-truth contracts:
1. Inventory dashboard has seven canonical warehouses in the migrated test DB; after the one confirmed US-NY import, exactly six remain unknown. The test now explicitly confirms seven total warehouses and six data gaps. No warehouse configuration altered.
2. KolSpecialist role preset already includes inventory.view, following Owner-approved product/inventory visibility for all roles. Dashboard test must assert visibility.inventory=true, while its operations/sync/finance/procurement restrictions remain asserted false.
3. Changelog markdown source has 2026-09-08 section Logistics / Carrier and 2026-09-07 section Accounting / Order Profit; original test expected the second-day section in the first-day HTML. Test now confirms both contents at their proper index and still checks read-only behavior and no editing routes.
4. The default schedule has bounded runtime.health and three operational backup maintenance cadences (daily/weekly/monthly). The previous test asserted exactly one total event. Updated test asserts the **exact list of four approved maintenance-only event names** and retains strict runtime every-five-minutes, quiet, overlap, on-one-server, noninteractive checks. No payment, orders, shipments, sync or inventory-auto-write scheduler events are permitted by this assertion.

Test evidence: four targeted Feature suites **21 tests / 235 assertions PASS**. Full isolated V1 RC before: 1,973 tests, 18,136 assertions, zero runtime errors, 23 failures. After: **1,973 tests, 18,147 assertions, zero errors, 19 failures** (six skipped). Full CI is STILL FAILING.

Owner-frozen logistics carrier and 4PX weight contract tests, manual fulfillment reservations, other Warehouse/Orders tests, frozen global Architecture issue #474 and hosted CI remain blockers. No broad test skips, command bypass, disabled permissions, real B2B Trade/Finance writes, main merge, S4/S6 Dev changes or production deployment.
