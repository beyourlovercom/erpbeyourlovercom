# ERP2 R0 — Latest main compatibility delta (2026-11-19)

> STATUS: SOURCE DIFF CONFIRMED / DEV RUNTIME NOT VERIFIED / ERP2 R1-R2 ADAPTERS NOT IMPLEMENTED. Documentation-only checkpoint on the existing R0 Draft PR #800. This does not claim ERP2 UAT, Laravel migration execution, production deploy, or real-order migration.

## Authority and pinned revisions

- Prior static index: ERP main `eb8ed64e7790efbcc6271742c8beaac3eaa3b30b` (2026-11-18, 178 migration files).
- New observed GitHub main: `5026ddc060c43ab44c8e09c1b229e3c744036b3e` (2026-11-19).
- GitHub compare: old main is an ancestor; **50 commits ahead** at this snapshot. This is not an automatic Dev runtime update.
- ERP2 frontend: Draft PR #766 head `299f837a94432e5b2c22b4c2c80c32162ae9fb10`, not merged. Its runtime carries source marker `CURRENT_LARAVEL_MAIN_DEV_B07885E3` and checks component `Orders/Index`.
- The R0 178/178 migration audit and 176 literal table-reference count remain historically accurate **only for the old SHA**; do not rebrand those numbers as the latest main or the executed Dev schema.

## Confirmed orders route and response compatibility change

At latest main in `app/Modules/Orders/Experience/Routes/web.php`:

1. `GET /orders` -> `OrderMasterController::index`, `can:orders.view`; controller renders **`Orders/Master`**.
2. `GET /orders/master` -> redirect to named route `orders.index` (`/orders`), preserving query parameters.
3. The prior `OrderController::index` can still render `Orders/Index`, but it is **not** the current `GET /orders` route owner.
4. ERP2 #766's `normalizeExistingInertiaOrders` and `r2b-current-gateway.cjs` currently expect `Orders/Index`; they need a contract-specific `Orders/Master` serializer, revised allowlisted filters/sort keys, and exact source-version proof. Merely changing the version marker or reusing the old row shape is forbidden.
5. The source Dev server's checked-out HEAD, database schema and actual GET response must be measured separately in the isolated Dev environment. No Production order data may be queried for the ERP2 test.

## Confirmed warehouse-final-SKU schema delta

New main migration `database/migrations/2026_11_18_000100_create_product_warehouse_skus_and_final_order_sku.php`:

- Creates canonical `product_warehouse_skus` keyed uniquely by `(product_id, warehouse_id)` and `(warehouse_id, sku)`, with `is_active`, `verified_at`, `verified_by`, `verification_note`.
- Adds nullable `order_items.final_sku` after `resolved_fulfillment_sku`.
- `WarehouseFinalSkuResolver::forShipment` prioritizes persisted `order_items.final_sku`; with strict mapping enabled, verifies it against the shipment's actual warehouse. Legacy fallback exists under separately configured compatibility rules.
- Therefore the current source contains **at least 179 migration files**, including one additional schema migration since the prior 178-file static audit; final executed table/field counts remain UNKNOWN until Dev schema verification.
- ERP2 must distinguish `original_sku`, `resolved_fulfillment_sku`, `final_sku` and warehouse mapping state. No source `items[].sku` may be relabeled `warehouse_final_sku`; do not invent a final SKU for rows where the column is null.

## Current Laravel logistics routes (rechecked at latest main)

- `GET /logistics/wait-stock`: `LogisticsController::waitStock`, `can:logistics.view`, Order-based pending-allocation predicate.
- `GET /logistics/china`: `DomesticLogisticsController::index`, permission enforced inside controller, Order / physical-shipment based; preserve `domesticReady` **without mandatory Kaven approval**.
- `GET /logistics/oversea/{warehouse?}`: `OverseaWarehouseController::index`, `can:logistics.view`, Shipment-based; preserve review warehouse match, OMS issued versus shipped distinction, 3SM aliases.
- GitHub compare shows these three controllers and multiple associated logistics services changed between the SHAs. Previous exact query predicates cannot be claimed current until audited on latest HEAD.

## R0–R2 execution gates, no shortcuts

1. R0: Read latest route/controller changes; verify **isolated Dev** checked-out SHA and DB host/name prior to any `migrate:status`; verify migration/schema delta and permissions. Keep R0 static-index SHA labels unchanged.
2. R1/R2 backend-only: bounded, separate Work Package and dedicated branch/worktree; reuse canonical Laravel query owners for three **GET-only JSON projections**, with server authorization, pagination/count parity, negative-role tests, final-SKU/null semantics and zero Woo/OMS side effects. Do not parse Inertia HTML as the business JSON API.
3. ERP2 frontend: Draft #766 is an explicit unmerged dependency; no unapproved stacked PR, no bypass of required Owner rendered-design PASS for new pages. After dependency and visual gates, wire three read-only screens and perform **007windows** real Dev UAT; empty lists are not a functional PASS.
4. Final review: exact commit PHPUnit/TS/build, source pin consistency, role 403, state fixtures, warehouse aliases and Final SKU. No Production deploy, no real-order import, no mutating warehouse dispatch.

**Work Package status:** R0 baseline delta documented; runtime schema gate HOLD; R1/R2 implementation/UAT pending. No Production changes.
