# ERP-INVENTORY-V2-ASSISTED-ALLOCATION-001

STATUS: IMPLEMENTED / VERIFICATION IN PROGRESS
OWNER_AUTHORIZATION: Owner approved PR #189 merge and explicitly authorized the Inventory V2 continuation: “采用推荐 → Reservation → Shipment Draft”, then replenishment / Safety Stock.
BASELINE: main after Logistics OS V1 closure.

## Scope

Connect the existing safe Warehouse Recommendation projection to the merged Inventory Reservation foundation without creating a second fulfillment truth.

Canonical flow:
1. Pending Order receives server-side warehouse recommendation.
2. Operator clicks “采用推荐并预留库存”.
3. Server recomputes recommendation and rechecks authoritative inventory.
4. Inventory Reservation is created or idempotently reused.
5. Canonical Shipment draft stores the recommended warehouse with `warehouse_selection_source=recommended`.
6. Operator may continue editing carrier/service/tracking draft fields.
7. On confirmed shipment, the active matching reservation is committed atomically; QOH decreases and reserved quantity returns to zero.
8. If operator manually changes warehouse before shipment, the recommendation reservation is released before the manual Shipment draft is saved.

## Invariants

- `orders + shipments` remain canonical fulfillment truth.
- Reservation does not create a second order or shipment.
- Recommendation is always recomputed server-side on adoption; client warehouse id is not trusted.
- Only warehouses with confirmed Full Snapshot authority can be reserved.
- An active reservation cannot be shipped from a different warehouse.
- Same-order/same-payload retries reuse the active reservation and do not double-reserve.
- Manual fulfillment without a reservation remains allowed in Assisted Fulfillment V2.
- Logistics operators may execute reservation primitives required by fulfillment; view-only users remain blocked.
- No Production deployment is authorized by this Work Package.

## Acceptance

- Adoption creates one active reservation and one recommended Shipment draft.
- Double adoption is idempotent.
- Same-warehouse draft edits preserve the recommendation reservation.
- Manual warehouse override releases the active recommendation reservation.
- Shipping a recommended draft commits the reservation and consumes QOH exactly once.
- Shipping through a different warehouse while a reservation is active fails closed.
- Existing Logistics V1, Inventory Reservation, Warehouse Recommendation, Inventory Sync and frontend regression remain green.
