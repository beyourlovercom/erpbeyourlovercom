# ERP Global Grid V1.2 — China domestic nine-view independent releases

Owner instruction (2026-11-10): migrate ALL nine views under /logistics/china,
but each view has an independent implementation, automated business regression,
controlled Production publish, and Owner PASS gate before enabling the NEXT
view by default.

Shared AG Grid remains ONE component, no per-view forks. Authorized fixed
right-pinned operations, left selection, existing single bottom footer,
search, 20/50/100 pagination and original table fallback are reused.
No Shipment/OMS/Tracking/Sync state changes and no role changes.

| Order | View key | Label | Status |
|---|---|---|---|
| 1 | review | 待审核 | Owner PASS; Production main 14ceb0d0 |
| 2 | stockout | 缺货单 | Independent R4-2 candidate; CI/browser/Production readback before Owner UAT |
| 3 | pending | 待发货 | FROZEN: exact carrier/service/Tracking/confirm shipping parity required |
| 4 | today | 今日订单 | FROZEN: original order download + carrier return files |
| 5 | today-4px | 今日速达 | FROZEN: Speeda file and tracking return |
| 6 | today-weship | 今日纬狮 | FROZEN: Weship file and tracking return |
| 7 | exceptions | 异常订单 | FROZEN: original exception filters/actions |
| 8 | 4px | 速达订单 | FROZEN: carrier-specific controls |
| 9 | weship | 纬狮订单 | FROZEN: carrier-specific controls |

## R4-2 stockout independent release contract

- Only right-pinned 已到货-可发货 for pending warehouse shipment,
  authoritative stockout review state and original domestic.review actor
  permission. No approve/reject/ship/reship or archive row action.
- Reuse exact existing POST /logistics/china/orders/{order}/restock;
  preserve decision service original locks, audit, workflow, preflight.
  Successful restock moves to native pending workbench (not auto-shipping).
- Bounded bulk restock reuses preexisting bottom footer dropdown; only that
  domain action appears for authorized reviewers. No duplicate toolbar.
- Stockout defaults to Grid for already authorized logistics viewers;
  global_grid=0 is one-click legacy fallback. Review remains Grid.
  All other seven domestic views default legacy until their own Owner PASS.
- Single right-pinned stockout column targets width 140px, min 136 max 148.
- Synthetic Chrome/SQLite checks one button, actual pinned column and pixel
  spacing, footer selection, allowed dropdown, view switches and fallback.
  No real OMS, warehouse or Woo writes.
- Business PHP regression checks real review/restock permission and workflow,
  state transition, batch behavior, audit. No migration required.

## Owner Production acceptance

Open https://erp.beyourlover.com/logistics/china?view=stockout.
Check the only fixed-right action, compact width, Grid search/pagination,
left checkbox / one bottom footer on selection and original fallback.
Do not perform a real restock operation for visual QA without separate
specific real-order authorization. Next view waits for Owner PASS.
