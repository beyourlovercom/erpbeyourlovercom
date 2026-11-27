# ERP Factory Native Import V1 (Product Master V3 work package)

Status: OPEN. Entry context and native read-only adapter implemented; do NOT certify import or enable production overwrite yet.

## Observed production root causes
- WarehouseShow links discard originating warehouse. Sync initializes selectedWarehouseId=null, and canPreview returns false.
- Generic XlsxAdapter reads only first sheet and first row, requiring SKU and qty aliases. Native file has named sheet 谦禾库存明细, header row 3, A material code, B material name, C factory finished-goods total, X B站-007成品仓. Native code is not ERP SKU.
- Original file contains 503 distinct material codes, C total 107629, X total 451, 9 positive X rows; all code/name and C/X values valid. 即时库存 sheet aggregates agree with these totals. Production FACTORY stock readback: 27 product rows/9350 units. Historical factory batch 21 includes quantities from C scope, not X. Do not write aggregate across unrelated departments or assume all units are dispatchable. 007 warehouse is distinct from FACTORY.

## Required completion gates
- Reuse Product Master V3 material-version registry, not a competing table or a text-only code field. Bind exact code to ERP product with recorded evidence; preserve every version and name. Store original snapshot and date. Never infer identity from a substring or overwrite old cost records.
- Factory direct import UI: choose file -> one explicit import action, automatic parse, scope/mapping/duplicate/freshness checks, transactional apply. No repeated manual preview/confirmation for verified normal files. New/ambiguous codes remain visible as unmapped source records, not sellable inventory; do not zero or partially overwrite a product whose coverage is uncertain. Only authoritative in-scope snapshot may clear absent material quantities. Preserve reserved quantity, unrelated warehouses, costs and order identity. Reject stale/conflicting/replayed snapshots; same snapshot idempotent. Audit actor, old/new values and time. Distinguish physical on-hand from availability and restrictions.
- Native adapter currently not routed into production write path by design; integrate after registry and snapshot policy gates. ERP1/ERP2 must share backend and same actual browser flow.
- Local tests: 44 PHP tests / 192 assertions; 11 Vue tests; typecheck PASS. Two stale pre-existing Vue text assertions were aligned to the current partial/full behavior. Actual original file validated read-only outside runtime; native PHP regression uses representative named-sheet fixture. No production writes, deployment, or real employee browser acceptance.
