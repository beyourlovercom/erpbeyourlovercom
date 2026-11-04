# ERP 海外仓超期库存 V1 · 2026-11-04

Status: independent PR UAT, **not merged and NOT deployed**. No schema migration or stock mutation.

## Owner request and source sample

Reference workbook: `007-超期库存.xlsx`, original Sheet1 75 detail rows,
1,744 units, four warehouses in the old snapshot. The workbook is a **format
reference**, never an input into ERP live inventory.

The first seven output columns intentionally match the original:
仓别、组别、责任人、入库时间、FNSKU、物料名称、数量.
Neither 组别 nor 责任人 is maintained as a stable ERP stock field. They stay blank
in XLSX and show — on the web. Never fabricate or copy staff details from the sample.

## Functional scope

- Inventory overview `/inventory` has one compact `超期库存 · ≥90 天`
  card showing current overdue **units** with distinct SKUs and batch rows.
- New read-only `/inventory/overdue` page: per-warehouse metrics,
  per-dated-lot table, warehouse/SKU search, 50 rows per page,
  empty state and reconciliation labels.
- Excel export `/inventory/overdue/export`: native XLSX in the original
  seven-column order, adding stock age, overdue days, ERP SKU, lot ID,
  status/source/quality. Filtered export includes **all** matches, not just
  current page. Separate `统计说明` tab documents date and provenance rules.
- `inventory.view` permission required for both page and file export.
  No `inventory.operate` privilege required and no mutation endpoints.

## Calculation

- Overseas warehouses are selected by actual `country_code` (not
  `is_domestic`, which is historically false for both Chinese warehouses);
  exclude `CN`, including 007 and China factory; includes US-LA, US-NY,
  DE, CA, and US-SM.
- Use `warehouse_inventory.quantity_on_hand` for **current physical quantity**,
  and `warehouse_inventory_lots.quantity/putaway_date` as historical dated
  batch evidence. Do not count deleted inventory or zero QOH.
- **At exactly 90 calendar days, include the lot**. Business date is UTC+8
  (Asia/Taipei). The cutoff is `as_of_date - 90 days`. The date-only
  fields are normalized in the business timezone before comparing, avoiding
  UTC-midnight off-by-one errors. This reporting condition is separate from
  P9 fee charging, which starts only after 90 free days.
- Keep distinct rows for repeated SKUs/lots; verify product identity.
  For US-LA, use a uniquely verified MorePlus SKU alias when available,
  otherwise canonical ERP SKU.
- Capped lot projection: physical QOH is an absolute ceiling; if gross dated
  lot total exceeds QOH, retain newer lot units first and exclude old excess
  units from overdue counts, mark **待复核**. Do not modify either ledger.
  If physical QOH exceeds known lot quantities, unallocated units have
  unknown age and are not silently inherited from other lots.
- If no dated lots exist, `warehouse_inventory.inbound_at` is a fallback
  only when explicitly present and is labeled **待复核 / 汇总入库日期**.
  No date, or future date -> exclude and count as unknown age units.
- All results are live ERP queries. **No** ShipOut API POST, batch re-import,
  stock writes, fulfillment changes, historical aging recalculation, or
  automatic storage-fee postings.

## Read-only Production baseline (2026-11-04 as_of)

Signed off **for discovery only**, not live deployment:
- Current overseas overdue units ~**5,027** (changes with inventory).
- **56** distinct product IDs, **85** warehouse-product combinations,
  **94** dated batch rows, across **4** warehouses.
- **16** units missing reliable age evidence (not added to overdue total).
- **2** products whose positive dated lot totals differ by a combined
  **2** units from current ERP QOH; both flagged and conservatively capped.
- Sample workbook 1,744 is stale and is not used to overwrite these numbers.

## Testing and release gates

- Scoped PHP feature tests cover day-90 inclusive vs day-89 exclusion,
  multi-lot SKUs, warehouse exclusion, missing dates, current QOH caps,
  page/warehouse/SKU filtering, native XLSX binary read-back, Chinese UTF-8
  filename, formula injection safety, view-only access and forbidden exports.
- Vue tests cover Inventory overview entrypoint, warehouse/date/lot table,
  filtering, Excel URL and no apply controls.
- No Production release until separate **Owner PASS** of the exact PR HEAD.
- Before staging, compare all touched files to actual Production release;
  preserve other workstreams, shared .env, storage and three logistics overlays.
  Stage HTML/Vite build first, test auth routing and live read-only projection,
  then Owner-controlled atomic release with rollback.
