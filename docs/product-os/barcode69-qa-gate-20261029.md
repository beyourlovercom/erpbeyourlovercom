# Barcode 69 Product OS QA gate — 2026-10-29

## Source
User-supplied SKU to barcode table from Tencent Docs; 59 low-risk candidates staged as docs/product-os/barcode69-safe-staging-20261029.csv. No Production data writes.

## Production read-only SKU comparison
From an existing authorized ERP session, Product OS /products/list?per_page=all showed 252 product records.
Exact SKU comparison: 55 of 59 found; unmatched:
- 2200VA617A01010
- RJ238-purple
- TX218A
- BV468A

No SKU substitutions or new products should be inferred. The Production database does not have the proposed barcode_69 migration until release; this comparison is SKU-only, **not** confirmation that product barcodes were imported.

## Regression
Isolated SQLite in-memory, PHP 8.3.33, PHPUnit 12.5.33: 5 tests, 14 assertions, 0 failures.
Vue TypeScript typecheck PASS; Vite build PASS on earlier changes. Route inventory includes:
- GET /products/barcode-69/import
- POST /products/barcode-69/import/preview
- POST /products/barcode-69/import/confirm
- PUT /products/{product}/barcode-69
- GET /logistics/china/orders/{order}/barcode-69

## Scope and limitations
Read-only Europe domestic 69-barcode preview requires a European destination and domestic warehouse shipment; each line is checked against the linked Product SKU. This is a **preview API**, not a modification of the frozen Speeda/Weship 24-column XLSX provider contract. Real carrier mapping / label channel requires separate contract verification.
No real Dev ERP browser deployment or live-order UAT was performed. No Production migration, bulk import, merge, or release. PR remains Draft.

## Next release gates
- Verify the 4 unmatched SKUs; clarify product variations and source anomalies (VT500A colors, DW01A/DW02A, VX609A/VX610A shared code).
- Validate authenticated Dev ERP browser usage of packaging editor, CSV preview/confirm, and EU domestic manifest endpoint.
- Re-check real SKU matching at time of import and inspect each status; never override existing values automatically.
- Owner must separately approve Production rollout and exact data import.
