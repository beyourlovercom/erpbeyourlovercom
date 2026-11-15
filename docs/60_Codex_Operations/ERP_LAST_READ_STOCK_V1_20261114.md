# ERP_LAST_READ_STOCK_V1 — Owner Frozen Scope

Scope: R0–R3 isolated ERP Dev branch, first MorePlus US-LA only.
R4: 007windows browser UAT, GitHub PR review, separate Owner approval for any Production release.

## Business contract

1. Only in US-LA/MorePlus, rename the visible Standard QoH column to 上次读取库存数. Other warehouses retain original standard_qoh fields and editing.
2. The reference is the latest independently read provider PHYSICAL stock, NOT the ERP quantity_on_hand mirror or provider availableQuantity.
3. A successful signed ShipOut GET persists raw reference, timestamp and source BEFORE ERP main-inventory apply. An ERP apply failure must not erase the read.
4. API errors preserve the previous raw/net reference and timestamp, marking it historical. A null/unread field is NOT zero.
5. Only exact signed OMS Shipped evidence can trigger an automatic reference decrement: Tracking entry, Dede approval, OMS submission/ACK and unverified manual completion are not sufficient.
6. Unique provider order/outbound/SKU evidence is idempotent; repeated confirmations must never double subtract.
7. Shipment events strictly newer than provider read reduce the reference; events at or before the snapshot are review-only (API stock effective time may be ambiguous).
8. A new signed read replaces the reference baseline, subtracting only proven later events. Never replay prior already accounted shipments.
9. Ambiguous aliases/unverified SKU identities remain raw evidence, never misassigned to an ERP product.
10. MorePlus authoritative real physical/available mirror, reservation logic, historical FIFO/P9 and Woo/OMS APIs are unchanged.

## Isolated data

warehouse_stock_read_references: current last-known stock per provider SKU.
warehouse_stock_read_events: append-only read/failed read/apply result evidence.
warehouse_stock_read_adjustments: append-only, uniquely keyed real outbound evidence with result.

No provider inventory POST/PUT, no scheduled jobs and no new OMS dispatches are added.

## QA acceptance examples

Signed read 80 + ERP inventory 35 => reference 80 and ERP remains 35 before main sync.
Read 80, ERP mirror audit/write fails => ref 80, status read_success_apply_failed, main ERP still 35.
Next GET fails => preserve 80 with historical-read warning.
True verified outbound qty 3 after read => 77; duplicated exact outbound => still 77.
New signed read 75 => 75, not 72.
Before-read or ambiguous shipped timestamp, missing baseline, alias conflict, overdraft => never auto decrement.
Explicit source zero => 0; never read => dash.
All other warehouses keep Standard QoH unchanged.

## Out-of-scope

Reference imports for other warehouses, automated source-backed shipment reversal integration, forecasting and new dashboards remain separate tasks.
The initial verified OMS outbound event uses the existing exact signed Shipped evidence and checked ERP order lines, and records uncertain cases for review.

## Release gate

GitHub PR + SQLite/MySQL8 CI, 007windows Chrome isolated Dev visual UAT, then separate Owner-approved controlled Production deployment with backups, additive migration, readbacks and rollback.
