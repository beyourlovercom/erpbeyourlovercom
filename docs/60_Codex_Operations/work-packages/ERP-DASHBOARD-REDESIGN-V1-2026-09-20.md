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
