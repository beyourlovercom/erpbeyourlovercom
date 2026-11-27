# ERP1 Global Inventory Consistency V1
Owner approved 2026-11-27; R0–R5; ERP1 only.
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK
BASELINE_MAIN_SHA: b590ffa915a7fef1ed15ad5cf058d02f6f090bee
Branch: fix/erp1-inventory-consistency-v1-20261127
R0 production read-only: 207 rows, 9 allocation-vs-list available discrepancies (DE 3, US-SM 6). MorePlus signed GET 81 rows: 17 updates, 21 creates, 38 unchanged, 5 identity blocks.
R1: default-off Production-only 10-minute MorePlus scheduled GET and ERP mirror through existing syncProvider; independent scheduler; no outbound provider writes. R2: reuse allocation availability reader for inventory page projections and filters; retain physical quantity and reservation history. R3: preserve successful provider verification time even when stock unchanged; preserve existing independent last-read failure status. R4: exact head regression, existing P0 gates, typecheck/build, no real outbound writes. R5: ERP1 only, rollback point, database backup before bulk mirror, explicit environment flag, verify readback.
Conflict boundary: Product Master V3 owns mapping and WarehouseInventory model/availability reader; do not change those files or guess aliases. Rebase and verify exact integration head before release. ERP2 independent development and release frozen; V4 native reverse UI migration retained. No schema or FIFO change.
