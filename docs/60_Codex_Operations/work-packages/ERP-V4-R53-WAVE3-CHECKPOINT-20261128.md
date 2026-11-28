# ERP V4 R5.3 Wave 3 checkpoint — 2026-11-28

- Beijing time: 2026-11-28 18:17 (development checkpoint).
- Owner accepted wave 2 `c31b3bd9` at 18:01. Existing PR #918 remains the only V4 migration PR.
- Worktree: US Mac mini isolated detached worktree. Author identity: beyourlovercom (GitHub repository account). No production DB, ERP2, warehouse API or worker operation.
- Baseline: `25ca32603ca4a1c9d2ce6ee6a2fe61a41447c148` (main `d516c888` + accepted V4 changes, 3 overlapping pages replayed).
- New main observed at `e9b216747ea290c47d74e1264aaedaa3ab2b7732` (+9 commits, overlapping Domestic/Index, Oversea/Show, Orders/Master). Integrate before next release candidate; do not replace upstream business actions.

## Implemented, pending browser UAT
- Warehouse master list; warehouse stock, restock, clearance, shipment projection and monthly expense details; overdue inventory; logistics dimensions/evidence tabs; packaging record/optimization/standards primary results; Orders Trash.
- GlobalGridTemplates is a renderer adapter over the frozen GlobalGrid / OmsGrid V2, not another grid. Original Vue actions are carried through slots. Column visibility is constrained by supplied definitions. Explicit legacy account namespace import supports native restock column order/visibility. Fixed server pagination cannot offer an unsupported page size.
- Page tests exercise actual column definitions and cell renderer; the 5 older warehouse UI failures were outdated assumptions about default overview vs explicit stock/clearance/cost tabs. Tests navigate actual tabs; business assertions remain.

## Evidence
- Vitest targeted regression: 26 files, 245/245 PASS.
- Typecheck PASS; Vite production build + 30-tab workspace build guard PASS.
- SQLite in-memory PHPUnit: 145/148 PASS, 1767 assertions; three InventoryFoundationM1Test failures (8 warehouse seed, 8/7 warehouse count, stock/product availability) carried from prior wave. No backend business code changed. Do not mark backend suite green.
- 007windows GitHub API limit and two codeload attempts timed out; its previous accepted runtime was not switched. Browser layout/edit interaction, focus, compact multi-line cells and full role-specific UAT remain open. Main advanced after baseline; no production approval, deployment or merge.

## Next scope
- Remaining WarehouseShow native tables: three MorePlus evidence/preview tables, inbound SKU-review form, inventory warning rows, bill reconciliation review queue and bill history. Evaluate multi-line editable panels separately; do not break existing controls.
- Other unconverted business grids (including archive, remaining order/logistics/customer/product/finance lists and import evidence) require inventory, migration, regression and actual 007windows UAT. R5.3.3 / R5.3.4 are not complete.
