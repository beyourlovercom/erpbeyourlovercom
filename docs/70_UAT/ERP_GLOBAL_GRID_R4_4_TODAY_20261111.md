# R4-4 | 今日订单 Global Grid per-view release

Owner: R4-3-F 待发货 Production visual PASS (2026-11-11). Proceed to the next independent view. No new batch settings or extra header widgets. Today Grid uses compact heading/navigation and moves ordinary flow hints to a small footer, per Owner standard.

## Invariants
- Only the fourth tab, today, joins the three approved Global Grid views. Five other views keep native defaults until individually tested and Owner-accepted.
- ?global_grid=0 always returns the original table.
- Reuse GlobalGrid/OmsGrid. The original OrderProgressQuery.domesticToday scope is unchanged: reviewed pending orders plus shipments dispatched today, regardless of historical order date.
- One right-pinned action column: 重新审核 for an authorized reviewer when approved and pending; 同步官网物流 / 重新同步 only for authorized eligible shipping orders.
- Reuse SKU 69-barcode read action for EU records.
- Exactly three existing one-line shortcuts retain their original labels and URLs: ⬇️这批订单, ⏬今日速达, ⏬今日纬狮.
- Reuse the same single GlobalGrid footer for selected rows, archive and eligible tracking sync. No duplicate batch toolbar and no redundant native-batch toggle on this view; the original full-table fallback remains reachable by the header button.
- Preserve account column settings, server search, 20/50/100 pagination, right-pinned actions and legacy fallback. No role/schema change.
- ERP tracking sync is separate from physical shipping and is owned by the existing sync module.

## Gates
- PHP: exact same order results with Grid and original fallback; today XLSX and SKU-line parity; other five views unchanged.
- Vue: permissions for right-pinned retry, review, noneligible rows and one batch footer.
- Chrome disposable browser: three shortcuts, EU barcode, row selection, pinned actions and fallback.
- Full CI; read then-current Production source fingerprints; preserve rollback and other parallel releases. Stop if current pointer drifts.
- Owner visual PASS of fourth view before migrating the fifth.

## No-go
- No real outbound or shipping POST in Production tests.
- No new import endpoint, template structure change or new business state transitions.
