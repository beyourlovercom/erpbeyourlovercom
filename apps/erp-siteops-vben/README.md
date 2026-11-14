# BYL ERP SiteOps/Vben — 007windows isolated development pilot

This directory versions the BYL-specific frontend overlay inside the existing ERP repository. The ERP Laravel monolith is the sole authority for orders, identity, permissions, inventory, logistics, finance and Woo sync.

## Current boundaries
- 007windows local source: `D:\ai\erp-siteops\frontend`
- Local browser: `http://127.0.0.1:18786/` (loopback only)
- Separate local gateway and credentials remain under `D:\ai\erp-siteops`, **not committed here**.
- Source UI base: Owner-accepted SiteOps Vben V9.1 at `D:\ai\siteops-vben\vue-vben-admin-main` — never edit this original.
- Pinned upstream archive: `D:\ai\siteops-vben\vben-upstream-main.zip`; SHA-256 `DF1E977A85EB0D55E9A75D19E270180BFF68E8E7FCD15759D69919223E44141B`.
- This is an **overlay-only** Git checkpoint. The full Vben vendor/base is not committed; full reproducibility via a pinned Git baseline remains an R1 blocker.
- Existing SiteOps ports 18777/18778, ERP Production, database, jobs, user login and real provider data have not been modified or contacted.

## What is versioned
- ERP Vben menu, compact workspace, nine navigation entries, standalone order grid preview.
- The real-orders adapter and tests in `overrides/src/api/erp-orders*`.
- Browser-facing order data mapping for Laravel's existing `GET /orders` Inertia page JSON.
- Safe build/test scripts and dry-run overlay applicator.

## Read-only orders authority
- Existing route: `GET /orders` (web + auth + can:orders.view), `OrderController@index`, Inertia `Orders/Index`.
- No new business API, no new SQL or direct cloud/WordPress connectors.
- The adapter requests `X-Inertia: true` on the **same authenticated Laravel Dev origin**. It never uses a separate SPA token for the ERP backend.
- Preserve source `erp_order_id`, workflow-status projection, shipment details, SKU lines, server-side paging and existing role/archived filters.
- Do not force `oms_grid=1` or override the existing OMS canary permission.
- `VITE_ERP_READONLY_ORDERS_ENABLED` defaults to OFF; current UAT shows synthetic `UAT-ORDER-*` rows only. Live Dev Session 200 is not yet verified.
- Business writes (POST/PATCH/DELETE), order mutations, WooCommerce and OMS remain disabled.

## 007windows restore
1. Checkout ERP feature branch and verify SiteOps V9.1 approved source locally.
2. Prepare `D:\ai\erp-siteops\frontend` as an isolated copy of that approved base without node_modules/dist/private files.
3. In the ERP repository, run `node apps/erp-siteops-vben/scripts/bootstrap.cjs` for a dry run; use `--apply-overlay` to copy the tracked changes into **only** that isolated destination.
4. Run local `D:\ai\erp-siteops\install-offline.cmd`, `test-orders.cmd` and `build.cmd`.
5. Existing local gateway remains separate. Do not put its password file or runtime credentials in GitHub.

## UAT requirements before any real Dev read
- Confirm local Laravel Dev application and user session are reachable on the same origin.
- Test role 403, expired session, 20/50/100 paging, all-record search, status, archived/deleted and KOL scope parity.
- Browser test on 007windows and Owner PASS before Production cutover discussions.
- Neither GitHub merge nor Production release is authorized by this draft checkpoint.
