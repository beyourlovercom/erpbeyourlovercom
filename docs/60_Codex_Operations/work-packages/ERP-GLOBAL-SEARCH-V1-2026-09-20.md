# ERP Global Search V1 — 2026-09-20

## Status

CLOSED / PRODUCTION ACTIVE / OWNER UAT PASS

## Scope

Global Search V1 is a read-only navigation capability in the ERP shell.

V1 providers:
- Product
- Order
- Inventory context

Deferred providers:
- Shipment as an independent result group
- Customer
- KOL
- Purchase Order
- Supplier
- Project
- Tooling

## Contract

Global Search is navigation only. It does not become business truth and does not persist search state as canonical data.

Endpoint:
- GET /search/global?q=...

UX:
- persistent Header search control
- Command/Ctrl + K keyboard shortcut
- 180ms debounce
- minimum query length 2
- grouped results
- exact identifiers rank ahead of broad text matches
- keyboard Up / Down / Enter
- Escape closes overlay
- mobile full-screen search overlay
- each provider returns at most 5 results in the overlay
- View all deep-links to the existing scoped module search

Authorization:
- Product results require products.view
- Order results require orders.view
- Inventory results require inventory.view
- users never receive result groups they are not authorized to view

## Product search

Searchable identifiers:
- canonical SKU
- Product name
- GTIN
- WooCommerce Product ID for numeric input
- SKU aliases

Result deep-link:
- /products/{id}

View all:
- /products/list?search=...

The Product scoped list search was aligned so GTIN, WooCommerce Product ID and SKU aliases remain searchable after View all.

## Order search

Searchable identifiers:
- dbid:{id} for explicit internal primary-key lookup
- ERP order identity
- internal order number
- external order number
- external provider references
- Shipment tracking number

Numeric input preserves exact order/reference behavior while also allowing an exact numeric tracking number, because common USPS tracking numbers are numeric.

Result deep-link:
- /orders/{id}

This intentionally uses the existing showAny route so fulfillment-derived A/B/C child Orders navigate back through canonical parent-order semantics.

View all:
- /orders?search=...

The scoped Order search was aligned to include external provider references and tracking numbers.

## Inventory search

Inventory is not introduced as a second canonical business entity page.

Results expose:
- Product SKU
- Warehouse context
- available quantity
- on-hand quantity
- reserved quantity

Result deep-link:
- existing Product Inventory filtered view with Product SKU + canonical Warehouse slug

View all:
- /inventory/products?search=...

The scoped Product Inventory search was aligned to include GTIN and SKU aliases.

## No schema change in V1

A draft lookup-index migration was deliberately removed before merge.

Reason:
- current dataset size does not require a schema gate for the first usable version;
- several scoped searches intentionally support contains matching, which ordinary B-tree indexes do not fully accelerate;
- Production rollout should remain code-only and read-only;
- Search Index Hardening can be introduced later from observed query evidence.

## Validation

Backend focused:
- GlobalSearchV1Test: 4 tests / 25 assertions PASS, existing environment warnings only

Frontend focused:
- GlobalSearchOverlayTest + TopBarTest + AuthenticatedLayoutTest: 25 / 25 PASS

Scoped regressions:
- OrderIdentitySearchContractTest + InventoryFoundationM1Test: 21 tests / 217 assertions PASS, existing environment warnings only
- SkuAliasTest + SkuInventoryIntegrationTest: 28 tests / 54 assertions PASS, existing environment warnings only

Frontend baseline:
- npm run typecheck: PASS / 0 errors
- npm run build: PASS / 939 modules
- git diff --check: PASS

## Safety

- no migration
- no Production business-data write
- no search index table
- no external search engine
- no change to canonical entity ownership
- no ERP Changelog update

## Production activation

PR #395 merged as 7460d816cdd550dacab300a2e4bffc4e5284b1db.

Production release:
- global-search-v1-pr395-20260920-7460d816

Production-derived staging and post-switch read-only probes:
- VA617A Product + Inventory groups: PASS
- ERP Order 007-0909-c40405: PASS
- Tracking lookup: PASS
- gift Product lookup: PASS
- observed query latency: approximately 28–105ms

Authenticated Production desktop technical UAT on the existing CN Mac ERP tab:
- Header Global Search control rendered
- Product + Inventory grouping rendered
- Order result rendered with canonical ERP order identity and tracking
- overlay closes back to the original Inventory page
- no horizontal overflow at 1680px viewport

Actions jobs remained account-side infrastructure failures with steps=null; CI-green was not claimed.

No migration or Production business-data write occurred.

## Closure

Owner visual UAT: PASS on 2026-09-20.

Final status:
- CLOSED
- PRODUCTION ACTIVE
- OWNER UAT PASS

No further Production mutation is required for V1. Any future Shipment/Customer/KOL/Purchase/Supplier/Project providers or search-index hardening must start as separate bounded Work Packages.
