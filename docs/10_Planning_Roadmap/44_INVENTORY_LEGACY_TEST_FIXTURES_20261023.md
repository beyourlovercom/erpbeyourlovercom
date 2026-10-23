# Inventory read-only CI fixture reconciliation for B2B V1 release gate

Date: 2026-10-23. Test-only, no production business rules or inventory operations changed.

## Existing current authority
The existing warehouse_master configuration already has real active XLSX bill imports at US-LA (moreplus) and US-NY (speeda-us-ny). US-SM (us-3sm) is explicitly disabled and awaiting a carrier bill sample. The existing controller capability check must respond 404 for noncapable warehouses. Also, the carrier master v2 migration defines active USPS service USPS Ground Advantage, but no service named plain Ground.

## Minimal test-only changes
- InventoryMultiWarehouseTemplateP6Test: accept the already active US-NY Speeda provider on WarehouseShow, preserve US-LA MorePlus, and assert 404 on the actual disabled US-SM import routes. Preserve zero-import-batch assertions.
- InventoryWarehouseStorageAgingP9OperationalTest: in two FIFO shipment tests, use the current active USPS Ground Advantage instead of obsolete Ground. Keep FIFO stock, audit, rollback and failure checks intact.
- No runtime controller, service catalog, storage billing provider, production routing or Finance data modified; no actual shipments or bills processed.

## Evidence
- Inventory targeted suite: 11 tests / 184 assertions PASS on disposable SQLite.
- Full V1 release candidate before these updates (#487): 1973 tests / 18001 assertions / 2 errors / 28 failures.
- Full suite after test-only updates: 1973 tests / 18122 assertions / 0 errors / 26 failures, six skipped. Both residual errors removed, but FULL CI STILL FAILS 26 assertions including frozen Architecture #474 and Logistics #486.
- No blanket test skip, stubbed security, credential bypass or Production code change.

## Release HOLD
Consolidated B2B V1 #483 Draft, menu Owner PASS #484, Woo fixture #485, Logistics carrier fixture #487 remain unmerged. This Draft stacks on #487. No main merge, Dev UAT overwrite, actual Woo Orders or real Finance cash events, Production DB migration or payments. Finance Owner, authenticated role-browser/physical M14 Owner acceptance and hosted CI remain required.
