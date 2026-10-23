# B2B V1 release baseline: isolated Woo numeric fixture repair

Date: 2026-10-23. Scope: **test-only**, no Orders code or production changes.

## Defect attribution
Existing `OrderIdentityService::wooOrderNumber` intentionally requires a numeric Woo business order number (leading # accepted) or numeric fallback reference, otherwise fails closed. Four existing `ProductExternalReferencesTest` cases used artificial values `WOO-1001`, `WOO-1002`, `WOO-CROSS-CONNECTION`, `WOO-1003`, causing 4 unrelated product/external-reference tests to error before exercising their assertions.

## Minimal repair
Only replace these four invalid **test fixture values** with distinct numeric strings `1001`, `1002`, `1003`, `1004`. Do not alter normalized inbound provider references, connection-scoped product IDs, order source contracts, asserted product/variation mappings, or the strict Woo identity validation. No production source code or merchant Woo order was modified.

## Evidence
- Focused `tests/Feature/Products/ProductExternalReferencesTest.php`: **21 tests, 64 assertions PASS**.
- Full isolated RC suite before this repair (Owner-approved menu V2.8 branch): **1,972 tests, 17,875 assertions, 15 errors, 26 failures**.
- Full isolated RC suite with this repair: **1,972 tests, 17,884 assertions, 11 errors, 26 failures**, 6 skipped.
- Four deterministic original test errors are now resolved without creating new error/failure classes. The full suite **still fails**, and this test fixture fix is not a general CI waiver.
- Remaining known test blockers: Logistics carrier DB setup (separate owner frozen naming/service semantics), Inventory/Warehouse lifecycle/API discrepancies, frozen global ModuleArchitecture manifests #474, and other inherited module failures. Owner menu V2.8 itself passes.

## Release boundary
This docs/test-only repair is independently stacked on Owner menu V2.8 PR #484. It is not directly merged to main nor automatically deployed. Consolidated B2B V1 release candidate PR #483 remains Draft until integrated review, hosted CI and production owner gates. Both independent S4/S6 Dev servers and all real customers/orders/Finance cash remain untouched. B2B Trade/Finance write flags default OFF.
