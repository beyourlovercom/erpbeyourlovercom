# BYL ERP Entity Architecture V2

**Status:** OWNER_APPROVED / DESIGN STANDARD
**Date:** 2026-08-26
**Scope:** Long-term ERP entity identity, cross-module relationships, 360 pages, taxonomy, projects, search, read models and future module integration.

## 1. Core Principle

BYL ERP is a connected business-entity system, not a collection of isolated module pages.

Every durable business object owns a canonical internal ID. Human-readable numbers, SKU, WooCommerce IDs, tracking numbers, material codes and other external identifiers remain searchable business identifiers, but they do not replace canonical relational IDs.

Primary anchors already frozen:

- `orders.id` / `order_id` = canonical Order anchor.
- `products.id` / `product_id` = canonical Product anchor.
- `order_items` = canonical bridge between Order and Product.

Future major entities follow the same pattern, including `warehouse_id`, `supplier_id`, `shipment_id`, `purchase_order_id`, `project_id`, `tooling_id`, `customer_id` and `kol_id`.

## 2. Three-Layer Entity Architecture

1. **Canonical Entity Layer** — each durable business object owns its permanent internal ID.
2. **Explicit Business Relation Layer** — core relations use real foreign keys and bounded relation tables.
3. **Entity Navigation Index Layer** — optional derived relation/search indexes support discovery, navigation and 360 aggregation without becoming business truth.

## 3. Data Authority Rules

Business modules retain their own truth. Product and Order 360 pages aggregate related information but do not duplicate changing operational state.

Examples:

- Product = product master truth.
- Inventory = stock truth.
- Procurement/Replenishment = supply truth.
- Freight/Inbound = in-transit truth.
- Orders = transaction truth.
- Finance = accounting/profit truth.
- Audit/Event streams = history/timeline evidence.

Never add warehouse-specific stock columns, in-transit quantity, production quantity, shipment state or other dynamic module state directly to `products` merely for convenience.

Generic `entity_relationships` may exist as a derived navigation index, but explicit tables such as `order_items`, `inventory_balances`, `purchase_order_items` and `freight_items` remain authoritative.

## 4. Product 360 and Order 360

Any UI that shows a canonical entity should support navigation to its 360 page.

Product 360 may aggregate master data, warehouse inventory, replenishment, inbound/freight, suppliers, tooling, projects, recent orders, cost/profit summaries and audit history.

Order 360 may aggregate identity, customer/KOL, order items, shipment, warehouse fulfillment, payment/refund, cost/profit, sync state and audit history.

Cross-links are bidirectional. Product rows in an Order link to Product 360; Order rows in a Product view link to Order 360.

## 5. Taxonomy and Project Relations

Product classification is multi-dimensional. Do not add `category_1`, `category_2`, `category_3` or equivalent fixed columns.

Use taxonomy/term relations for product category, family, series, lifecycle, business line, sales channel, supply type and future classification dimensions.

Projects are independent entities. Products, suppliers, tooling, purchase orders, shipments and other entities may belong to one or many projects through relation tables.

This permits future views such as:

- by product category;
- by product family/series;
- by project;
- by brand;
- by lifecycle;
- by supply/manufacturing classification.

Tooling/die/mold is also an independent entity when introduced. A product may use multiple tooling assets and a tooling asset may relate to multiple products.

## 6. Global Search Standard

The ERP Header shall provide a persistent search icon on the right side. Selecting it opens a global search overlay/window without navigating away first.

Global Search searches across registered entity types, initially including Product, Order and Inventory context, and later Warehouse, Shipment, Purchase Order, Supplier, Customer, KOL, Project, Tooling and other registered entities.

Search results are grouped by entity type and every result deep-links to the canonical entity or a canonical filtered module view.

Initial Product search identifiers should include product name, SKU, material/product code, GTIN, WooCommerce Product ID and brand where available.

Initial Order search identifiers should include visible business order number, canonical/internal technical identifiers where authorized, external provider order IDs, tracking number and bounded recipient/customer identifiers where supported by the module contract.

Inventory search is product-centered and warehouse-aware. Results should expose the Product and Warehouse context and link either to Product 360 or a pre-filtered Inventory view.

Global Search is a discovery/navigation capability only. Search indexes are derived data and must never become canonical business truth.

## 7. Scoped Module Search

Each major module also provides scoped search that searches only its own entity domain:

- Product pages: Product-only search.
- Order pages: Order-only search.
- Inventory pages: Inventory/Product/Warehouse scoped search.
- Future modules follow the same pattern.

Scoped search and Global Search shall reuse one Search Contract/provider architecture rather than implement unrelated search logic per page.

A module registering a new entity type should provide a bounded Search Provider describing searchable identifiers, result label, authorization filter and canonical deep-link target. Adding a new module must not require rewriting the Header search core.

Global Search may show a small ranked result set per entity type with a `View all` deep-link to the corresponding scoped search. Large result sets remain server-side filtered and paginated.

## 8. Search UX Rules

- Header search icon remains visible as a global capability.
- Clicking the icon opens the search overlay and focuses the search field.
- Results are grouped and labelled by entity type so Product, Order, Inventory and future entities are visually distinct.
- Exact identifiers such as SKU or business order number rank ahead of broad text matches.
- Selecting a result navigates directly to the canonical 360 page or a pre-filtered module detail/list.
- Search respects the current user's existing authorization and data-scope rules; search never exposes a result the user cannot otherwise access.
- Empty and loading states remain explicit.
- Keyboard navigation and Enter-to-open should be supported when the implementation package is authorized.

## 9. Scale and Performance Rules

Target scale includes at least 10,000 products and 100,000 orders with room for materially larger datasets.

All large lists and searches are server-side. Do not load entire Product, Order, Inventory, Shipment, Freight, Purchase or Audit datasets into Vue and filter them locally.

Database indexes must support canonical foreign keys and common search/filter identifiers. Exact-code lookup paths should avoid table scans.

360 pages use read projections/summary models for first-screen aggregates rather than unbounded live joins across every module. Source modules remain authoritative and detail views query their own data.

Offset pagination is acceptable for bounded current surfaces; high-volume chronological lists should be able to migrate to cursor/keyset pagination without changing canonical entity semantics.

A future external search engine such as Meilisearch, Typesense or Elasticsearch may replace or augment the search index, but canonical relational data remains authoritative even when search infrastructure is unavailable.

## 10. Future Module Registration Contract

Every new major module must answer:

1. What is its canonical entity and permanent ID?
2. Which module owns its business truth?
3. Which explicit foreign-key/relation tables connect it to existing entities?
4. Which 360 pages should expose it?
5. What canonical deep links are supported in both directions?
6. Which identifiers may its Search Provider expose, subject to authorization?
7. Which summary/read projections are needed for fast 360 rendering?

Unknown future modules such as quality inspection, samples, packaging, tooling, certificates, compliance, Amazon FBA, content/media, repairs or warranty must plug into this contract rather than add ad-hoc fields to Order or Product.

## 11. Frozen Rules

1. `order_id` is the canonical Order-world anchor.
2. `product_id` is the canonical Product-world anchor.
3. Every future major business entity receives its own canonical ID.
4. Core business relations use explicit foreign keys/relation tables; generic JSON/EAV does not replace them.
5. Generic entity relationship/search indexes are derived navigation infrastructure, not truth.
6. Product classification uses multi-dimensional taxonomy.
7. Project is an independent entity with many-to-many entity relations.
8. 360 first screens use bounded read projections rather than unlimited cross-module joins.
9. Large lists/searches use server-side filtering/pagination and remain cursor-ready.
10. Canonical entities shown in UI support bidirectional deep links.
11. Header Global Search and module Scoped Search share one extensible Search Contract.
12. Search results always enforce the same authorization/data-scope rules as their source modules.
