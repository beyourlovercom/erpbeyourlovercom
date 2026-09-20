# ERP Accounting Product Cost Evidence Coverage — 2026-09-20

## Status

READ-ONLY AUDIT COMPLETE / EXTERNAL COST EVIDENCE REQUIRED

## Scope

Fresh Production audit of confirmed Product Cost evidence after Progressive Estimated Profit V1 closure.

No Product Cost source was invented, back-propagated, or written.

## Production baseline

Database: byl_erp_prod

Confirmed Product Cost sources:
- rows: 57
- distinct Products: 55
- all current confirmed source rows effective from: 2026-07-01

Revenue-ready customer Orders:
- all dates: 5,585
- on/after 2026-07-01: 1,141

Post-2026-07-01 Product Cost coverage:
- Orders with canonical product_cost: 294
- Orders fully covered by effective Product Cost source: 294
- mixed Orders (some lines covered, some missing): 231
- fully missing Product Cost evidence: 616
- one additional Order contains an item with no product_id

The dominant issue is NOT effective-date drift. It is missing confirmed source coverage for Products actually sold after 2026-07-01.

Post-July revenue-ready Orders use 97 distinct Product IDs. 53 used Products have no confirmed Product Cost source.

## Coverage priority

There are 846 unique post-July revenue-ready Orders affected by at least one missing Product Cost source.

| Rank | Product ID | SKU | Affected Orders | Units |
|---:|---:|---|---:|---:|
| 1 | 1350 | gift | 292 | 292 |
| 2 | 1279 | VA617A | 153 | 155 |
| 3 | 1352 | VA617A-GREEN | 126 | 128 |
| 4 | 1353 | VA617A-PINK | 108 | 109 |
| 5 | 1243 | TL278A-Green | 84 | 84 |
| 6 | 1400 | VX723A | 36 | 36 |
| 7 | 1254 | VX357A | 29 | 29 |
| 8 | 1426 | VP757B | 26 | 27 |
| 9 | 1371 | A1 | 21 | 21 |
| 10 | 1365 | VG515A | 20 | 20 |
| 11 | 1471 | AH414 | 19 | 19 |
| 12 | 1337 | SF030C | 19 | 19 |
| 13 | 1456 | JEM0108017 | 18 | 18 |
| 14 | 1232 | VX007H | 16 | 16 |
| 15 | 1233 | BX033B | 15 | 15 |
| 16 | 1255 | VX357D | 14 | 14 |
| 17 | 1429 | N1 | 13 | 13 |
| 18 | 1244 | BV339A-Y | 10 | 10 |
| 19 | 1453 | VX760A | 9 | 10 |
| 20 | 1349 | VX586B | 8 | 8 |

Cumulative unique affected-Order coverage if confirmed cost evidence becomes available:
- Top 1 Product: 292 / 846 = 34.5%
- Top 3: 498 / 846 = 58.9%
- Top 5: 649 / 846 = 76.7%
- Top 10: 748 / 846 = 88.4%
- Top 15: 783 / 846 = 92.6%
- Top 20: 809 / 846 = 95.6%
- Top 30: 831 / 846 = 98.2%
- All 53: 846 / 846 = 100%

Therefore a small prioritized cost-source update can close most current Product Cost gaps; a 53-SKU manual exercise is not required as the first step.

## Identity anomaly

One post-July revenue-ready item is not a Product Cost source problem and must remain separate:

- ERP Order: 007-0714-c38594
- external Order: 38594
- order DBID: 5199
- item DBID: 6668
- product_id: null
- SKU: blank
- product summary: Sticker Design
- quantity: 100
- unit price: 0.01

Do not fabricate a Product identity or Product Cost for this item. Resolve only if exact source identity becomes available.

## External evidence recovery

Searched without mutation:
- US Mac Downloads / Desktop / Documents / AI trees
- CN Mac Downloads / Desktop / Documents
- macOS Spotlight on both machines
- ChatGPT Library
- connected Google Drive by:
  - 成本货本007组
  - 进销存 007
  - 成本货本
  - high-priority SKU searches
  - 原成本单价 / 成本单价

The original authoritative 成本货本007组 7月进销存.xlsx was not recovered.

Google Drive contains an OEM产品开发进度表 - 嫦娥2025 workbook that includes a generic 单价 column and mentions products such as VA617A, but the VA617A / color-variant rows do not contain usable unit-price values and this workbook is not the frozen inventory-cost-book source. It is therefore NOT accepted as canonical Product Cost evidence.

## Existing import contract

ConfirmedProductCostSourceImportService remains the only approved source-file path for confirmed Product Cost.

Required row identity:
- erp_product_id
- erp_sku
- source_variant_key
- unit_cost
- currency

Required import metadata:
- source reference
- effective-from date
- optional source-at timestamp

Safety rules remain:
- no historical back-propagation from July cost into earlier periods without historical effective evidence;
- no guessed related-SKU cost mapping;
- no variant inheritance unless the source file itself proves it;
- no Product standard-cost fallback when those fields are absent;
- no conversion of Progressive Estimated Profit fallback into confirmed Product Cost.

## Next gate

Product Cost confirmed coverage is external-evidence blocked.

When a current/expanded 007 cost book becomes available:
1. extract/normalize the source rows;
2. match exact Product ID + SKU;
3. dry-run Top 20 first;
4. stop on any conflicting Product-period cost;
5. apply only exact confirmed rows;
6. rerun finance:materialize-product-costs dry-run;
7. verify Product Cost / Estimated Profit deltas;
8. Production apply only under an exact Data Write Gate.

Until then, Progressive Estimated Profit remains the safe operating model for missing Product Cost.
