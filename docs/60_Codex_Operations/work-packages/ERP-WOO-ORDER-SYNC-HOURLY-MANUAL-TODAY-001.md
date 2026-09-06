# ERP-WOO-ORDER-SYNC-HOURLY-MANUAL-TODAY-001

## Owner requirement

- Automatic WooCommerce Order sync changes from every 5 minutes to once per hour.
- Operator can click a manual sync button.
- Manual sync imports only Woo orders created today, starting at 00:00 through click time.
- Orders created before today's 00:00 are outside the manual-sync scope, even if modified today.

## Frozen semantics

- Woo source timezone is UTC: latest Production Woo payload has identical `date_created` / `date_created_gmt` and `date_modified` / `date_modified_gmt`.
- Manual date window therefore uses Woo business day `00:00:00 UTC -> now UTC`.
- Manual query uses `after`, `before`, `status=any`, `orderby=date`, `order=asc`; it does not use `modified_after` and does not read the automatic checkpoint.
- Existing provider-reference idempotency remains unchanged.
- Exact `created_via=admin` + empty `line_items` rows are skipped during manual today sync.
- Manual today sync does not create or advance automatic SyncRun checkpoints.
- Automatic incremental checkpoint and 5-minute overlap semantics remain unchanged; only scheduler cadence changes.
## Implementation

- Scheduler: `orders:sync-woocommerce --no-interaction` -> `hourly()`, named `orders.woocommerce.inbound.hourly`, overlap lock 55 minutes, one server.
- Existing manual `/sync/woocommerce/incremental` action is repurposed to `importToday()` so no old cursor-based manual import remains exposed by that button.
- Woo inbound UI now labels the action `手动同步今日订单` and explains the 00:00-to-now boundary.

## Verification

Isolated real-Laravel integration against temporary SQLite:
- first manual run: seen 2 / created 1 / updated 0 / skipped 1 / failed 0;
- second manual run: seen 2 / created 0 / updated 1 / skipped 1 / failed 0, proving idempotency;
- exact query: after `2026-09-06T00:00:00+00:00`, before `2026-09-06T15:30:00+00:00`;
- no `modified_after` parameter;
- automatic SyncRun count created by manual action: 0;
- hourly scheduler count 1, cron `0 * * * *`, overlap 55, `onOneServer=true`.

Vue/Vite full build PASS. `git diff --check` PASS.

## Current gate

`WOO_ORDER_SYNC_HOURLY_MANUAL_TODAY = CLOSED / DEPLOYED TO ERP / PRODUCTION ACCEPTED`

Production acceptance on 2026-09-06:
- fresh database backup Run #27 ready; encrypted artifact checksum verified;
- bounded deploy only, no migration;
- live scheduler changed to `0 * * * *`; scheduler restarted successfully;
- automatic canary SyncRun #531 completed: seen2 / created1 / updated1 / failed0;
- Owner manual UAT SyncRun #533 completed: requested4 / succeeded4 / skipped0 / failed0 / conflict0;
- manual context: `2026-09-06T00:00:00+00:00 -> 2026-09-06T04:26:45+00:00`;
- source Woo orders 40212-40215 all have `date_created_gmt` inside that window;
- post-UAT health: Woo refs6041 / duplicate refs0 / null Product items18 / open SKU reconciliation0 / failed_jobs0 / `/up`200.
