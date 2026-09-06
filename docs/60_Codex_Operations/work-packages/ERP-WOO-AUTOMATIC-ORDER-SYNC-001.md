# ERP-WOO-AUTOMATIC-ORDER-SYNC-001

## Scope
Close the gap between accepted Woo historical import and unattended new-order ingestion.

Production already has a live Laravel scheduler worker and queue worker. Before this WP, Woo Order inbound supported single, manual incremental, and historical execution only; no Order scheduler event existed.

## Frozen behavior
- command: `orders:sync-woocommerce`
- schedule: every 5 minutes when explicitly enabled
- overlap: existing 5-minute provider-modified overlap
- idempotency: existing `(provider, connection_key, reference)` Order contract
- page bound: 100 pages × 100 Orders
- statuses: `any`

Activation flag:
`WOO_ORDER_SYNC_ENABLED=false` by default.
## Automatic checkpoint contract
Automatic sync records durable execution evidence in existing `sync_runs` / `sync_run_items`; no new table is introduced.

Checkpoint precedence:
1. latest scoped automatic run context `checkpoint_modified_at`;
2. existing latest Woo Order external-reference `provider_modified_at` for first bootstrap.

A run advances checkpoint only when `failed=0`. The next query starts five minutes before the checkpoint, so overlap is safe through canonical idempotency.

Known Woo non-product shell contract:
- `created_via=admin`
- exact empty `line_items`
- recorded as SyncRun `skipped`
- no synthetic Product, SKU, item, or canonical Order
- does not block checkpoint progression.

Any other normalizer/upsert failure is a real failure: it is recorded as failed, command exits non-zero, and checkpoint remains unchanged for retry.
## Architecture
Orders Application consumes only the Sync module public contract `SyncRunTracker`; it does not depend on Sync internal Application implementation classes.

`SyncRunService` implements that contract and the existing Sync provider binds it. Scoped context lookup reuses the existing SyncRun repository.

Scheduler event when enabled:
- `orders:sync-woocommerce --no-interaction`
- `*/5 * * * *`
- `withoutOverlapping(10)`
- `onOneServer()`

Code merge alone cannot activate Production because the feature flag defaults false.

## Verification
Post-rebase branch base: `main@88c037693a982a2674bb9853d1977e619ba03ac2`.

Focused regression: `13/13 PASS`, `95 assertions`:
- automatic checkpoint across accepted admin shell;
- real zero-line checkout failure blocks checkpoint;
- scheduler bounding;
- all existing Woo single/manual incremental/historical/SKU resolver/long-address tests.
Architecture suite currently reports the same three frozen-baseline failures already present on clean main: module file/directory freeze, application-layer frozen list, and migration frozen list. Clean `main@829c81b` was independently verified to produce the same three test-case failures. This WP does not broaden scope to repair unrelated global baseline drift.

`git diff --check` = PASS.

## Production state
Automatic Woo Order schedule has NOT been enabled in Production.

No schema migration is required by this WP. No credential changes are required.

Current gate:
`WOO_AUTOMATIC_ORDER_SYNC = CODE_READY / FOCUSED_TESTS_PASS / PRODUCTION_DISABLED`

Next gate is read-only Production catch-up reconciliation, followed by explicit Owner authorization before setting `WOO_ORDER_SYNC_ENABLED=true` or otherwise activating scheduled Production writes.

## Production read-only catch-up pre-scan
Production cursor at scan start: `2026-09-05 10:17:48 UTC`; canonical Woo refs: `6028`; failed jobs: `0`; `/up=200`.

Woo query from five-minute overlap returned 13 changed source rows across one page:
- 1 row already exists in ERP and is overlap/idempotency coverage;
- 12 rows are not yet canonical ERP Orders: `40199, 40200, 40201, 40206, 40207, 40208, 40209, 40210, 40211, 40212, 40213, 40214`;
- source statuses: 12 processing, 1 pending across the whole overlap window;
- source created_via: checkout/store-api only;
- new zero-line admin shells: 0;
- normalizer failures: 0.
The 12 canonical candidates contain 18 line items. Read-only resolution using the same precedence as Order inbound produced:
- resolved items: 18/18;
- unresolved usable-SKU items: 0;
- blank-identity items: 0.

A second read-only normalized-field vs live Production schema scan found 0 overflows. Maximum observed lengths include `address_line_2=36`, `sku=12`, `product_summary=29`, and `external_order_number=31`.

Frozen first automatic-run expectation at this scan point:
`13 overlap-window rows = 1 existing idempotent update + 12 new canonical Orders + 0 admin shells + 0 failures`.

Current gate:
`WOO_AUTOMATIC_ORDER_SYNC = PASS / PRODUCTION ACCEPTED`

## Production activation closure — 2026-09-06
- Owner activation authorization: PASS.
- PR #204 merged as `152d3290e59ad6cf7881a9b850b2b36d5d6da698`.
- Fresh encrypted DB backup before activation: run #23, UUID `582a5844-96dd-4977-ab70-52460f3c1a63`, state `ready`.
- Backup artifact checksum verified live: `4b5a5e09fe82b428b60fe2405eb994c238e6166be3911c0ba2b3069afda4cbf3`.
- Production deployment was bounded to the 10 #204 runtime files only; no migration was executed.
- Runtime rollback package: `backups/woo-order-auto-runtime-20260906-031715`.
- Deployed runtime fingerprint: 10/10 files match merge commit `152d3290...`.
- `WOO_ORDER_SYNC_ENABLED=true`; schedule declaration is `*/5 * * * *`.
- Manual activation canary: SyncRun #516, completed, seen=1, created=0, updated=1, skipped=0, failed=0.
- First verified scheduler-owned tick: 2026-09-06 03:20:02 UTC; SyncRun #517 completed, processed=1, succeeded=1, failed=0.
- Final health: Woo refs=6040, duplicate refs=0, null-Product historical exception items=18, open SKU reconciliation=0, failed_jobs=0, `/up`=200.
- Scheduler process restarted successfully and is running with the automatic Woo Order event loaded.

Formal status: **WooCommerce Automatic Order Sync — CLOSED / DEPLOYED TO ERP / PRODUCTION ACCEPTED**.
