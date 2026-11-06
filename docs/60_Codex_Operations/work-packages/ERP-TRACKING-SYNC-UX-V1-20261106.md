# ERP 官网物流同步状态 UX V1 — 2026-11-06

Issue: #639
Branch: `feat/erp-tracking-sync-ux-v1-20261106`

## Owner-approved UX rules

1. Remove the duplicate always-visible header-level `同步官网物流` action. Batch sync remains contextual and appears only after selecting eligible rows.
2. A green `已同步` badge requires current ERP Tracking fingerprint evidence, a completed WooCommerce Tracking Outbox, a completed Woo order-status Outbox whose payload is `woo_status=completed`, and the corresponding ERP ManualSync state. The explicit V3.1 manual override marker is accepted only when its stored Tracking fingerprint still matches.
3. Hide the row-level sync action after verified success. Show `重新同步` for failure, retry, or stale Tracking evidence. A Tracking change invalidates the prior success projection.
4. `部分同步` is shown when the current Tracking package is verified but the latest Woo order-status Outbox is still `processing` (other parcels are not complete).
5. Use one read-only batch projection across domestic logistics, overseas logistics, Orders list, and Sync Orders. Do not make per-row Woo HTTP requests while rendering a page.
6. Show the latest verified timestamp in the tooltip. This is the last verified snapshot, not a live HTTP check on every list render.

## Implementation

- `LogisticsTrackingListStatus` now batches current Shipment Tracking, Tracking Outbox, Woo order-status Outbox, and V3.1 manual verification markers.
- Main Orders list (classic table and OMS Grid) gets a final `官网同步` column.
- Sync Orders displays the same verified status, reason, and last verification timestamp.
- Domestic and overseas logistics remove the duplicate header action; selected-row batch action remains contextual. Successful row actions are hidden; stale/failed rows can retry.
- Protected Woo statuses, V3.1 A/B/C package notification behavior, and the 30-minute scheduler are unchanged.

## Verification gates

- PHP feature tests: `LogisticsTrackingListStatusTest`, `DomesticLogisticsProductionClosureTest`, `OverseaWarehouseWorkbenchTest`, `OrderSavedViewsTest`.
- Vue tests: Domestic Index, Oversea Show, Orders Index, Sync Orders.
- `vue-tsc --noEmit`, Vite production build, Pint, and `git diff --check`.
- No Production data writes, no customer emails, and no Production deployment in this work package.
