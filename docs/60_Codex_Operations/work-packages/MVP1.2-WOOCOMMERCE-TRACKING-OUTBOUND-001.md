# MVP1.2-WOOCOMMERCE-TRACKING-OUTBOUND-001

## Baseline

- Base: `main@6eb5cc595469f0efb84aa4523e13385b093c571d`
- Branch: `feature/erp-mvp1-2-woocommerce-tracking-outbound-001`
- Roadmap authority: MVP1.2 Single Tracking Outbound.

## Purpose

Push one tracking update from the existing canonical Shipment truth to the WooCommerce order referenced by the existing `(provider=woocommerce, connection_key, reference)` external reference, verify the remote state, and record retry/audit through the existing Sync Outbox foundation.

## Frozen flow

`Shipment → Woo external reference → Sync Outbox → Woo tracking adapter → remote verify → complete/fail + audit`

## Provider contract discovered from production

Production `www.beyourlover.com` uses AfterShip Tracking plugin `1.18.1`.

The plugin's own save logic writes:
- `_aftership_tracking_items`
- `_aftership_tracking_number`
- `_aftership_tracking_provider_name`

Tracking item identity is `md5("{slug}-{tracking_number}")`.
The plugin permits order-level tracking items without Woo line-item IDs. ERP does not currently persist Woo line-item IDs, so MVP1.2 MUST NOT fabricate item-level fulfillment links.

## MVP1.2 scope

- One operator-triggered tracking outbound action for one shipped Woo-sourced order.
- Reuse existing `sync_outbox`; no second retry/outbox table.
- Enqueue a deterministic idempotent intent from canonical Shipment truth.
- Claim, execute one remote write, re-read the Woo order, and verify tracking number/provider.
- Complete the outbox only after remote verification.
- Retry a failed single intent through the existing outbox transition model.
- Audit enqueue/claim/complete/fail through existing Sync Outbox audit plus a provider execution audit.
- Surface success/error through the merged Global Feedback Island.

## Preconditions and safety

- Order must have a WooCommerce external reference for the active connection.
- Shipment must be `shipped` with non-empty `tracking_number`, carrier and `shipped_at`.
- Carrier must resolve to an explicit AfterShip slug; unknown carriers are blocked, never guessed.
- Woo credentials remain environment-backed and never enter Git/database/logs.
- Production write verification requires a dedicated Woo API credential with write permission; the current read-only key is sufficient only for discovery/read verification.
- MVP1.2 does not change Woo order status; it writes tracking only.

## Explicit exclusions

- No batch/all-pending execution (MVP1.3).
- No scheduler/daemon/background orchestration (MVP1.3).
- No reconciliation dashboard or conflict engine (MVP1.3).
- No Woo order status mutation.
- No Product/KOL/Inventory schema redesign.
- No item-level partial fulfillment until Woo line-item identity is canonicalized.
- No warehouse connector work.

## Acceptance gates

1. Same Shipment truth enqueues the same outbox intent, not duplicates.
2. Remote write uses the production-observed AfterShip meta contract.
3. Remote GET verification must match tracking number and provider before success.
4. Failed remote write/verify leaves a retryable failed/pending path with audit evidence.
5. Unknown carrier mapping is blocked locally before any remote write.
6. Focused tests, Architecture, Full PHPUnit/Vitest, Typecheck, Build, diff-check pass.
7. Real Woo Gate is performed only with explicit write-capable credential and a deliberately selected test/owner-approved order.
