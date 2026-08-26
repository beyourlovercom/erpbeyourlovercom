# Product Edit V2 UI Specification

**Status: OWNER_APPROVED / PRODUCT FORM BASELINE / FROZEN V1**
**Implementation gate: `PRODUCT_EDIT_V2 = UAT_READY_FROZEN`**
**Date: 2026-08-25**
**Applies to:** `/products/create`, `/products/{id}/edit`
**Parent rule:** `18_BYL_ERP_FORM_DESIGN_STANDARD_V1.md`

## 1. Goal

Replace the current developer-style vertical Product form with a centered, card-based ERP form that is compact, visually calm, business-grouped, and consistent with the BYL ERP form standard.

No Product truth or accounting contract changes are authorized by this UI specification. This is presentation/interaction restructuring over the already-approved Product fields and permissions.

## 2. Exact Desktop Wireframe

```text
┌──────────────────────────────────────────────────────────────────────────┐
│ BYL ERP / 产品 / 产品列表 / 编辑产品                         [返回列表] │
│ 编辑产品                                                                 │
│ 管理产品资料、物流属性与会计参考成本                                     │
├──────────────────────────────────────────────────────────────────────────┤
│ [SKU  TZ001]  [来源 WooCommerce]  [Woo ID 12345]  [状态 ● 启用]          │
├──────────────────────────────────────────────────────────────────────────┤
│ 基本信息                                                                 │
│ SKU *                         产品名称 *                                  │
│ [ TZ001                  ]    [ Product name                         ]    │
│ 产品类型                     GTIN                                         │
│ [ simple ▼               ]    [                                    ]    │
│ 单位                         状态                                         │
│ [ pcs ▼                  ]    [● 启用] [○ 停用]                          │
└──────────────────────────────────────────────────────────────────────────┘
```
```text
┌──────────────────────────────────────────────────────────────────────────┐
│ 销售信息                                                                 │
│ 售价                           原价                                       │
│ [ 99.00                  ]    [ 129.00                             ]    │
└──────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────────┐
│ 物流属性                                                                 │
│ 净重 (kg)                     发货重量 (kg)                               │
│ [ 0.650                  ]    [ 0.800                              ]    │
│ 长 (cm)                        宽 (cm)                                     │
│ [ 30.0                   ]    [ 20.0                               ]    │
│ 高 (cm)                        体积重量（只读/未来计算）                   │
│ [ 10.0                   ]    [ —                                  ]    │
└──────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────────┐
│ 海关与产地                                                               │
│ 原产国                         HS Code                                    │
│ [ CN / 中国 ▼             ]    [ 3926909090                         ]    │
└──────────────────────────────────────────────────────────────────────────┘
```
```text
┌──────────────────────────────────────────────────────────────────────────┐
│ 成本参考                                                                 │
│ 标准生产成本                   标准包装成本                               │
│ [ 21.0000                ]    [ 1.5000                              ]    │
│ 标准采购成本                   成本币种                                   │
│ [ 18.5000                ]    [ CNY ▼                               ]    │
│ 成本备注                                                                 │
│ [ 当前参考值；正式历史成本由 Accounting Cost Versions 管理          ]    │
└──────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────────┐
│ 内部备注                                                                 │
│ [                                                                    ]   │
└──────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────────┐
│ [取消]                                              [保存产品]            │
└──────────────────────────────────────────────────────────────────────────┘

┌────────────────────────────── 危险操作 ──────────────────────────────────┐
│ 删除此产品。历史订单快照继续保留。                         [删除产品]     │
└──────────────────────────────────────────────────────────────────────────┘
```

## 3. Field Group Contract

### A. Identity strip — edit page only

Show compact read-only metadata above cards:
- SKU.
- Source: `WooCommerce` or `手动`.
- Woo ID when present; show `—` when absent.
- Active status badge.

The source identity is never editable here. `source_type` and Woo ID must not look like normal writable inputs.

### B. 基本信息

Writable fields:
- `sku` — required for manual create; unique when nonblank.
- `name` — required.
- `product_type` — compact select/chip; default `simple` where current contract does so.
- `gtin` — optional text.
- `unit` — compact input/select; common examples `pcs / 件 / 套` may be offered as suggestions without changing stored truth.
- `is_active` — segmented choice or compact toggle, not a giant native checkbox.

Layout: 2 columns desktop, 1 column narrow. `name` may span wider if needed.
### C. 销售信息

Writable fields:
- `sale_price`.
- `regular_price`.

Rules:
- Empty is valid where current backend contract permits it.
- Do not fabricate zero.
- Numeric amount controls use consistent decimal entry.
- Do not imply that Product sale/regular price is authoritative accounting revenue.

### D. 物流属性

Writable fields:
- `weight_kg` — product/net/reference weight.
- `shipping_weight_kg` — shipping reference weight.
- `length_cm`.
- `width_cm`.
- `height_cm`.

Rules:
- All values optional unless later business policy changes.
- Negative values rejected server-side.
- Use suffix/unit presentation (`kg`, `cm`).
- Do not introduce actual shipment cost here; shipment truth belongs to Logistics.
- A future calculated dimensional-weight display may be read-only and must be labelled as calculated/reference, never persisted as fabricated truth.
### E. 海关与产地

Writable fields:
- `country_of_origin`.
- `hs_code`.

Rules:
- Country should evolve toward a searchable country selector using ISO country data; current stored contract remains the approved country code field.
- HS Code remains text/code input; do not auto-correct or infer a customs classification without an approved data source.

### F. 成本参考

Writable fields:
- `standard_production_cost`.
- `standard_packaging_cost`.
- `standard_purchase_cost`.
- `cost_currency`.
- `cost_note`.

Rules:
- This card must include helper copy: `当前参考成本；历史会计成本由 Accounting 成本版本管理。`
- Standard costs are current reference values only.
- No field in this card may silently create historical Accounting ledger entries.
- Currency is a compact 3-letter selector/input consistent with the current schema.
- Numeric costs permit 4 decimal precision consistent with the database contract.

### G. 内部备注

- `notes` full-width textarea.
- Internal operational context only; no fabricated defaults.
## 4. Create vs Edit Behavior

### Create `/products/create`

- Page title: `新增产品`.
- No identity strip before first save.
- `source_type` is implicitly `manual`; do not expose an editable source selector.
- Woo ID is absent/NULL and must not be fabricated.
- Primary action: `创建产品`.
- On success: redirect deterministically to the created Product edit page and show success feedback.
- No Delete action before creation.

### Edit `/products/{id}/edit`

- Page title: `编辑产品`.
- Show identity strip.
- Primary action: `保存产品`.
- Source identity stays read-only.
- Delete is available only to authorized operators and lives in the separated Danger Zone.
- Historical order-item snapshots must not be mutated by editing Product master fields.

## 5. Action Bar Rules

Desktop:
- Action bar aligned to the form width.
- Left: `取消` / `返回列表` secondary action.
- Right: one primary `保存产品`/`创建产品` button.
- Do not place Delete as a same-weight button beside Save.

Narrow/mobile:
- Full-width primary action at bottom.
- Secondary action remains available but visually subordinate.
- Respect viewport safe areas; do not cover form fields with a sticky action bar.
## 6. Validation and Feedback

- Required/unique/numeric/range rules remain server-authoritative.
- Client-side validation may improve UX but must not replace backend validation.
- Invalid controls receive danger border/focus treatment and a concise Chinese error below the field.
- Preserve all other entered values after validation failure.
- On Save, primary button enters loading/disabled state to prevent duplicate writes.
- On success, show `产品已保存` or `产品已创建`.
- Unexpected server failure must show a recoverable error surface; production UX must not intentionally rely on the Laravel debug exception page.

## 7. Permissions

- `products.view`: Dashboard, list, SKU search/read surfaces.
- `products.operate`: create/edit/update/delete/import.
- Create/Edit pages are never a UI substitute for authorization; server middleware remains authoritative.
- View-only users must not receive misleading active management actions in the Product list.

## 8. Visual Acceptance Gate

Owner UAT should reject the page if any of the following remain:
- Native oversized checkbox floating in empty space.
- Long single-column form with large unused right-side whitespace on desktop.
- Every input rendered full-width despite short field semantics.
- Database-order grouping instead of business sections.
- Source/Woo identity rendered as editable fields.
- Delete visually competing with Save.
- Inconsistent widths, radii, spacing or label treatment.
- Missing loading, validation or success feedback.
- Horizontal overflow on narrow screens.
- Any fabricated value presented as real Product, Logistics or Accounting truth.