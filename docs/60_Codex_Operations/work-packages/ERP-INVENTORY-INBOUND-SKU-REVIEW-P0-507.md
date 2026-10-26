# ERP Inventory OS — Inbound SKU Review P0 (#507)

**Authority:** Owner approval 2026-10-26 to fix the missing inbound review operation, Dev-test, then pursue controlled release and real warehouse confirmation.
**Execution provider:** GPT_WEB via US self-hosted OAuth MCP + US Mac mini.
**Branch/worktree:** fix/inventory-inbound-sku-review-p0-507 / /Users/a/AI/007/worktrees/inventory-inbound-sku-review-p0-507.
**Base:** origin/main@71637bf43b08c3dcb6f3f5c5dc8b2454e4a0efba.
**Scope:** one branch / one PR / Inventory OS inbound review only.
**Related:** US-SM inbound #375, FIFO #381, inventory table cell editor #505 (already merged).

## Repro / root cause

Production screenshot from /inventory/us-3sm: batch IB027260912RS-1 at received with one unresolved inbound item Pillow mixed, source SKU unknown, quantity 20. Existing UI displays 待复核 but offers no review/remap, and lifecycle correctly blocks ready and stock activation.

## Approved design / implementation

- Existing 在途 / 入仓 batch table gains a small row-local 复核 SKU action only for unmatched rows on received batches, conditional on inventory.operate.
- Existing Product OS SKU selector reused. The operator must choose an existing active canonical Product and explain the evidence (including color/model). Do **not** invent SKU, auto-assume that 20 mixed pieces share one variant, or edit master Product.
- Backend PATCH is authenticated and inventory.operate guarded, strictly warehouse/batch/item scoped, batch-and-item row-locked, rejects mismatched state, stale item, repeat, invalid/inactive product, and preserves original manifest row, quantity, source name, evidence and source file.
- A canonical product mapping is saved with an audit trail; received remains no-stock-write. Independent ready activation remains transactional, fail-closed and exactly once.
- Do not create customer Shipment, trigger Woo, modify Accounting, or directly edit Production inventory. If 20 physical pieces have multiple variants, this single-row mapping **must not** be used; SKU-split contract requires separate Owner-confirmed evidence and controlled scope.

## Verification / gates

- PHP service/controller/test lint and git diff --check.
- Backend targeted WarehouseInboundReviewTest + existing WarehouseInboundLifecycleTest (8 tests / 50 assertions PASS).
- Full Inventory feature suite 65 tests / 484 assertions PASS.
- Vite build PASS; TypeScript vue-tsc PASS; WarehouseInboundReview Vue tests 2/2 PASS.
- Dev / Owner live UAT still required; do not claim OWNER_UAT_PASS from test logs.
- main merge only when mandated gates complete. Formal Production deploy remains an explicit release gate.
- Real US-SM 20-pillows mapping and 131 total-unit inventory activation require confirmed physical SKU evidence and owner acceptance; no blind Production write.

**Production state:** not altered by this work package.
