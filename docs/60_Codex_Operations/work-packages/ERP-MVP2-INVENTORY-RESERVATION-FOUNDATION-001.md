# ERP-MVP2-INVENTORY-RESERVATION-FOUNDATION-001

Status: IN_PROGRESS
Program: BYL ERP / Inventory V2
Owner priority: resumed after `INVENTORY_REAL_DATA_IMPORT_V1 = CLOSED`
Execution branch: `feature/erp-mvp2-inventory-reservation-foundation-001`

## Mission

Add the canonical Inventory Reservation foundation required by MVP2 Assisted Fulfillment Intelligence.

Canonical primitives:
- `reserve`
- `release`
- `commit`
- `expire`

The foundation must mutate only canonical `warehouse_inventory.quantity_reserved` / `quantity_on_hand` and must never create a second inventory truth.

## Frozen business contract

- First release remains **one Order = one Warehouse**.
- Reservation is allowed only for canonical Orders in `pending_shipment` state.
- Every reservable Order item must have a canonical `product_id` and positive quantity.
- Warehouse must be active and have confirmed FULL inventory authority.
- Availability is `max(quantity_on_hand - quantity_reserved, 0)`.
- Reserve fails closed when any required Product is unavailable or insufficient.
- Reserve increments only `quantity_reserved`; it does not reduce on-hand.
- Release / expire decrement reserved only.
- Commit decrements both on-hand and reserved exactly once.
- Replays with the same reservation key and same payload are idempotent.
- Same key with changed payload is a hard conflict.
- One Order cannot have two active reservations concurrently.
- Inventory writes and Reservation state transitions are transactional and row-locked.
- Every real mutation writes Inventory Adjustment evidence and Audit evidence.

## Schema

Add canonical append-only reservation identity/state tables:
- `inventory_reservations`
- `inventory_reservation_items`

No duplicate quantity column is permitted.

## Explicit exclusions

This WP does **not** implement:
- automatic warehouse assignment;
- split shipment;
- stock-status thresholds / alerts;
- replenishment forecasting;
- procurement auto-trigger;
- packing / chargeable weight;
- shipping cost / carrier decisions;
- WMS connectors;
- automatic fulfillment;
## Acceptance gates

- focused Reservation tests PASS;
- existing Inventory batch-sync regression PASS;
- existing warehouse recommendation regression PASS;
- migration up/down is structurally valid;
- no unresolved Product identity may be reserved;
- insufficient multi-product reservation rolls back atomically;
- release / commit / expire are idempotent on replay;
- `quantity_reserved` never becomes negative;
- commit never makes `quantity_on_hand` negative;
- reservation requires confirmed FULL inventory authority;
- permissions remain `inventory.operate`;
- no Production mutation in this WP before the Owner Production Gate.

## Delivery flow

Implementation → focused tests → reconcile latest `origin/main` → PR/CI → merge → integration regression → `READY_FOR_PRODUCTION`.

Production migration/deployment remains separately gated by the frozen BYL ERP release contract.
