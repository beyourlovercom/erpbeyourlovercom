# Global Grid R4-3 — domestic PENDING shipping workbench

Owner authorized R3-A through R3-E contiguous progression and separate
Production Owner visual acceptance. This PR only migrates the third domestic
view, /logistics/china?view=pending, from native table to existing GlobalGrid.
The preceding review and stockout defaults remain unchanged; the six
remaining domestic views stay native until their separate acceptance gates.

## Original operation matrix (read-only feature inventory)

- Original factory-ready query is OrderProgressQuery::domesticReady:
  pending shipment, authoritative domestic_reviewed marker and not stockout.
- Authenticated shipping operator with existing logistics.operate and
  original completed business review can select carrier and service; POST
  /logistics/china/orders/{id}/carrier-service.
- Tracking input is a DRAFT POST to
  /logistics/china/orders/{id}/tracking-draft. No physical shipment,
  website sync or customer notification occurs at this stage.
- Explicit confirmation POST to /logistics/china/orders/{id}/quick-ship
  requires approved business review, pending shipment, manually confirmed
  carrier + service and nonempty tracking. Original server-side validation,
  idempotency and audit policies are untouched.
- Review users can reopen approval; logistics super_admin remains read-only
  for shipping (original elevated-role security policy preserved).
- For European destinations, the SKU cell retains the original read-only
  69-barcode endpoint, now shown in a fixed-position floating detail panel.
- Original explicit website-sync button retains its existing eligibility
  and LogisticsTrackingPushAccess gate, reusing its original parent handler.
- Top-level download待发货批量表, XLS/XLSX upload, provider detection,
  Preview and Apply, multiple-provider templates, and original error
  handling are preserved outside the Grid. No second table or new upload
  engine was built.
- Left checkboxes, single bottom bulk menu, search, pagination, per-view
  preferences, compact small-list height, and native fallback (?global_grid=0)
  are supplied by the previously approved shared Grid.

## R3-A through R3-E

- R3-A: inventory and exact original domain action/permission mapping.
- R3-B: compact pinned-right actions: 写物流, 确认发货 and, when
  review-authorized, 重新审核. Shipping operator width 170px; reviewer
  252px. A role-dependent eligible website-sync action is only rendered
  if the original native-table rule permits it.
- R3-C: one on-demand 354px body-teleported floating form for carrier,
  service and Tracking, with explicit save buttons. No Grid row expansion,
  no extra native table, no navigation. Original parent Inertia handlers
  own the writes; no duplicate backend logic.
- R3-D: preserve all existing carrier export, XLS/XLSX upload,
  Preview/Apply and barcode actions; only UI adapts.
- R3-E: PHP authorization and business-state feature regression,
  executable Chrome visual regression, synthetic carrier -> tracking-draft
  -> explicit-ship using disposable SQLite database only, independent PR,
  guarded merge/release and Production read-only verification.

## Exit criteria

1. Review/stockout remain approved Global Grid; view=pending defaults Grid.
2. Other six views default original native table.
3. Non-shipping super_admin cannot ship via pending Grid; operator can.
4. Confirm ship disabled until existing carrier/service and saved Tracking.
5. No Woo/OMS write is invented by the table migration.
6. Right operation cell compact, 8px button gap, anchored overlay never
   hidden behind next row, document-body popover, no permanent input.
7. Original carrier provider buttons and download/upload/preview/apply
   remain available; no duplicated tables.
8. Explicit global_grid=0 fallback and all column preferences.
9. Production visual acceptance by Owner required before fourth view.
10. Do not use Production real orders for synthetic write QA.

Rollback: immutable previous Production release; no schema migration.
