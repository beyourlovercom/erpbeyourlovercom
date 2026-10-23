# Identity and Customer CI test contract repair — 2026-10-23

Scope: test-only, stacked after Draft #488. No actual role, user, customer, order, database, Finance, or production changes.

The ERP user creation CLI derives its role choices from the eight current RolePreset cases: logistics, sales_representative, kol_specialist, kol_manager, finance, operations, writer, administrator. Legacy Audit and Authorization feature tests still asserted removed four-role options, so they failed before reaching their actual audit and validation assertions. The same command contract is covered by the separate current CreateErpUserCommandTest. Update only the two old test choices to the eight current presets, selecting operations, preserving all negative failure and audit rollback checks.

Customer order projection uses erp_order_id as recentOrders.number; external_order_number is a separate field. The legacy Customer Master test inserted no erp_order_id but expected the external WEB-1001 under number. Give the test fixture its own synthetic canonical ERP number, assert that number and independently assert external WEB-1001 remains in the external field. All owner scope, order counts and currency assertion checks are preserved.

Evidence: four focused Audit, Authorization, CLI, and Customer suites **36 tests / 291 assertions PASS**. Full isolated candidate before: 1973 tests / 18122 assertions / 0 errors / 26 failures. Full candidate after: **1973 tests / 18136 assertions / 0 errors / 23 failures** (6 skipped). NOT full CI PASS.

Remaining hard HOLD: global Architecture frozen manifest #474, Logistics owner routing #486, other historical modules, hosted CI, authenticated PC/physical M14 Owner UAT, Finance policy, and exact-head main/deployment approval. V1 business and Finance write flags remain OFF by default. GitHub main and real ERP unchanged.
