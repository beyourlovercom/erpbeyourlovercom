# ERP ↔ WooCommerce Completed anomaly audit — 2026-11-11

## Scope and evidence
Read-only scan against Production ERP and WooCommerce v3, based on live order references, ERP shipments, and Woo AfterShip tracking metadata. No order, tracking, note, mail, outbox, or shipping state was modified.

- 6,022 Woo orders in Completed, across all 61 API pages.
- 5,995 matched existing Woo↔ERP order reference.
- 41: no recognized tracking on either ERP or Woo AfterShip; highest-priority manual review.
- 4,088: Woo has AfterShip package(s) but ERP lacks qualifying shipment evidence; NOT proof of non-shipment. Historic migration/backfill is a likely contributor.
- 1,866: ERP has valid carrier, tracking and shipped/completed/exception state for all active segments.
- 27: no reliable ERP mapping by external reference.
- 68: AfterShip ship date is later than Woo completion date; time-zone differences and intentional later parcels must be considered.

Both-missing group order-note attribution (41 read-only note API requests):
- 35 latest relevant status-change notes authored by Vita.
- 5 by aff andy.
- 1 by WooCommerce.
- 31 explicitly said bulk edit; 10 indicated another transition mechanism.
- These are the accounts recorded in Woo notes, not proof of who physically operated the account.

Representative Woo #23177 / ERP #542:
- ERP order pending_shipment, no ERP shipment tracking.
- Woo completed, no recognized AfterShip tracking.
- Woo note: bulk edit Processing → Completed by Vita, 2026-11-02 02:31:14 in Woo API's returned time.
- Completed order notification sent.

## Root-cause determination
1. Proven: WooCommerce's native bulk status editing directly marked orders Completed, without passing through ERP's carrier/Tracking/shipment gates. Order notes explicitly record the bulk edit. ERP Completed Outbox contains no corresponding writes for representative cases.
2. Contributing architecture gap: ERP's 30-minute Sync OS does not own or police all independent Woo admin status changes.
3. Separate discrepancy: many legacy Woo AfterShip records are missing from canonical ERP shipments. Their actual warehouse fulfillment must be reviewed before correction.
4. Existing explicit override: the ERP manual split-parent override can intentionally mark a mother order Completed after one verified package. This is a distinct, already-authorized workflow and not the source proven for the sampled bulk-edit incidents. Altering A/B/C business semantics is outside this P0/P1 change.

## Current P0/P1 boundaries
- P0 WordPress-side standalone guard in beyourlover-web-v3 repo: remove bulk Complete UI for HPOS/legacy, refuse completion without AfterShip proof, preflight Woo API status mutation, block non-admin direct UI completion. Dev integration UAT and WWW manual install required.
- P1 read-only scanner scripts/ops/audit_woo_completed_orders.php is repeatable. CSV and JSON stored on US Mac mini at /Users/a/ai/uat/woo-completed-audit-output/. Reports contain operational order IDs, package counts and status dates, no PII or actual tracking numbers.
- Do not mass-reset Woo completed to processing, invent ERP Tracking, backfill without shipment proof, send duplicate Completed/Customer Note emails, or modify histories merely because of mismatched status.

## Manual review order
1. 41 both-missing: prove warehouse fulfillment using warehouse logs / ShipOut / packages and order notes. Decide individually whether to complete or restore under approval.
2. 68 chronological anomalies: investigate dates and split parcels; do not assume date-only evidence proves error.
3. 4,088 Woo-only tracking: de-duplicate by Woo order, verify Woo AfterShip vs warehouse facts, backfill ERP only with verified proof.
4. 27 missing references: reconcile exact Woo↔ERP identity; do not match by SKU or guess order number.
