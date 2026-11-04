# Inventory dashboard V1.2 — single-row horizontal metrics

Owner authorization: develop, merge to GitHub and controlled release Production.

## Scope

- Only `resources/js/Pages/Inventory/Dashboard.vue` changed at runtime; companion Vue test.
- Retain all 13 inventory/warehouse/overdue metrics and calculations, just reorder to show
  day-to-day stock first: available, recorded, overdue, reserved, out-of-stock, low,
  in-stock, inventory records, warehouse total, fulfillment, inventory-only, confirmed,
  unimported warehouses.
- Show 13 compact metric cells on **one non-wrapping horizontally scrollable row**, ~76px
  high. Native trackpad/touch scrolling, visible thin scrollbar, keyboard Left/Right,
  arrow buttons. Works on mobile without stacking vertically.
- `/inventory/overdue` remains linked from overdue metric, no invented destinations
  for other counters. Compact indicator colors only on overdue/out-of-stock/low-stock.
- Warehouse list immediately follows this one strip; existing warehouse links,
  creation permission, warehouse rows and all back-end APIs unchanged.
- No schema migrations, warehouse adjustments, P9 changes, stock or OMS writes.
- Because this visual-only release builds onto latest running release, preserve
  all current ERP/Logistics/MorePlus runtime files, shared .env/storage/node_modules.

## UAT

- Vue suite: 24/24 across Inventory Foundation, Overdue, MorePlus Stock Sync,
  Warehouse Restock, Warehouse Inbound.
- Inventory Foundation confirms exactly **13** counters, exact labels/values/order,
  only established overdue link, keyboard + arrow scrolling, and original warehouse
  links/create permission.
- `vue-tsc --noEmit` PASS; Vite build PASS; `git diff --check` PASS.

## Production safeguards

- Merge only exact approved PR HEAD after CI success.
- Fresh compare current Production baseline; compose `Dashboard.vue` overlay onto
  latest release, rebuild Vite; no DB migration or shared files overwritten.
- Atomic symlink switch, retain original release as rollback.
- Post-switch verify /up, /login, asset, GET inventory/overdue auth,
  and stock/FIFO write guards remain HARD_BLOCKED.
