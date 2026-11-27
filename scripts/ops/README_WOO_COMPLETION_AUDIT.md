# P1 — Completed order audit

`scripts/ops/audit_woo_completed_orders.php` is a read-only CLI auditor, not a cron job. It enumerates WooCommerce Completed orders and compares the linked ERP root and split shipments.

It separates:
- **woo_no_tracking_erp_no_tracking**: neither side has shipment tracking evidence
- **woo_tracking_erp_no_tracking**: Woo AfterShip has package(s), ERP lacks shipment proof
- **erp_partial_fulfillment**: some but not all active ERP segments have proof
- **erp_fulfilled**: every active segment has fulfillment proof
- **no_erp_reference**: no reliable Woo↔ERP external reference
- Flag: AfterShip ship date is later than Woo completion date

**No PII or tracking number contents are exported.** Output includes public Woo order IDs, ERP IDs, operational statuses, package counts and dates only. The report does not change completed statuses, tracking, notes, emails or sync queues.

Execute with the active Production app root to use its existing DB and Woo API config:

`ERP_RUNTIME_ROOT=/Users/a/AI/production/erp.beyourlover.com/current php scripts/ops/audit_woo_completed_orders.php --output-dir=/Users/a/ai/uat/woo-completed-audit-output`

Use `--max-pages=1` for a limited smoke test. A complete scan reports `scan_complete=true`.
