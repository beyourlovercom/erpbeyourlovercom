# ERP Dashboard Redesign V1 — Work Package

Date: 2026-09-20  
Repository: `beyourlovercom/erpbeyourlovercom`  
Branch: `feature/dashboard-redesign-v1`  
Base main: `cdd0468e4c1a73517326075c3381ff06b70e8420`

## 0. Execution boundary

This is a fresh bounded package. Do not resurrect PR #81. Do not reopen Global Search V1, Accounting P8/P8.1/P8.2/P9, or any CLOSED Owner-UAT stage.

Parallel PI / Blue Label is owned by stacked PRs #393 → #396 → #399 and is out of scope.

GitHub is the only code/execution source of truth. Production is read before deploy. No business-data write is allowed in this package unless separately gated.

## 1. D0 Current Dashboard Audit — COMPLETE

Current route: `GET /dashboard` behind `web + auth + can:dashboard.view`.

Current backend:
- `DashboardMetrics::current()`
- `DashboardMetrics::procurementProgress()`
- `DashboardController`

Current frontend:
- `resources/js/Pages/Dashboard/Index.vue`

Current UI scope:
- 待处理订单
- 待发货
- 已发货待同步网站
- 今日已完成
- optional Purchase OS procurement progress/recent rows

Production read-only snapshot at D0:
- pending_orders: 1549
- pending_shipment: 1549
- shipped_pending_sync: 1
- completed_today: 0
- procurement active/pending/purchasing/ordered/completed/cancelled: all 0

## 2. D0 Findings

1. `pending_orders` and `pending_shipment` are the same query: `orders.order_status = pending_shipment`. They duplicate one business fact under two labels.
2. The Dashboard is logistics/sync-centric and does not answer a complete Owner/Operations overview.
3. Purchase OS dominates the lower page despite zero live activity; it should become contextual, not the page's main second block.
4. Existing canonical modules already own drill-down truth. Dashboard must aggregate and deep-link; it must not become a second source of truth.
5. Current permission boundary `dashboard.view` must remain. Sub-module cards must respect their underlying permissions before showing sensitive data/links.
6. Redesign must reuse current Product / Order / Logistics / Inventory / Accounting contracts where possible instead of reimplementing module logic.
7. Mobile is a first-class acceptance target.

## 3. Dashboard questions to answer

The redesigned BYL boss/operations dashboard should answer, at a glance:
- 今天/当前有多少真实订单需要关注？
- 订单履约卡在哪里：待分仓、待发货、运输中、异常、待复核？
- 网站同步是否有积压或失败？
- 库存是否存在缺货/紧张/备货压力？
- 今日/近期订单与利润有没有可读的趋势信号，且只使用 Accounting 已建立的安全收入/利润边界？
- 哪些异常需要点击进入现有模块处理？
- Purchase OS 有活动时显示进度；无活动时不抢占首页主视线。

## 4. Roadmap

D0 Current Dashboard Audit ✅  
→ D1 Metrics Contract  
→ D2 Shell / Navigation  
→ D3 Core KPI + Operations Funnel  
→ D4 Drill-down  
→ D5 Mobile / Performance  
→ D6 Local UAT  
→ PR  
→ Production  
→ Owner UAT

## 5. D1 guardrails

D1 must define every KPI with:
- canonical source table/service
- exact inclusion/exclusion rule
- time boundary if any
- permission required
- drill-down URL
- empty-state behavior
- no double counting across parent/child fulfillment
- historical_logistics exclusions where required
- no fabricated financial values
- no direct reuse of YBY Boss Dashboard semantics unless intentionally justified

No implementation should begin until the D1 contract removes the current duplicate `pending_orders/pending_shipment` ambiguity.


## 6. D1 Metrics Contract — COMPLETE

### 6.1 Global rules

- `dashboard.view` remains the page-entry permission.
- Every module block additionally requires its source-module read permission; hidden means no data query and no rendered value.
- Dashboard is read-only aggregation/navigation. It never becomes a second source of truth.
- Historical pseudo-orders with `externalReferences.provider = historical_logistics` are excluded wherever the source module excludes them.
- Parent/child fulfillment must follow the Logistics canonical query and must not be naively summed.
- Current-state metrics and period metrics are visually separated.
- Money shown as aggregated business performance must use the Accounting revenue-safety boundary and aggregate-ready FX only.
- Estimated profit is always labeled `预估 / 非最终`; Final Profit is never inferred from missing evidence.
- Empty/zero is a valid state. A zero caused by missing evidence must not be presented as a real zero business result.

### 6.2 Section A — Current Operations

Permission: `logistics.view`

Canonical source: extract/reuse the existing Logistics status contract from `LogisticsController::statusBar()`; do not duplicate its queries in Dashboard.

Primary cards:

| Metric | Contract | Drill-down |
| --- | --- | --- |
| 待发货 | existing Logistics `pending` count; excludes fulfillment parents with children, historical_logistics, and pending logistics review | `/logistics/pending` |
| 已发货待物流号 | existing Logistics `awaiting` count: shipped + shipped_at + missing tracking | `/logistics/awaiting-tracking` |
| 运输中 | existing Logistics `transit` count: shipped + non-empty tracking | `/logistics/in-transit` |
| 异常单 | existing Logistics `exception` count | `/logistics/exceptions` |
| 复核单 | pending `LogisticsReview`, excluding historical_logistics | `/logistics/review` |

Secondary context:
- 已完结 and 已取消 remain visible as secondary/compact status values, not primary alert cards.
- `/logistics/wait-stock` is exposed as **待分仓/待分配 context** using the exact current route query. It is a subset/context view and MUST NOT be added to 待发货 as if the two were mutually exclusive.
- D3 may visually draw a workflow, but must not imply arithmetic add-up between overlapping states.

Production D1 snapshot:
- 待发货 663
- 已发货待物流号 0
- 运输中 1847
- 异常单 0
- 复核单 0
- 已完结 0
- 已取消 549

### 6.3 Section B — Inventory Risk

Permission: `inventory.view`

Canonical source: `InventoryService::dashboard()` / `EloquentInventoryRepository::dashboard()`.

Primary metric:
- 库存预警 = `stock_alert_rows = out_of_stock_rows + low_stock_rows`

Supporting values:
- 没货 = `out_of_stock_rows`
- 库存紧张 = `low_stock_rows`
- 可用库存 = `quantity_available`
- 数据缺口仓 = `warehouse_data_gap_count`

Thresholds remain canonical:
- available <= 1 => 没货
- available 2..9 => 库存紧张
- available >= 10 => 有货

Drill-down:
- current safe link: `/inventory`
- D4 may add a dedicated stock-status query filter only as a bounded Inventory enhancement; until then do not invent a non-existent filter URL.

Production D1 snapshot:
- 库存预警 38 = 没货 21 + 紧张 17
- available 5,059
- data-gap warehouses 2 / total 7

### 6.4 Section C — Sync Health

Permission: `sync.view`

Canonical sources:
- manual order sync: `ManualSync.website_sync_status`
- orchestration health: `SyncOrchestrationService::queueSummary()`

Primary values:
- 待同步 = count of ManualSync `pending`
- 重试到期 = orchestration `retry_due`
- 同步失败 = orchestration `failed`
- 冲突 = orchestration `conflicts`

Drill-down:
- pending manual sync: `/sync/orders?status=pending`
- queue/orchestration overview: `/sync`

Do not merge these counts into one synthetic number because one order/outbox may appear in more than one operational queue.

Production D1 snapshot:
- ManualSync: completed 3 / pending 1
- Outbox: retry_due 1 / retry_waiting 0 / processing 0 / failed 0 / conflicts 0

### 6.5 Section D — Operating Finance

Permission: `analysis.view`

Default window: trailing 7 calendar days including today.
Selectable windows in D2/D3: 7d / 30d / 90d / custom. Date boundaries use `orders.order_date`.

Canonical population:
- `order_kind = customer`
- exclude `historical_logistics`
- apply the same revenue-ready scope used by `OrderAnalysisController`

Revenue-ready provider-import requires:
- required revenue components
- provider total
- tax
- paid_at
- provider status in processing/completed/refunded
- reconciliation matched

Primary metrics:
- 经营订单 = eligible customer Orders in selected period
- 收入就绪订单 = revenue-ready Orders
- 已有预估利润 = revenue-ready Orders projected by `EstimatedProfitProjectionService`
- 预估利润覆盖率 = estimated-profit Orders / revenue-ready Orders
- 最终利润覆盖率 = confirmed/final Orders / revenue-ready Orders

Monetary trend contract for D3:
- series: revenue / estimated total cost / estimated profit by order date
- use `OrderAnalysisService` net revenue + `EstimatedProfitProjectionService` estimated cost/profit
- aggregate in USD only when the order is base-currency aggregate eligible
- use the order's existing/frozen FX evidence; never fetch or invent an FX value inside Dashboard
- FX-ineligible Orders are excluded from money totals and surfaced as an excluded-count/data-health note
- every estimated-profit value is rendered as `预估 · 非最终`
- no projection fallback writes to ledger

Implementation guardrail:
- do not copy the private USD conversion rules from `ProfitDashboardService` into Dashboard. Extract/reuse a shared aggregator/converter or expose an application-level finance snapshot contract.

Production D1 snapshot:
- 7d: eligible 110 / revenue-ready 99 / estimated-profit 99 / final 0 / FX pending 0
- 30d: eligible 521 / revenue-ready 460 / estimated-profit 460 / P8.2 direct-required-cost ready 12 / final 0 / FX pending 0
- therefore Final Profit = 0 must never be presented as “business profit is zero”.

Drill-down:
- `/accounting/order-costs?date_from=...&date_to=...`
- optional final/confirmed detail remains inside Accounting, not Dashboard.

### 6.6 Section E — Purchase OS

Permission: `procurement.view`

Canonical source remains `DashboardMetrics::procurementProgress()` until a dedicated Procurement summary contract exists.

Behavior:
- if active > 0, show an operational card with active/status counts + recent rows.
- if active = 0, collapse to a compact “当前无活跃采购” state; it must not dominate the home page.
- drill-down: `/purchase`

Production D1 snapshot: all procurement status counts are 0.

### 6.7 Permission matrix

| Dashboard block | Additional permission |
| --- | --- |
| Current Operations | `logistics.view` |
| Inventory Risk | `inventory.view` |
| Sync Health | `sync.view` |
| Operating Finance | `analysis.view` |
| Purchase OS | `procurement.view` |

The controller must not query a block the user cannot view.

### 6.8 D1 acceptance / anti-regression

D1 is accepted only if implementation can prove:
1. old duplicate `pending_orders` / `pending_shipment` contract is removed;
2. Logistics dashboard counts equal the Logistics module canonical counts;
3. Inventory alert totals equal Inventory OS;
4. Sync values are not double-summed into a fake aggregate;
5. failed/unpaid provider orders do not receive estimated profit;
6. estimated values are visually identified as estimated;
7. final/confirmed zero is not presented as actual zero profit when coverage is incomplete;
8. hidden permission blocks execute no source query;
9. mobile uses the same semantic contract, not a reduced/incorrect alternate dataset.

## 7. Next execution point

D0 Current Dashboard Audit ✅  
→ D1 Metrics Contract ✅  
→ **D2 Shell / Navigation**  
→ D3 Core KPI + Operations Funnel  
→ D4 Drill-down  
→ D5 Mobile / Performance  
→ D6 Local UAT  
→ PR → Production → Owner UAT
