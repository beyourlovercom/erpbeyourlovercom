# ERP-LOGISTICS-HISTORICAL-IMPORT-M1-001

STATUS: ACTIVE / DESIGN FROZEN / IMPLEMENTATION AUTHORIZED / PRODUCTION WRITE NOT AUTHORIZED
EXECUTION_PROVIDER: US_CODEX_CLI
SYSTEM: BYL ERP
BASELINE: origin/main@50afe18d0e6c30907fe404d0edbfa5e5ee44647e
BRANCH: feature/erp-logistics-historical-import-m1-001
WORKTREE: /Users/a/ai/007/worktrees/erp-logistics-historical-import-m1-001
DEPENDENCY: Logistics OS V1 / PR #189 MERGED

## Purpose
Recover real historical carrier/tracking evidence from the canonical manual KOL source `0902-网红订单.xlsx` without corrupting current Logistics active fulfillment truth.

## Real-source audit facts
- Canonical source: `0902-网红订单.xlsx`, sheet `工作表1`.
- File size: 58,827,902 bytes. Current recovered workbook SHA-256: `164fe6242ec612d024bd521623879b0d3ede7afef2054347a8bce07f8f9d8c41`.
- 1,077 data rows; 971 rows have source Order No.; 964 rows have non-empty Tracking field; 482 rows have non-empty Carrier field.
- Conservative source scan found 820 rows with exact Order No. + one plausible tracking token + non-cancel status + no complex replacement/transfer text; 352 of these also have an explicit simple source carrier.
- 65 rows contain multiple plausible tracking candidates; 72 non-empty tracking cells contain no safe tracking candidate.
- Five source Order No. values occur twice with different tracking evidence. No tracking candidate is duplicated across source rows in the recovered workbook.
- Source Tracking cells include cancellations, no-shipment notes, waiting states, replacement numbers, transfer numbers, query URLs and multi-leg numbers; raw text cannot be bulk-copied into current Shipment.

## Existing ERP evidence
- Production has 961 canonical KOLs from the prior activation flow, but zero historical `kol_collaborations`.
- Existing KOL reconciliation staging has 1,077 replay rows, but that replay intentionally omitted Carrier/Tracking/Warehouse and did not backfill `matched_kol_id`.
- Exact source-row -> KOL mapping is nevertheless recoverable without fuzzy matching through `kol_import_preview_candidates.source_row_ids` plus the two applied `kol_import_activation_items` batches: first activation created 919 KOLs; second activation created the remaining 42.
- Source `KOLxxxx` Order No. is not currently present as `orders.internal_order_number` / `external_order_number`; Historical Import must not assume an existing Order row.

## Frozen architecture
1. Add an additive Logistics Historical Import workspace at `/logistics/historical-import`; do not modify current Logistics queue semantics.
2. Add a server-side XLSX reader that reads only logistics-safe columns from the source workbook: source row, Status, Stock/Warehouse, Carrier, Tracking No., Input Time, Order No., SKU and Quantity. Do not persist/read shipping address, email, phone, social URL or payment PII for this WP.
3. Persist preview/import evidence in dedicated additive tables. The original XLSX bytes are not stored in ERP or Git.
4. Resolve KOL identity only from the existing exact P2/M4 source-row activation graph. No name/email/address/order fuzzy matching.
5. Classification: `ready`, `review`, `skip`. Ready requires exact source Order No., exactly one plausible tracking candidate, non-cancel/no-shipment state, no replacement/transfer ambiguity, and exact KOL resolution. Carrier may be source-missing; preserve it as null rather than infer.
6. Preserve `source_status_raw`, `source_carrier_raw`, `source_warehouse_raw`, `source_tracking_raw`, normalized tracking candidate, source row, file checksum and evidence hash.
7. Do NOT map KOL collaboration status (`2.1 Shipping`, `2.5 Published`, `3.0 Finished`) to current Shipment lifecycle status. They remain raw historical evidence.
8. Do NOT create/update current `shipments` in M1 preview/evidence commit. Do NOT create customer Orders. Do NOT overwrite Logistics OS active truth.
9. A later separately gated projection may create canonical `kol_fulfillment` Order/Collaboration/ShipmentAttempt only after exact review rules are accepted.
10. Import commit is idempotent by source checksum + source row + normalized tracking/evidence hash and emits canonical audit evidence.

## UX / permissions
- Preview + evidence commit require `logistics.operate`; view requires `logistics.view`.
- Page shows source checksum, rows, ready/review/skip counts and a paginated row table with source Order No., KOL link when resolved, raw carrier, normalized tracking, warehouse, classification and reason.
- Commit writes only `ready` evidence rows. Review/skip rows never silently write canonical fulfillment state.
- Production commit remains feature-gated OFF until explicit Owner production-write authorization.

## First real-data UAT
- Use 10 real, non-PII clean logistics rows derived from the recovered 0902 workbook in an untracked/local UAT fixture.
- Prove parse/classification/idempotency and row->KOL exact mapping without writing Production.
- Owner reviews the historical-import page before any merge or Production write.

## Exclusions
- No provider tracking API/polling.
- No automatic carrier inference from tracking prefix.
- No parsing addresses/PII.
- No historical shipping-cost/accounting import.
- No active Shipment mutation.
- No Production write/deploy without a separate Owner gate.
