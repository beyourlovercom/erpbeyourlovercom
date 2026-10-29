# Barcode69 R3/R4 localhost UAT — 2026-10-29

Scope: PR #549 only, localhost-bound isolated Laravel 13 application on US Mac mini. No deverp subdomain needed.

- Runtime: http://127.0.0.1:18769 (127.0.0.1 only), dedicated SQLite file under the isolated checkout. Production DB credentials were not copied. Migration 2026_10_29_190000_add_barcode_69_to_products completed.
- LocalUatSeeder generated synthetic accounts and records. Authenticated HTTP login succeeded (redirected to /dashboard).
- GET /products/1 and /products/1/packaging: 200, correct Inertia components Products/Show and Products/Packaging.
- GET /products/barcode-69/import: 200, Products/Barcode69Import.
- GET /logistics/china: 200, Logistics/Domestic/Index.
- Multipart CSV Preview: 200, synthetic SKU PKG-UAT-001 matched exactly and showed ready=1.
- Confirm: 200, updated=1 skipped=0. Packaging readback: barcode_69=6977291390537.
- Repeat Preview: unchanged=1 ready=0; Confirm: updated=0 skipped=1 (idempotent).
- Synthetic FR domestic shipment 007-B69-LOCAL-FR-001: GET /logistics/china/orders/8/barcode-69 returned 200 with FR, local CN warehouse and item PKG-UAT-001 quantity 2 barcode_69 6977291390537.
- Existing unit/feature regressions on branch: PHPUnit 11 tests / 141 assertions PASS; domestic Vue 8 tests PASS; typecheck and build PASS (previous turn).

**Boundaries:** This is localhost synthetic-data UAT, not production import or provider's real shipping-label UAT. Real production matching was read-only (55/59 exact SKU matches); 4 unmatched SKU records remain quarantined. Speeda/Weship 24-column template unchanged. Keep PR Draft, require separate approval for production merge/release and data import.

Local UAT scripts and database are intentionally excluded from Git. No credentials in this report.
