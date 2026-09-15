# ERP-LOGISTICS-CUSTOMER-HISTORICAL-SHIPMENT-BACKFILL-001

**Status:** CONTRACT FROZEN / IMPLEMENTATION AUTHORIZED / BACKEND ONLY
**Date:** 2026-09-14
**Owner scope:** 27 Tianyun customer Orders only; 16 historical/KOL excluded
**Execution:** GPT_WEB + US DC / GitHub connector fallback
**Verification tier:** HIGH_RISK
**Production apply:** NOT AUTHORIZED

## Objective
Restore missing canonical `shipments` rows for exactly the customer-order portion of the January 2026 Tianyun Germany warehouse bill so P6B actual carrier billing can match by exact tracking number.

This package repairs Shipment truth only. It does not import carrier billing, materialize Finance cost, change Order status, or reopen historical KOL evidence.

## Issued baseline
- Repository: `beyourlovercom/erpbeyourlovercom`
- Issued `origin/main`: `68b6433b3d0f52e377a25dc96bd9e899d80369d4`
- Production current observed at contract freeze: `logistics-exception-ux-pr301-20260914-022651-ef60fb49`
- Real bill source: `天运 20260101~0131账单.xlsx`
- Original XLSX SHA256: `2576b1ed2f1ee6c78c13e64c4f363b08567220316ccda33235c4df252a1c843d`
- P6B canonical bill dry-run: total 43 / ready 0 / review 43 / skip 0; all `shipment_not_found`.

## Frozen population
- 43 Tianyun bill rows total.
- 16 KOL/historical rows are permanently excluded from this package.
- 27 customer rows all exact-match existing ERP customer Orders.
- All 27 Orders currently have `order_status=shipped`.
- All 27 currently have `shipments=0` and `shipment_attempts=0`.
- Customer currency split: 26 EUR / 1 USD.
- No row outside this 27-order set may be mutated under this WP.

## Source facts
For each accepted row the bill supplies:
- warehouse: `德国仓(Hannover)` -> canonical warehouse code `DE`
- exact DHL tracking number
- exact outbound timestamp (`出库时间`)
- exact DHL channel (`物流渠道`)
- provider reference (`OMS单据号`)
- source reference (`参考单号`, Cxxxxx family)

Source outbound time is interpreted in `Europe/Berlin`; normalized Shipment `shipped_at` is stored as UTC. No bill or WMS timestamp is guessed as UTC.

## Canonical backfill input
Implement a dry-run-first CSV reader requiring:
`order_id,erp_order_id,tracking_number,warehouse_code,carrier,service,shipped_at,source_reference,provider_reference,source_row_number`.

The real 27-row CSV is operational evidence and must not be committed to Git.

## Preview contract
Each row must fail closed unless all are true:
1. `order_id` and `erp_order_id` identify the same Order.
2. `order_kind=customer`.
3. `order_status=shipped`.
4. no canonical Shipment currently exists for the Order.
5. no ShipmentAttempt currently exists for the Order.
6. warehouse code resolves exactly to an active canonical warehouse.
7. tracking is nonblank, unique in source, and absent from all canonical Shipments.
8. carrier/service are nonblank source facts.
9. `shipped_at` is an offset-aware ISO-8601 value and normalizes deterministically to UTC.
10. source row is in the approved customer population; KOL/historical references are review-blocked.

Preview returns source SHA, plan SHA, total/ready/review/skip, per-row decision/reason and proposed Shipment facts. Dry-run writes nothing.

## Apply contract
Production apply requires all of:
- process-local `ERP_LOGISTICS_CUSTOMER_HISTORICAL_SHIPMENT_BACKFILL_PRODUCTION_APPLY_ENABLED=true`
- exact source SHA
- exact plan SHA
- exact ready/review/skip counts
- exact `ready=27` for the first approved Tianyun batch unless Owner separately narrows scope

Apply is one database transaction and re-locks every Order/Shipment/ShipmentAttempt/tracking identity before mutation. Any drift rolls back the full batch.

Created Shipment facts:
- `shipment_status=shipped`
- warehouse resolved from exact source warehouse code (`DE` for this source)
- `warehouse_selection_source=historical_exact`
- `carrier=DHL`
- source-exact DHL service
- `carrier_selection_source=historical_exact`
- source-exact tracking number
- source outbound time normalized to UTC
- `handled_by=NULL`
- audit/provenance note containing provider/source checksum/source row/reference

## Explicit non-effects
- do not change Order status
- do not call `ManualSyncService`
- do not queue Woo sync
- do not create ShipmentAttempt
- do not create inventory reservations
- do not create packaging snapshots
- do not import `shipment_carrier_billing`
- do not materialize Finance costs
- do not touch the 16 KOL/historical rows
- no UI/routes/menu/scheduler changes
- no schema migration

## Idempotency / conflict rules
- exact already-backfilled canonical Shipment -> skip/noop only when all source-backed Shipment facts match
- any different existing Shipment -> review `existing_shipment_conflict`
- any ShipmentAttempt -> review `shipment_attempt_exists`
- tracking owned by another Shipment -> review `tracking_conflict`
- KOL/historical source reference -> review `historical_scope_excluded`
- duplicate/ambiguous source rows -> review; never merge tracking identities

## Acceptance criteria
- focused parser/preview/apply tests cover ready, excluded historical, wrong Order identity, existing Shipment, ShipmentAttempt, tracking conflict, bad time, warehouse mismatch and idempotent replay
- dry-run is zero-write
- apply exact guards fail closed
- apply creates only canonical Shipment rows and audit events
- no operational/Woo/Inventory/Finance side effects
- Logistics relevant regression has zero new failures
- Production write remains disabled after merge/deploy until a separate Owner data-write gate

## Rollout
1. implement + local/high-risk verification
2. merge under continuous authorization if regression delta is zero
3. run approved read-only 27-row dry-run against Production without `--apply`
4. lock exact source/plan/count snapshot
5. separate Production code deploy gate if current runtime does not contain the command
6. separate Production data-write gate
7. after backfill apply, rerun P6B Tianyun carrier-bill dry-run; only then consider actual carrier-billing import

## Final verification evidence
- Focused `CustomerHistoricalShipmentBackfillTest`: 5 tests / 34 assertions PASS.
- Command discovery PASS: `logistics:backfill-customer-historical-shipments`.
- Touched PHP syntax lint PASS.
- `git diff --check` PASS.
- Relevant Logistics branch suite: 41 tests / 329 assertions with 8 inherited `ManualFulfillmentIntegrationTest` failures.
- Exact `main@68b6433b3d0f52e377a25dc96bd9e899d80369d4` comparison: 36 existing tests / 295 assertions with the exact same 8 failures.
- Relevant Logistics regression delta: 0.
- Architecture/provider/navigation branch: 27 tests / 320 assertions / 3 frozen-baseline failures.
- Exact main architecture/provider/navigation: 27 tests / 320 assertions / the same 3 failures.
- Architecture regression delta: 0.

## Real source preparation
- Original Tianyun XLSX SHA256: `2576b1ed2f1ee6c78c13e64c4f363b08567220316ccda33235c4df252a1c843d`.
- Non-Git canonical 27-row backfill source: `/tmp/tianyun-customer-historical-shipment-backfill-202601.csv`.
- Canonical source SHA256: `83bd0637788828715f5ad2e3a942a4e4975134a73a16f74535d5e2f8707d667c`.
- Source rows: 27; unique Orders: 27; unique tracking numbers: 27.
- No canonical source row is committed to Git.
- Production backfill apply remains NOT AUTHORIZED and has not been executed.

## Work Log
- Contract/source reconciliation: 0.45 effective hours.
- Implementation + safeguards: 0.80 effective hours.
- Focused/regression/baseline verification: 0.65 effective hours.
- Final source preparation/evidence: 0.25 effective hours.
- Total actual effective time: 2.15 hours.

## Merge readiness
Implementation is merge-ready when rebased/current against latest `main`, subject only to repository CI infrastructure status. Production code deployment and Production data write remain separate Owner gates.

## Latest-main reconciliation before merge
- `main` advanced from issued baseline `68b6433b...` to `6d6fc73454273894cb364e4a17e06ad84fca1ead` via PR #306 Warehouse Clearance Gift Pool V1.
- PR #306 has zero file overlap with this WP's backfill implementation surface.
- Branch rebased cleanly onto `main@6d6fc73454273894cb364e4a17e06ad84fca1ead`.
- Post-rebase focused + fulfillment/clearance verification: 21 tests / 188 assertions PASS.
- Latest-main relevant Logistics comparison: branch 47 tests / 375 assertions / 8 inherited failures; main 42 tests / 341 assertions / the exact same 8 failures. Regression delta: 0.
- Latest-main architecture/provider/navigation comparison: branch 27 tests / 320 assertions / 3 inherited failures; main 27 tests / 320 assertions / the exact same 3 failures. Regression delta: 0.
- Canonical non-Git 27-row source SHA256 remains `83bd0637788828715f5ad2e3a942a4e4975134a73a16f74535d5e2f8707d667c`.
- Production backfill apply remains disabled and unexecuted.
