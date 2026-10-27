# ERP Order Detail UX V1.2 — Collapsible, sortable business panels

Date: 2026-10-27. Owner requested WordPress-style top-right collapse and vertical drag for each order detail business module, and moving low-frequency packaging/weight immediately above internal notes.

## Frozen contract

- Keep the existing official ERP order detail, V1 floating nav and V1.1 compact View/full Edit modes. No WooCommerce-themed overhaul, new backend APIs or loss of operational evidence.
- The order header and identity strip are fixed. Nine sortable business modules are 履约状态, 辅助标记, 库存分布, 订单信息, 收件与地址, 订单商品, 包装与重量参考, 内部备注, 操作审计.
- Each module header has a grip (drag/drop onto another module; Alt+Up/Down keyboard equivalent) and a chevron fold toggle. The left section menu follows custom order.
- 包装与重量参考 moved physically and in default navigation directly between 订单商品 and 内部备注, and is folded by default because it is low frequency.
- Inventory is one wrapper for all SKU warehouse cards and related allocation controls. It moves/collapses as one, never splits individual SKU cards. Conditional reopen allocation control remains visible.
- Collapse is display-only (does not destroy form state). Navigating to collapsed panel reopens before scrolling. Backend validation errors reopen their panels. No automatic order or stock writes from display operations.
- Per-staff, per-browser, per-origin preferences are kept in localStorage keyed by authenticated staff user ID. Invalid or unavailable storage safely uses defaults. No new database/API or shared staff preferences.
- Desktop-only staff interface and unchanged ERP global shell, warehouse, packing, shipment, finance, roles and soft-delete logic.

## Executed technical evidence

- Same PR #528 and development branch. Only Vue order detail component, its tests and these docs changed. No schema, backend controllers or order data changed.
- Scoped Vue regression 67/67 PASS, including 5 specific fold/drag/reorder/group/persistence/draft tests.
- TypeScript vue-tsc PASS; production Vite build PASS; Git diff --check PASS.
- Isolated production-backend-parity PHP tests: 38 tests / 399 assertions PASS (Order Trash, fulfillment, inventory reservation, route/permissions).
- Isolated Dev site at http://100.94.52.109:18687/customers/orders/1 — source file checksum equals build worktree, login HTTP200, unauthenticated order route redirects 302; synthetic SQLite only.
- Older broader full Vue suite had six pre-existing WarehouseClearance/GiftPool, WarehouseCostCenter and Orders/Index test failures (608/614 passed), reproduced unchanged on older original commit. This scoped UX is not a reason to alter those unrelated files.

## Hard gates still open

- Real authenticated browser 1440px visual/interactions: nav, grab/drop, collapse, form fields preservation, audit, inventory, warehouse and fulfillment modals, console error readback. Browser automation login in prior context was rejected by connector safety check, so never equate component/PHP passes to live browser PASS.
- Owner live Dev PASS. CI on new pushed HEAD. Independent production release authorization. At production release, re-read live symlink/source, avoid overwriting concurrent changes, build rollback-safe release and Owner final production visual PASS.
- No production deployment or production database writes have occurred.

Status: Implemented/isolated technical validation PASS; browser/Owner UAT and deployment not closed.
