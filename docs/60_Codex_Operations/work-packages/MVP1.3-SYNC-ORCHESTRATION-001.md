# MVP1.3-SYNC-ORCHESTRATION-001

## Baseline

- Base: `main@a61ff189ebd61104ba2da9b426531b69ba4259d6`
- Branch: `feature/erp-mvp1-3-sync-orchestration-001`
- Roadmap authority: MVP1.3 Sync Orchestration.
- Predecessors: MVP1.1 WooCommerce Inbound + MVP1.2 Tracking Outbound are merged.

## Purpose

Add one orchestration layer over the existing canonical Woo inbound, Shipment truth, tracking outbound and `sync_outbox` state machine so operators can run selected/all-pending work, retry eligible intents, inspect failures/conflicts, and reconcile ERP tracking truth against WooCommerce without creating a second Order/Shipment truth.

## Frozen flow

`Existing provider/service truth → Sync Run → Sync Run Items → existing inbound/outbox execution → result snapshot → reconciliation/audit`

## Canonical ownership

- Order remains the canonical ERP order truth.
- Shipment remains the canonical ERP fulfillment/tracking truth.
- Order external references remain provider identity authority.
- `sync_outbox` remains outbound idempotency/retry/claim authority.
- New Sync Run / Run Item records are orchestration evidence only; they MUST NOT become business truth.
## MVP1.3 scope

- Add provider-neutral `sync_runs` and `sync_run_items` evidence tables.
- Operator can create a tracking-outbound run from selected eligible Woo orders.
- Operator can create a tracking-outbound run from all currently pending eligible Woo orders.
- Each item executes the existing `WooCommerceTrackingOutboundService`; no duplicate provider write path.
- Add a bounded retry runner for eligible pending `sync_outbox` tracking intents.
- Recover stale processing locks through the existing `SyncOutboxService::recoverStale()` contract.
- Classify run-item outcomes as succeeded, retrying, failed, conflict or skipped with exact error evidence.
- Add Woo tracking reconciliation: compare current canonical Shipment tracking/provider to current remote AfterShip tracking without overwriting either side.
- Reconciliation classifies matched, remote_missing, conflict or not_eligible.
- `/sync` gains orchestration summary/history, selected/all-pending actions, retry/reconcile actions and run detail.
- All short feedback routes through the Global Feedback Island; persistent evidence stays in Sync workspace.

## Inbound orchestration boundary

MVP1.1 single/incremental/historical inbound remains the existing canonical import path. MVP1.3 may record run-level evidence around inbound executions, but MUST NOT introduce a second inbound queue, second Woo order table or alternate normalized DTO.

## Safety

- Batch execution is bounded; no unbounded request loop.
- Same Shipment truth continues to map to the same outbox idempotency key.
- Conflict never overwrites remote tracking.
- Reconciliation is read-only.
- Unknown carrier mapping remains locally blocked before any write.
- Provider credentials stay environment-backed and never enter Git/database/run snapshots/logs.
- Run items store identifiers/status/error summaries only, not customer address/contact payloads.
- Production execution keeps the same Owner/Real Write safety gates already proven in MVP1.2.

## Explicit exclusions

- No Woo webhook inbox in this package.
- No always-on scheduler/daemon or infrastructure queue worker deployment.
- No Woo order-status mutation.
- No automatic conflict resolution or remote overwrite.
- No Product/KOL/Inventory schema redesign.
- No warehouse connector or fulfillment recommendation work.
- No item-level partial fulfillment.
- No Shopify/additional provider implementation.

## Acceptance gates

1. Selected batch executes only selected eligible Woo shipments and records one run item per selection.
2. All-pending batch is deterministic and bounded, and does not duplicate completed outbox intents.
3. Retry runner executes only eligible pending intents whose `available_at` has arrived.
4. Stale processing recovery remains bounded and audited through existing outbox transitions.
5. Conflict is terminal evidence and never causes a remote PUT overwrite.
6. Reconciliation is read-only and distinguishes matched / remote_missing / conflict / not_eligible.
7. Run summary counts equal persisted run-item outcomes and survive page reload.
8. Existing single tracking outbound and Woo inbound contracts remain green.
9. Architecture, full PHPUnit/Vitest, Typecheck, Build, secret scan and diff-check pass.
10. Owner UAT covers selected batch, all-pending/retry visibility, conflict and reconciliation before merge.
