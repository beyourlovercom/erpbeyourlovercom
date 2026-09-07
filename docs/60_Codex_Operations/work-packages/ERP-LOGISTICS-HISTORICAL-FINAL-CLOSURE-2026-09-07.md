# BYL ERP — Historical Logistics Final Closure

**Date:** 2026-09-07  
**Track:** ERP 支线 3 — Historical Logistics  
**Status:** CLOSED / PRODUCTION ACCEPTED / FINAL RECONCILIATION PASS  
**Canonical source:** `0902-网红订单.xlsx`  
**Source SHA256:** `164fe6242ec612d024bd521623879b0d3ede7afef2054347a8bce07f8f9d8c41`

## 1. Closure boundary

This record closes the Historical Logistics line after R0–R7. It is record-only and does not mutate Production data.

The closure explicitly preserves these rules:

- no replay of already CLOSED R1–R4;
- no R5 re-Apply;
- no Active Shipment creation;
- no historical `shipped_at` backfill;
- no carrier inference from raw historical agent text;
- no warehouse guessing for unresolved evidence;
- no fuzzy Product / SKU / KOL / Order identity;
- Historical Orders and Attempts remain excluded from live Logistics / Automatic Fulfillment / Inventory shipped-demand.

## 2. Production truth at final reconciliation

Final reconciliation was repeated against the then-current Production release after parallel Accounting deployment:

- `origin/main`: `6ac34da0abaaa8cfb8ed4539f5cf8ffe7c35f035`
- Production current: `accounting-v11b-utc-hotfix-20260907-002948-6ac34da0`
- `/up`: 200
- `/login`: 200
- R5 global Apply gate: OFF

Historical state:

| Invariant | Final Production value |
| --- | ---: |
| Source rows | 1077 |
| Historical evidence | 1065 |
| Historical external refs | 886 |
| Historical Orders | 886 |
| Original Historical ShipmentAttempts | 765 |
| R5 Rescue ShipmentAttempts | 244 |
| Historical ShipmentAttempts total | 1009 |
| Historical Shipments | 0 |
| R4 Rescue Orders | 121 |
| R4 Permanent Review | 20 |
| R5 Apply audits | 1 |
| Duplicate historical identity groups | 0 |
| Duplicate historical tracking groups | 0 |
| Attempt sequence mismatch groups | 0 |

## 3. R5 acceptance

R5 was already completed through the Owner UAT Control Center before this final closure reconciliation.

Frozen manifest:

`cf5cde39159dc26cd2632d6094568faa97f49cd3593b3c766de4db075d2336fe`

Audit chronology:

- Web Preview: `2026-09-07 05:56:33 UTC`
- Database Backup #39: started `06:08:25`, READY `06:08:32 UTC`
- Web Apply: `2026-09-07 06:08:36 UTC`
- Apply audit count: exactly 1

Accepted R5 materialization:

- 244 / 244 projection rows linked to ShipmentAttempts;
- `shipment_id = null`: 244;
- `shipment_status = historical_evidence`: 244;
- `shipped_at = null`: 244;
- `carrier = null`: 244;
- `service = null`: 244;
- warehouse `FACTORY`: 243;
- unresolved `warehouse_id = null`: 1;
- unique tracking: 244;
- exact per-Order attempt sequence: PASS;
- Active Shipment delta: 0;
- Order delta from R5: 0;
- historical external-ref delta from R5: 0.

Therefore:

> R5 Historical Rescue ShipmentAttempt Projection — CLOSED / 244 HISTORICAL ATTEMPTS MATERIALIZED / IDEMPOTENCY CONTRACT PRESERVED / PRODUCTION ACCEPTED

## 4. R6 permanent exceptions closure

The 20 Rescue Order Projection permanent Review rows remain fail-closed:

- `duplicate_source_order_no`: 10
- `sku_unmatched_or_invalid`: 7
- `quantity_not_positive_integer`: 2
- `order_date_future_requires_review`: 1

No automatic resolution is authorized for these rows.

The original 145 Skip rows remain explained:

- `missing_order_no`: 106
- `no_tracking_candidate`: 24
- `cancelled_or_no_shipment`: 15

In addition, 3 original Review rows with `kol_unresolved_exact_graph` remain intentionally Owner-accepted manual unresolved rows with no Historical Evidence. No fuzzy KOL reconciliation is permitted.

Therefore:

> R6 Permanent Exceptions Closure — PASS / FAIL-CLOSED / NO UNAUTHORIZED INFERENCE

## 5. R7 1077-row lineage reconciliation

The 1077 source rows reconcile by source-row lineage, not by adding different entity-layer totals.

### Source classification layer

- 788 initial Ready source rows;
- 144 initial Review source rows;
- 145 Skip source rows;
- total = 1077.

### Evidence layer

- 788 Ready rows -> 788 Historical Evidence rows;
- 141 of the Review rows -> 277 Rescue Historical Evidence rows;
- 3 Review rows -> intentionally unresolved, no evidence;
- 145 Skip rows -> no evidence;
- evidence-bearing source rows = 929;
- no-evidence source rows = 148;
- `929 + 148 = 1077`;
- Historical Evidence total = `788 + 277 = 1065`.

### Original Order materialization layer

The 788 initial Ready evidences produced:

- 765 Historical Orders;
- 23 Order Materialization Review rows:
  - 22 `sku_unmatched_or_invalid`;
  - 1 `quantity_not_positive_integer`.

### Rescue Order layer

The 141 Rescue source rows produced:

- 121 Historical Rescue Orders;
- 20 permanent Rescue Order Review rows.

Thus Historical Orders total:

`765 + 121 = 886`

### ShipmentAttempt layer

- original Historical ShipmentAttempts = 765;
- R5 Rescue ShipmentAttempts = 244;
- total Historical ShipmentAttempts = 1009.

These are child logistics evidence entities and are not added to source-row totals.

Final lineage conclusion:

- unexplained source row = 0;
- duplicate identity = 0;
- duplicate tracking = 0;
- fuzzy mapping = 0;
- Active Shipment contamination = 0;
- live Logistics contamination = 0.

Therefore:

> R7 Final 1077 Reconciliation — PASS / FULL LINEAGE EXPLAINED / ZERO ACTIVE LOGISTICS CONTAMINATION

## 6. Final status

ERP 支线 3 Historical Logistics is closed.

Final canonical status:

> **ERP LINE 3 — HISTORICAL LOGISTICS — CLOSED / R0–R7 COMPLETE / PRODUCTION ACCEPTED / 1077 SOURCE ROWS FULLY RECONCILED / 1009 HISTORICAL SHIPMENTATTEMPTS / ZERO ACTIVE SHIPMENT CONTAMINATION**

No further Historical Logistics mutation is planned under this line. Any future correction must open a new bounded work package with its own Preview → Freeze → Owner UAT → Apply → Reconcile gate.
