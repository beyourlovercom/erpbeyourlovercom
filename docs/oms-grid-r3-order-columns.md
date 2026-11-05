# BYL ERP OMS Grid V1 — R3 Order column catalog

Status: R3 optional-column expansion, isolated UAT only, Draft PR #628.

## Frozen presentation rule
- Default operational columns: ERP订单号, SKU, 下单数量, 来源, 订单日期, 收件人, 国家, 仓库, 工作状态, 物流单号, 操作.
- 46 order-data columns + 1 actions column in the right-hand AG Grid "列设置" selector. Remaining columns default hidden to preserve one-line compact row density. Users can drag, resize, pin, hide/show and persist choices by account.
- A single flat title row and a separate filter-input row; max-window mode and server pagination remain unchanged.
- SKU is an **ordered distinct SKU list** for the current order's item lines. 下单数量 is **SUM(quantity) over all item lines**, not SKU-type count or line count; blank/missing item collections are shown as `—`/null, not a fabricated zero.
- `SKU×数量明细` is a separate optional column for multi-SKU orders so users can see product-specific units without squeezing them into the SKU and total-quantity columns.
- `商品行金额` = sum of existing line_revenue values, **not an overall order payment total**: discounts, shipping, taxes and refunds are not included. Never label it as order revenue/profit. No confidential costs are newly exposed.
- No new product/warehouse lookups are performed: all data is mapped from existing OrderController::orderData and its loaded order items, shipment, address, notes and sync fields.

## Available columns by group

| Group | Columns | Default |
|---|---|---|
| 高频 | ERP订单号, SKU, 下单数量, 来源, 订单日期, 收件人, 国家, 仓库, 工作状态, 物流单号, 操作 | All visible |
| 商品 | SKU×数量明细, 产品名称, 商品行数, SKU种类数, 商品行金额, 产品单价明细, 币种 | Hidden |
| 身份 | 网站/来源订单号, 内部订单号, 订单数据库ID, 订单类型, 来源类型代码, 订单真实状态, 负责人, 原订单号, 异常/业务标记 | Hidden |
| 收件 | 收件电话, 州/省, 城市, 邮编, 地址第1行, 地址第2行 | Hidden |
| 履约 | 仓库ID, 发货状态, 分仓来源, 物流商, 物流渠道, 物流商选择来源, 实际发货时间, 发货处理人ID, 物流备注 | Hidden |
| 其它 | 订单备注, 官网同步状态, 官网同步时间, 国内驳回原因, 国内驳回时间 | Hidden |

## Search and safety
- Server-accurate column-search fields: ERP订单号, SKU, 下单数量, 产品名称, 来源, 订单日期, 收件人, 国家, 仓库, 工作状态, 物流单号, 负责人, 城市, 邮编, 物流商, 网站/来源订单号.
- SKU uses contains-match against `order_items.sku`; quantity input accepts an integer in 0..99999 and matches the **order-level total** across all order_items (not just an individual item's quantity).
- Global OMS search also looks at SKU and product summary. Older unmigrated order routes retain their legacy matching rules.
- Backend continues to authorize the same orders.view and orders.edit permissions and cannot fetch extra order data via column selection.
- No full-site migrations, no new DB columns/migrations, no Production writes or workflow changes.
- Finance order-level total, true order profit, accounting costs, live inventory availability and other data not present in the existing list response require separate domain adapters and permission review; do not guess their values.

## Verification
- Vue row-projection unit tests cover multi-SKU quantities, missing items, zero-value gift lines and existing metadata flattening.
- Laravel feature tests exercise full-dataset SKU/quantity/product-name/recipient queries and numeric input rejection while preserving the legacy list.
- Authenticated Chrome UAT on 225 synthetic SQLite orders (247 item lines): 47 options available, SKU and quantity visible by default, multi-SKU order `R3-UAT-0220` displays `A1, MF414A` and quantity `3`, SKU MF414A filters 112 results, quantity 3 filters 76, 0 page JS errors.
- Owner-facing private Tailnet UAT remains `http://100.94.52.109:8199/orders?oms_grid=1`; release gate remains Draft PR / Owner visual PASS.
