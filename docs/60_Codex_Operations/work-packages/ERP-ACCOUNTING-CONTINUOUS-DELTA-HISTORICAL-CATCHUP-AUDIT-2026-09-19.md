# Accounting Continuous Delta Historical Catch-up Audit — 2026-09-19

Status: PASS / READ-ONLY AUDIT CLOSED

## Scope

Bounded read-only audit for the period before PR #373 normalized Logistics MySQL write sessions to UTC.

Audit window:
- start: 2026-09-10 00:00:00
- end: 2026-09-19 02:27:45
- Production release at audit: post-PR #373
- no Production data writes in this audit

Purpose:
- identify customer Orders whose Shipment timestamps were written during the old MySQL SYSTEM-timezone Logistics path;
- include fulfillment-derived child Shipments by resolving to the parent customer Order;
- exclude `historical_logistics` external-reference Orders using the same boundary as Continuous Delta;
- run current Accounting materializer previews for Logistics-sensitive stages;
- distinguish `apply` from `noop` and legitimate business-evidence blockers.

## Population

- Shipment-linked audit Order IDs: 1,846
- Eligible customer Orders: 1,846
- Main change concentration:
  - 2026-09-14: 1
  - 2026-09-15: 1,002 unique audit Orders
  - 2026-09-16: 727
  - 2026-09-17: 115
  - 2026-09-19: 1

The large 2026-09-15..17 population is primarily historical Shipment recovery/backfill activity, so it was intentionally included rather than sampled.

## Read-only preview result

| Stage | Apply | Noop | Blocked | Interpretation |
|---|---:|---:|---:|---|
| warehouse_cost | 0 | 142 | 1,704 | no missed materializable warehouse cost |
| last_mile_estimate | 0 | 1 | 1,845 | no missed eligible fallback estimate |
| last_mile_actual | 0 | 142 | 1,704 | no missed complete carrier-billing materialization |
| overseas_handling_actual | 0 | 1,844 | 2 | no missed handling materialization |

**Total current materializable Logistics-sensitive facts missed by the old window semantics: 0.**

## Blocker evidence

Warehouse cost blockers:
- `warehouse_storage_allocation_missing`: 1,703
- `stronger_or_different_existing_fact`: 1

Last-mile estimate blockers:
- `p82_automatic_estimate_age_blocked`: 1,658
- `actual_carrier_billing_available`: 142
- `china_direct_no_fallback_allowed`: 43
- `overseas_warehouse_required_for_fallback`: 2

Actual last-mile blockers:
- `carrier_billing_missing_on_shipment`: 1,702
- `complete_carrier_billing_evidence_required`: 1
- `shipment_warehouse_missing`: 1

Overseas handling blockers:
- `shipment_warehouse_missing`: 1
- `stronger_or_different_existing_fact`: 1

These blockers are missing/insufficient business evidence or stronger-fact conflicts. They are **not** classified as Continuous Delta misses and must not be auto-filled.

## Canary exception already resolved

Order 7008 / ERP `007-0909-c40405` was the single live P9 canary that exposed the timezone mismatch:
- FIFO consumption: 1 unit
- storage allocation: CNY 8.00
- warehouse_cost exact-gated materialization: EUR 1.03
- replay after apply: noop
- PR #373 fixed future ShipmentService MySQL writes by setting the session to `+00:00` before Logistics write transactions.

This audit found no second Order requiring the same catch-up action.

## Closure

P0-1 Historical Continuous Delta catch-up audit: **PASS / CLOSED**.

No blanket replay is required.
No additional Production cost materialization is authorized by this audit.
No ERP Changelog update.
