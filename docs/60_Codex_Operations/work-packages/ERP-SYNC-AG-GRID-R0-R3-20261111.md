# ERP 同步订单总表 · Global AG Grid R0–R3

Date: 2026-11-11
Scope: front-end table migration and bounded, server-backed navigation. **Dev/UAT only until Owner R4 PASS.**

## R0 — existing capability and release gates

- Existing shared components: `GlobalGrid.vue`, `OmsGrid.vue`, `GlobalGridCell.vue`, existing `/ui/preferences/{namespace}` account-scoped layout persistence.
- Reuse current `GlobalGridPilot`: Dev/Local can opt in with `?global_grid=1`; Production remains legacy unless Owner ID 1 explicitly requests canary `?global_grid=1` after a separate authorized release.
- Current Sync status projection and manual WooCommerce tracking safety controls are unchanged. A blocked or conflicted row must **not** cause a WooCommerce API write.
- No migration, queue/scheduler/Worker changes, no Woo credential setup.

## R1 — shared Grid integration

- `/sync/orders?global_grid=1` uses the shared `GlobalGrid` (not a new AG Grid instance).
- Dedicated account preference namespaces: `grid.sync.orders.global.v1` desktop and `grid.sync.orders.global.mobile.v1` mobile.
- Required/locked: ERP order number (left), manual actions and sync progress (right). The status is the rightmost column and cannot be hidden. All remaining fields can be hidden/reordered by user preference.
- Account settings restore before rendering; existing compact density, maximization and bounded server page controls remain in the shared engine.
- Mobile uses narrow essential fixed columns (order number / sync action / progress); no horizontal body overflow. Source and metadata remain available through Grid's horizontal scroll/column settings.
- The shared `GlobalGrid` gains only an optional `defaultPinnedRight` prop (defaults to the previous value **1** for existing pages).

## R2 — status/actions and business authority

- Four statuses remain unchanged: 未同步 / 同步失败 / 已同步 / 已通知.
- A "同步网站" button appears only for authorized operators on unsuccessful rows; if the shipment cannot be written, it shows the exact block reason without POST. Original per-order confirmation and server gates are untouched.
- "核对官网" and "原因" remain accessible in desktop action cells, while progress pills show their reasons; mobile keeps direct single-row sync control.
- A/B/C child order IDs and Woo root association continue to use the existing Sync payload.
- Existing advanced tools and original list are preserved for controlled rollback.

## R3 — server-backed data, tests and UAT

- All ERP orders remain queryable; no `manual_syncs` record required. Archive visibility remains unchanged.
- Existing search by ERP/source/internal order and recipient, source filter, 20/50/100 pagination.
- Whitelisted database sort keys `date`, `order_number`, `source` with `asc/desc`; prevents arbitrary columns and load-all.
- The Sync grid cannot export an incomplete current page as a falsely complete CSV and has no new bulk write path.
- Independent Mac mini UAT: `http://127.0.0.1:18870/sync/orders?global_grid=1`. Synthetic SQLite only; no Woo credentials.
- UAT worktree: `/Users/a/ai/uat/erp-sync-global-grid-r0-r3-20261111`.
- Browser verification includes desktop and mobile, all four status types, safe blocked-row handling, search/source/sort, account preference persistence after page reload, and absence of Woo writes.

## Gates remaining

- **R4**: Owner visual check in independently isolated UAT, then optional administrator-only Production Canary by separate explicit authorization. Confirm old version remains available.
- **R5**: exact-HEAD CI, Owner approval, controlled Production release and per-page visual readback. Preserve rollback target and don't change existing Tracking flags.

No Production cutover, real WooCommerce order synchronization, customer note/email, queue or scheduler mutation is allowed under this R0–R3 authorization.
