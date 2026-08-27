---
type: work-package
project: BYL ERP
work_package_id: ERP-INVENTORY-BATCH-SYNC-M1-001
title: Inventory Batch Sync M1
status: COMPLETE / OWNER UAT PASS / MERGE AUTHORIZED
execution_provider: CLAUDE_CODE
verification_tier: HIGH_RISK
baseline: inventory-foundation@d37c791b9482f1e569c9f9e17088f83c9a5d0a83
branch: feature/erp-inventory-batch-sync-m1-001
updated: 2026-08-27
---

# ERP-INVENTORY-BATCH-SYNC-M1-001

## Objective
Add one canonical Inventory Sync Pipeline so warehouse stock can be maintained by copy/paste, CSV, XLSX, and later API connectors without creating separate stock-update logic.

## Owner authorization / preflight
```text
OWNER_AUTHORIZATION: GRANTED IN CHAT 2026-08-25
OWNER_APPROVED_MENU_CHANGE: ADD Inventory > 批量同步 > /inventory/sync
CURRENT_REMOTE_MAIN: d4bd3a6ae9eba16e8b3e5e35a957fa4632c8669d
DEPENDENCY: ERP-INVENTORY-FOUNDATION-M1-001 @ d37c791b9482f1e569c9f9e17088f83c9a5d0a83
DEPENDENCY_TYPE: STACKED_PR
DEPENDENCY_STATUS: OWNER AUTHORIZED CONTINUATION BEFORE FOUNDATION MERGE
PARALLEL_SAFE: YES, bounded child worktree/branch; do not modify Shipping
WORKTREE: /Users/a/AI/007/worktrees/erp-inventory-batch-sync-m1-001
PRODUCTION_DEPLOYMENT: NO
```

## Canonical sync contract
All inputs normalize to the same row contract before preview/confirm:
```text
warehouse_id
sku
stock_status = sellable | defective
stock_count_at
putaway_date nullable
quantity >= 0
raw_product_name nullable
raw_warehouse_name nullable
raw_dimension nullable
raw_total_volume nullable
source_row_no
```

Input adapters:
- Paste: tab/newline table, minimum SKU + Qty, selected target warehouse required.
- CSV: header mapping + preview.
- XLSX: first worksheet + header mapping + preview.
- API: contract/interface placeholder only in M1; no live connector or scheduler.

## Source-field authority
```text
SKU: canonical Product Master match key after trim; no auto-create
Product name: reference only
Warehouse string: reference/validation only; selected ERP warehouse is authority
Stock properties: 正品 => sellable; 次品 => defective; unknown value blocks confirmation
Stock count date: inventory source time
Putaway date: lot authority
Qty: inventory authority
Stock age: ignored/recalculated from putaway date
Dimension: raw reference only; never overwrite Product Master
Total volume: raw reference only; never overwrite Product Master
```

## Snapshot semantics
- `partial`: only listed SKUs are authoritative. For each touched SKU, replace current normalized lots for that SKU across stock statuses; SKUs absent from the source remain unchanged.
- `full`: source is authoritative for the entire selected warehouse. Existing warehouse SKUs absent from the source are shown in Preview and become zero sellable stock after explicit Confirm.
- Confirmation is transactional and exactly-once per batch; a confirmed batch cannot be confirmed again.
- `quantity_reserved` is never imported and is preserved by batch sync.
- `warehouse_inventory.quantity_on_hand` is SELLABLE quantity only.
- `quantity_available = max(quantity_on_hand - quantity_reserved, 0)` remains canonical.
- Defective stock is stored separately and never contributes to Shipping-eligible QoH/Available.
- Multiple source rows for the same SKU are not last-row-wins. Current lots are grouped by SKU + stock_status + putaway_date; aggregate QoH is the sum of sellable lots.
- `warehouse_inventory.inbound_at` becomes the earliest current sellable putaway date for that warehouse/SKU, else null.

## Persistence
Add current normalized lot state plus immutable sync evidence:
```text
warehouse_inventory_lots
inventory_sync_batches
inventory_sync_rows
```
Required batch evidence includes warehouse, source type, sync mode, original filename when present, stock count time, checksum, preview totals/status, creator, confirmer and confirmed_at.
Required row evidence includes source row number, raw values, normalized SKU/status/date/qty, Product Master match, validation status/message, and raw reference fields.

## UI / routes
- Add Owner-approved level-2 menu `批量同步` at `/inventory/sync`, permission `inventory.operate`.
- `/inventory/sync`: input tabs `复制粘贴`, `XLSX / CSV`, `API` (API disabled/尚未配置).
- Preview before any mutation: source rows, unique SKU, sellable qty, defective qty, duplicate/multi-lot SKU count, unmatched SKU count, warnings/errors.
- Preview must show source rows and aggregated ERP result.
- Confirm button disabled while blocking row errors exist.
- Sync history is available from the same workspace/detail route; it is not a new governed level-2 menu item.

## Owner-provided real-file reference
The real UAT files are not committed to GitHub. M1 parser/aggregation must support their exact header schema:
```text
SKU
Product name/产品名称
Warehouse/仓库
Stock properties/库存属性
Stock count date/库存统计日期
Putaway date/上架日期
Qty/数量
Stock age/库龄（天）
Dimension/尺寸
Total volume总体积
```
Expected real-file facts for later Owner UAT:
```text
天运德国.xlsx: 20 rows / 17 unique SKU / physical qty 1792 / sellable 1791 / defective 1
duplicate multi-lot aggregates: SF030C=26, CA594B=34, VA617A=135
美东.xlsx: 19 rows / 18 unique SKU / sellable qty 1363
VA617A rows 256 + 49 => aggregate 305
```

## Security / correctness gates
- Product match uses Product Master SKU only; unmatched SKU blocks confirm and never auto-creates Product Master.
- Target warehouse is selected canonical Warehouse Master; raw warehouse names must not create new warehouses.
- No raw spreadsheet dimension/volume/product name may overwrite Product Master.
- File type/size limits, safe temporary handling and validation are required.
- Every confirmed batch records immutable Audit evidence in addition to sync batch/row records.
- No Shipping code changes in this package.

## Allowed dependency change
Adding `phpoffice/phpspreadsheet` is authorized only if required for safe server-side XLSX parsing. Do not introduce a broader import framework unless evidence shows it is necessary.

## Acceptance / validation
- focused PHP tests cover paste, CSV, XLSX, Product Master matching, multi-lot aggregation, defective exclusion, partial/full semantics, reserved preservation, double-confirm prevention and Audit;
- Vue tests cover source selection, preview, blockers, confirm state and API placeholder;
- Menu baseline/runtime/test updated together for Owner-approved `批量同步`;
- migration fresh/rollback validation passes on local test database;
- existing Inventory Foundation tests remain green;
- full US PHPUnit + Vitest + typecheck + build + architecture/menu checks pass on merge-candidate revision;
- local US runtime UAT proves one paste path and one real XLSX path before push;
- because this package adds schema/import mutation, final merge candidate is HIGH_RISK and requires China exact-SHA independent full verification unless Owner explicitly overrides at that gate;
- no merge or deployment without explicit Owner authorization.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Gate COMPLETE → MVP1 Tencent replacement → Order/Fulfillment → Product Master → Inventory Foundation → Inventory Batch Sync [CURRENT OWNER PRIORITY] → Shipping↔Inventory → Tencent productivity → Owner UAT → Woo/Tracking/Sync → later program layers
CURRENT_TRACK_PHASE_MAP: Product Master UAT PASS → Inventory Foundation UAT PASS → ERP-INVENTORY-BATCH-SYNC-M1-001 CURRENT → Shipping↔Inventory WAITING
COMPLETED_PHASES: Product Master China UAT; Inventory Foundation China UAT
CURRENT_PHASE: Inventory Batch Sync implementation
CURRENT_WORK_PACKAGE: ERP-INVENTORY-BATCH-SYNC-M1-001
PACKAGE_STATUS: ACTIVE / OWNER AUTHORIZED
WAITING_PHASES: Shipping↔Inventory integration; merge; deployment
BASELINE: d37c791b9482f1e569c9f9e17088f83c9a5d0a83
GITHUB_STATE: child branch created from exact Inventory Foundation GitHub head; Draft PR pending creation
SOURCE_CONFLICTS: repository docs 13/CURRENT_CONTEXT are stale versus GitHub main d4bd3a6 and Owner-prioritized Product/Inventory sequence; this package records current Owner authority without rewriting unrelated roadmap history
GLOBAL_GATE: LOCAL IMPLEMENTATION ONLY
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE / no Owner release authorization
NEXT_DEPENDENCY: verified Inventory Batch Sync merge candidate, then Shipping↔Inventory
EVIDENCE_AS_OF: 2026-08-25

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 6h planning value / 4–9h range
KNOWN_CURRENT_TRACK_REMAINING: Inventory Batch Sync 4–9h; downstream packages not recomputed here
ARCHITECTURE_GATE_REMAINING: 0h / complete
MVP1_TOTAL_ESTIMATE: 122–190h historical repository total
MVP1_PLANNING_BASELINE: 166h historical repository planning value
MVP1_MAXIMUM_ENVELOPE: 192h historical repository maximum envelope
MVP1_REMAINING: UNKNOWN WITH EVIDENCE / accepted actual ledger and overlap map incomplete
POST_MVP_KNOWN_INCREMENT: approximately 68–120h historical repository increment
LONG_TERM_CUMULATIVE_RANGE: 235–402h architecture; 603–1,018h first automated operations MVP; 1,059–1,802h product/SEO; 1,595–2,714h full core ERP; 1,819–3,146h all expansion
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE / cumulative horizons not additive and current Owner reprioritization not re-estimated
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE / accepted project-wide ledger incomplete
ESTIMATE_CONFIDENCE: MEDIUM for this bounded package
ESTIMATE_AS_OF: 2026-08-25
ESTIMATE_METHOD: bounded schema/import/UI/test scope; no chat elapsed-time conversion

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.5h planning / 0.3–0.8h
CORE_EXECUTION: 3.5h planning / 2.2–5.5h
TEST_AND_VALIDATION: 1.5h planning / 1.0–2.0h
REMEDIATION_BUFFER: 0.5h planning / 0.5–0.7h
ESTIMATED_EFFECTIVE_HOURS: 6h
ESTIMATE_RANGE: 4–9h
ESTIMATE_BASIS: Inventory Foundation already exists; bounded import pipeline + 3 tables + one workspace + adapters + tests
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: INCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: LOCAL TEST ONLY
UAT: INCLUDED
DEPLOYMENT: EXCLUDED

## Owner UAT closeout remediation — 2026-08-27

```text
OWNER_UAT: PASS
OWNER_APPROVED_DYNAMIC_WAREHOUSE_SHORTCUTS: YES
WAREHOUSE_SHORTCUT_SOURCE: active canonical Warehouse records
WAREHOUSE_SHORTCUT_ROUTE: /inventory/{warehouse}
DEFAULT_UAT_HOST: US main development Mac
CHINA_VERIFICATION: WAIVED BY OWNER UNLESS EXPLICITLY REQUESTED OR CROSS-MACHINE ISSUE REQUIRES IT
MERGE_AUTHORIZATION: GRANTED BY OWNER
```

Owner UAT also required the Local/UAT credential `admin` / `password`, repair migration support for stale local inventory-sync schemas, and a persistently visible two-step `Preview → 确认导入库存` control. These Local/UAT and sync-workspace remediations are included in the final merge candidate; Production deployment remains separately unauthorized.

## Final closure — 2026-08-27
```text
US_FULL_VERIFICATION = PASS
OWNER_LOCAL_UAT = PASS
OWNER_REMOTE_UAT = PASS
CHINA_VERIFICATION = WAIVED BY OWNER / NOT REQUIRED BY DEFAULT
DYNAMIC_WAREHOUSE_SHORTCUTS = PASS / data-driven active Warehouse Master entries
LOCAL_UAT_DEFAULT_CREDENTIALS = admin / password (local/testing only)
MERGE_AUTHORIZATION = GRANTED BY OWNER
PRODUCTION_DEPLOYMENT = NOT AUTHORIZED
NEXT_WORK_PACKAGE = Shipping ↔ Inventory M1
```

The Owner explicitly set the continuing verification default: US Mac verification/UAT is sufficient unless the Owner requests China verification or a concrete cross-machine reproduction risk requires it.
