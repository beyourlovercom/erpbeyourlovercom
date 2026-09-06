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
