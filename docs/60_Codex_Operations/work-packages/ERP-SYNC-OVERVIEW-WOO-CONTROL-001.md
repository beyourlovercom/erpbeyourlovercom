# ERP-SYNC-OVERVIEW-WOO-CONTROL-001

Status: CLOSED / DEPLOYED TO ERP / PRODUCTION ACCEPTED

## Owner intent

Make `/sync` the operational home for WooCommerce Order sync:
- see whether automatic Woo Order sync is enabled;
- see the hourly cadence and latest automatic result;
- trigger `手动同步今日订单` directly from `/sync`;
- keep `/sync/woocommerce` as the advanced maintenance page;
- every manual today-sync must create a visible, clickable manual SyncRun record.

## Frozen behavior

Automatic Woo Order sync remains hourly (`0 * * * *`) and keeps its existing checkpoint + overlap behavior.
Manual sync uses the existing `/sync/woocommerce/incremental` endpoint, whose semantics are already frozen as Woo-created-today only: `00:00 UTC -> click time UTC`, ordered by creation time, with no `modified_after` cursor.

Manual today-sync now records `woocommerce_order_manual_today` in Sync OS with:
- `initiated_by` = the operator;
- provider = `woocommerce`;
- domain = `orders`;
- context carrying `after` / `before` and `mode=manual_today`;
- one SyncRun item per Woo source order;
- exact admin zero-line shells recorded as skipped;
- canonical Order creates/updates recorded as succeeded;
- failures recorded per item.

## `/sync` UX

A dedicated `WooCommerce 订单同步` panel appears on the Sync overview and shows:
- automatic sync status;
- frequency `每 1 小时`;
- last automatic sync time;
- latest success/failure result;
- `手动同步今日订单` button;
- `高级设置` link to `/sync/woocommerce`.

View-only users see the panel but cannot execute the manual action.

## Verification

- focused Vue test: 3/3 PASS;
- Vue typecheck: PASS;
- production Vite build: PASS;
- `git diff --check`: PASS;
- real Laravel + temporary SQLite integration: PASS;
  - manual summary: seen2 / created1 / skipped1 / failed0;
  - manual SyncRun visible, `partial`, requested2 / succeeded1 / skipped1 / failed0;
  - query: `2026-09-06T00:00:00Z -> 2026-09-06T15:30:00Z`;
  - `/sync` exposes hourly Woo control with operator permission;
  - manual and automatic runs are both present in overview data.

Architecture test remains 3 failures / 8 tests / 128 assertions. A clean detached `main@1d2c0d1` produces the exact same 3 failures, confirming pre-existing global frozen-baseline drift unrelated to this WP.

## Production acceptance

Owner `pass` authorized Production deployment on 2026-09-06.
- no migration; bounded runtime + frontend deploy only;
- `/sync` live props show Woo sync enabled, frequency `每 1 小时`, manual action `/sync/woocommerce/incremental`, latest automatic Run #531 completed;
- Owner clicked `手动同步今日订单`; Production created manual SyncRun #533 with `initiated_by=1`;
- #533 completed: requested4 / processed4 / succeeded4 / skipped0 / failed0 / conflict0;
- four source orders (40212, 40213, 40214, 40215) were all created on 2026-09-06 UTC inside the exact manual window;
- post-UAT `/up` = 200 and Woo duplicate refs remain 0.

`ERP-SYNC-OVERVIEW-WOO-CONTROL-001 = CLOSED / DEPLOYED TO ERP / PRODUCTION ACCEPTED`.
