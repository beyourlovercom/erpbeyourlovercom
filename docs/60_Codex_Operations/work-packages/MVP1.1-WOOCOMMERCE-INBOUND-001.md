---
id: MVP1.1-WOOCOMMERCE-INBOUND-001
title: WooCommerce Inbound
status: IN_PROGRESS
branch: feature/erp-mvp1-1-woocommerce-inbound-001
target_base: main@55af41443c9acd74eff415fab8ba9ad211334376
baseline_tree: 1497d72aa5ce2b212330f91f8893e2c9e629ddde
owner_uat: REQUIRED
---

# MVP1.1-WOOCOMMERCE-INBOUND-001 — WooCommerce Inbound

## Goal

Replace manual re-entry of WooCommerce customer orders with a controlled inbound path into the existing canonical Order truth.

## Frozen scope

1. Environment-backed WooCommerce REST API connection with an explicit connection key.
2. Connection test from ERP.
3. Single import by WooCommerce provider order ID.
4. Incremental import from the maximum successfully observed WooCommerce provider modification timestamp.
5. Historical import by explicit date range.
6. Normalize WooCommerce payloads before persistence.
7. Idempotent upsert into existing `orders`, `order_addresses`, `order_items`, and `order_external_references`.
8. `order_kind=customer`, `source_type=woocommerce` for WooCommerce customer purchases.
9. Resolve Product by WooCommerce product/variation ID first, then SKU; unmatched products remain explicit nullable `product_id` snapshots and never auto-create Product Master records.
10. Audit inbound create/update operations.
11. Operator-visible import summary and failures.

## Canonical contract adjustments

- Add `OrderSourceType::WooCommerce = woocommerce` while preserving legacy `website`, `kol`, `manual_other`, and `manual` values.
- Add `connection_key` and `provider_modified_at` to `order_external_references`; enforce `UNIQUE(provider, connection_key, reference)`.
- Add order currency so WooCommerce currency is not discarded.

## Import semantics

- Provider identity: WooCommerce numeric order `id`.
- Human/business number: WooCommerce `number` → `orders.external_order_number`.
- New Woo orders enter canonical Order only; no `woocommerce_orders` table is allowed.
- Existing imported orders are matched only through `(provider, connection_key, reference)`; no fuzzy matching.
- Remote cancellation/refund may cancel a locally pending order; an already shipped/cancelled local order is not downgraded by inbound refresh.
- Existing local owner assignment is preserved on re-import.
- Re-import replaces the source address/item snapshot with the latest normalized provider snapshot.

## Connection / security

Credentials are runtime environment configuration only:

- `WOO_BASE_URL`
- `WOO_CONSUMER_KEY`
- `WOO_CONSUMER_SECRET`
- `WOO_CONNECTION_KEY`
- `WOO_TIMEOUT_SECONDS`

Secrets must not be persisted to application tables, audit payloads, logs, fixtures, or Git.

## Out of scope

- WooCommerce tracking write-back (MVP1.2).
- Webhooks, scheduled polling, queues, sync runs/items, automatic retry, conflict/reconciliation engine (MVP1.3).
- Product auto-creation from an order.
- AffiliateWP/KOL attribution.
- Reservation, warehouse/carrier automation, WMS connectors.
- Production deployment or production credentials in repository files.

## Acceptance gates

- Single import creates exactly one canonical Order and one external reference.
- Re-import of the same Woo order updates that Order and does not duplicate it.
- Two connections may use the same provider reference without collision.
- Incremental import uses an overlap-safe cursor and remains idempotent.
- Historical import accepts an explicit date range and paginates Woo REST results.
- Woo address/items/currency/status are normalized and persisted.
- Unmatched SKU/Product does not auto-create Product Master records.
- Existing manual and KOL order workflows remain green.
- Focused tests, Architecture gate, full PHPUnit, Vitest, typecheck, build and US Mac Owner UAT pass before merge.

## Delivery note

The local development seed commit is `3225d84cf312e03115c307403a08855eb667b6c5`, whose tree exactly equals the merged `main` tree `1497d72aa5ce2b212330f91f8893e2c9e629ddde`. Before PR delivery, implementation commits must be replayed onto exact `main@55af41443c9acd74eff415fab8ba9ad211334376` so PR ancestry is clean.
