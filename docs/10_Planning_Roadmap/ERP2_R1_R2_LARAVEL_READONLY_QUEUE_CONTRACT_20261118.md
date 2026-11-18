# ERP2 R1–R2 — Laravel 业务队列只读连接契约（2026-11-18）

> **状态：源代码证据已确认，ERP2 Adapter 尚未实现/发布；本文件不是运行时或前台 PASS。** 以原 ERP `main@eb8ed64e7790efbcc6271742c8beaac3eaa3b30b` 为静态真相；ERP2 Vben 仍位于 Draft PR #766。关联 R0 工作包：`ERP2_CODE_FIRST_DATA_LAST_R0_WORK_PACKAGE_20261118.md`。

## 1. 为什么必须是三套业务投影

| 独立队列 | 原 Laravel GET | 源读取根 | 源 Query Owner | ERP2 必须保留的业务语义 |
|---|---|---|---|---|
| 待分仓 | `/logistics/wait-stock` | `Order` | `LogisticsController::waitStock` → `LogisticsStatusSummary::pendingAllocationQuery` → `OrderProgressQuery::pendingAllocation` / `PendingAllocationEntryPolicy` | 根单、合资格待发货、未提交分仓或明确退回；排除历史物流、已分单根重入、缺货、非资格单 |
| 国内发货 | `/logistics/china?view=review/pending/stockout/today/today-4px/today-weship/exceptions/all/outsource` | `Order` + Shipment/仓库关系 | `DomesticLogisticsController::index/baseQuery/applyView` → `OrderProgressQuery::domesticReview/domesticReady/domesticStockout` | 国内工厂路由/实际仓、未完成外采屏障、未出货状态、今日承运商/审核/异常各自条件；不能用待分仓总表条件 |
| 海外发货 | `/logistics/oversea/{warehouse?}?view=review/stockout/pending/issued/shipped/exceptions/all` | **`Shipment`** + Order + OverseaReview + OMS ledger | `OverseaWarehouseController::index/applyView` → `OrderProgressQuery::overseasReview/overseasReady/overseasStockout` | 真实 Shipment 仓、路由仓、审核仓匹配、OMS drafted/created/exception 和库存缺货；需要总审核与逐仓队列互相吻合 |

**硬约束：** ERP2 只读接口不得直接从 `orders` 总表做三套前端过滤，也不得创建新的 Order/Shipment 业务真相表。若采用 JSON 适配器，应从上述 canonical Laravel Builder/Read Model 输出，不得解析 Inertia HTML 当业务 API。

## 2. 源码级关键谓词（必须迁移、不可简化）

### 待分仓

- `LogisticsStatusSummary::pendingAllocationQuery()` 从 `Order::query()->eligibleForOperations()` 开始，经 `PendingAllocationEntryPolicy::constrain()` 和 `OrderProgressQuery::pendingAllocation()`，再排除 `externalReferences.provider = historical_logistics`。
- `OrderProgressQuery::pendingAllocation()` 对 `fulfillment_parent_id IS NULL` 限制根单，允许明确 `workflow_status = pending_allocation`；自动分类必须 `order_status = pending_shipment` 且未有效提交分仓，Shipment 不存在或尚无仓库。拒绝退回有单独条件（审核 rejected + Shipment 待发、无仓、无 Tracking）。
- 原 Inertia 页可查询 `search`（ERP 显示号/外部号/收件人）和 `per_page` 20/50/100；必须由**服务端**分页、统计数量和权限过滤。

### 国内

- `DomesticLogisticsController::baseQuery()` 从合资格 `Order` 起，排除 cancelled、带 fulfillmentChildren 的父单及 historical_logistics；仓路由在 `FACTORY/OUTSOURCE` 或无路由但实际 Shipment 指向 Factory。
- `applyView('review')` 复用 `OrderProgressQuery::domesticReview()`，有实仓 `FACTORY`、`Shipment.pending`、未出库、未 `domestic_reviewed` 或 `domestic_stockout` 标记。
- `applyView('pending')` 复用 `domesticReady()`：保持工厂及实单待发、无缺货、未释放外采排除，**并不强制要求 `domestic_reviewed` 标记**。这是已冻结的“审核正常存在但物流可直接发货”业务规则，不得引入旧强制审批 Bug。
- `outsource` 是外采独立队列，**未 release 的外采不能出现在其它国内队列**；旧 `view=4px/weship` 会重定向到 `all?filter_carrier=...`。
- 入口 `GET /logistics/china` 的 route 仅有 `web,auth`，但 `DomesticLogisticsController::index` 显式判断 `logistics.view`，外采视图允许 `procurement.view`；ERP2 JSON 适配器必须同样服务端强制校验，不得只依赖前端隐藏。

### 海外

- `OverseaWarehouseController::index()` 读取 **`Shipment::query()`**；筛出在运营中的 Order、有效海外 routing_warehouse_code，或无路由时 Shipment 物理仓；排除 cancelled/historical_logistics。
- `overseasReview()`：`Shipment.pending` 且有仓，审核不存在、非 approved/stockout，或审核所指 `reviewed_warehouse_id` 与当前 shipment.warehouse_id 不一致，都必须在审核队列。
- `overseasReady()`：发货准备状态受实际审核且审核仓匹配影响；`pending` 队列还排除已发货、已出库、部分 OMS 已建单、隔离失败草稿与异常尝试，不能只看 `workflow_status = sending`。
- `issued` 和 `shipped` 分开：US-SM/US-LA 的 OMS 已创建证据与 Shipment 实际 shipped 状态不同；**OMS 草稿不等于出库成功**。
- `us-sm/us-sj/usa-sj` URL 别名在 Controller 中规范为 `us-3sm`。正式仓库实体 code 与前台 slug 要分别处理（US-SM、US-LA 等），保留按仓权限及手工发货方案。
- 原读页的 ShipOut 检查从**本地已记录 attempt/batch/readback 表**读取，不因列表渲染而调用仓库接口；ERP2 同样禁止外部 GET 请求升级为隐式状态修改。

## 3. 建议的 R1/R2 Adapter 数据合同（待开发，不是当前已存在的 API）

**一个只读业务层、三个受控列表入口**：`PendingAllocationReadModel`、`DomesticFulfillmentReadModel`、`OverseasFulfillmentReadModel`。推荐 Laravel 内复用现有 `OrderProgressQuery` 和 Controller 查询/行映射，避免复制 SQL。JSON endpoint 的实际路径、Serializer 以及是否复用现有 Inertia controller，须在下一代码 Work Package 冻结后实现。

共同返回字段（以 canonical 投影为准，非新增数据库列）：`display_order_number`、`order_internal_id`（仅 API identity，不在前台作为业务单号）、`order_kind`、`workflow_status`、`order_status`、`shipment_status`、`routing_warehouse_code`、`physical_warehouse_code`、`review_status`、`reviewed_warehouse_id`、`items[].original_sku`、`items[].warehouse_final_sku`（未映射时为空+明确状态，**不得猜**）、`items[].quantity`、`carrier`、`service`、`tracking_number`、`fulfillment_parent_id`、`fulfillment_suffix`、`permissions`。字段必须按**逐角色脱敏**，不能因为 Dev 使用模拟数据而放宽生产规则。

响应要求：
1. server-side searchable, filterable, sortable, paginated；每类队列与 counts 采用**同一 query 口径**。
2. GET 绝不写库、不生成 OMS/物流草稿、不自动推送 Tracking、不调用真实 Woo/仓库写接口。
3. 服务端认证 + `can:logistics.view` 或 source role 中限定的特殊权限；KOL 无法通过猜测接口 URL 看到无关订单。
4. 响应区分“没有数据”“没有权限”“数据/服务错误”；空列表绝不计为完整功能 PASS。
5. Endpoint 候选路径是建议，**并非已经存在或已验证**；实现阶段必须生成一对一 Controller/Service/schema/test/source proof。

## 4. 最小合成数据 HTTP 断言矩阵（每个场景同时比较列表与 count）

| Fixture | 待分仓 | 国内审核 | 国内待发货 | 海外审核 | 海外待发货 |
|---|---|---|---|---|---|
| 合资格根单，待分仓、无 Shipment | 应出现 | 不出现 | 不出现 | 不出现 | 不出现 |
| 已分 `FACTORY`、Shipment.pending、无审核 | 不出现 | 应出现 | **也可出现**（审核不硬阻挡发货） | 不出现 | 不出现 |
| Factory 已审批、无 Tracking、未发货 | 不出现 | 不出现 | 应出现 | 不出现 | 不出现 |
| Factory 已发货/Shipment.shipped | 不出现 | 不出现 | 不出现 | 不出现 | 不出现 |
| 海外已分仓、无审核 | 不出现 | 不出现 | 不出现 | 应出现 | 不出现 |
| 海外审核 approved 且仓匹配 | 不出现 | 不出现 | 不出现 | 不出现 | 应出现（须再满足其它 readiness） |
| 海外改仓但旧审核仍 approved | 不出现 | 不出现 | 不出现 | **应再次出现** | 不应凭旧审核出现 |
| 海外 OMS draft 未确认 | 不出现 | 不出现 | 不出现 | 按审核态 | 不得误判已完成出库 |
| 已归档/运营失格/取消 | 不出现 | 不出现 | 不出现 | 不出现 | 不出现 |
| KOL 无物流权限请求三入口 | HTTP 403 | HTTP 403 | HTTP 403 | HTTP 403 | HTTP 403 |

**注意：** 测试期望应由具体 Laravel fixture 与当前业务契约双重审核；若原 ERP 有互斥/重叠设计，保留现有语义并显式解释，不因新页面布局而默改状态机。此表是待编写的测试矩阵，不是已执行的结果。

## 5. 证据位置与下一步

- `app/Modules/Logistics/Experience/Routes/web.php`：待分仓、国内、海外 GET 入口。
- `app/Modules/Logistics/Experience/Controllers/LogisticsController.php`：`waitStock`、Inertia 服务器分页与数据映射。
- `app/Modules/Logistics/Application/LogisticsStatusSummary.php`：`pendingAllocationQuery()`。
- `app/Modules/Logistics/Experience/Controllers/DomesticLogisticsController.php`：`baseQuery/applyView/index`。
- `app/Modules/Logistics/Experience/Controllers/OverseaWarehouseController.php`：`Shipment` 根、仓映射、`applyView`，本地 OMS attempts/readback。
- `app/Modules/Orders/Application/OrderProgressQuery.php`：三队列进度谓词。
- `tests/Feature/Logistics/PendingAllocationEntryPolicyTest.php`、`DomesticBusinessReviewTest.php`、`OverseaWarehouseWorkbenchTest.php`：优先回归测试；**文件存在，不代表已运行 PASS**。
- 下一步：审计 Source+ERP2 #766 的字段接线和认证 session 机制 → 独立 R1/R2 后端实施 Work Package → 精确 HEAD PHPUnit/HTTP 负例 → 007windows Dev 前台逐页 UAT。任何新 UI 代码先走 Owner 视觉方案 PASS。Production 不变。


## 6. 已确认的 ERP2 #766 前端/网关集成缺口

只读审计未合并的 `feat/erp-siteops-007-r0-orders-readonly-20261114@299f837a` 后，以下是**现存源文件的事实**：

| 已有 ERP2 源文件 | 当前实现 | R1/R2 必补的缺口 |
|---|---|---|
| `apps/erp-siteops-vben/overrides/src/api/erp-orders.ts` | `getCurrentDevReadOnlyOrders` 仅支持 `scope='all'|'customers'`，GET `/erp/current/orders`；`normalizeExistingInertiaOrders` 只接受 `Orders/Index` | 尚无 `FulfillmentAllocation`、`Logistics/Domestic/Index`、`Logistics/Oversea/Index` 对应投影；原 `items[].sku` 仍按初始 SKU 串联显示，不能称为每仓 final SKU |
| `apps/erp-siteops-vben/runtime/r2b-current-gateway.cjs` | 只向原 Laravel DEV 请求 `/orders` 或 `/customers/orders`，并仅解析 `Orders/Index` | 未连接待分仓、国内/海外真实 Laravel 队列；需要独立受控 read adapter 和角色负例 |
| `apps/erp-siteops-vben/runtime/p0-canonical-proxy.cjs` | `DEV_PATHS` allowlist 仅涵盖登录、dashboard、identity、orders、customers/orders | 没有物流三 GET 入口；不能简单通过前端菜单跳转宣告实际 API 通路 |
| `apps/erp-siteops-vben/overrides/src/api/erp-hot-snapshots.ts` | 内存缓存 TTL 60 秒，版本常量为 `b07885e36c491a997f88ae3c7a6ef0b14d17ad31` | 源代码 `main@eb8ed64e` 已变更；必须先重建 Dev 源版本证据并统一版本 pin、缓存隔离键，不能直接沿用旧快照 |
| `apps/erp-siteops-vben/overrides/src/api/erp-orders.ts` + `runtime/r2b-current-gateway.cjs` | 前后端共同检查 `CURRENT_LARAVEL_MAIN_DEV_B07885E3` 标记 | 来源 pin 是 Dev UAT 安全约束，不可单方面改标签来假装兼容当前 `main`；必须同时更新真实 Laravel Dev checkout、网关和前端测试 |

**结论（静态代码审计）：** #766 是**订单/客户列表阶段的 ERP2 只读实现**，并非本轮 3 个物流业务队列连接已完成；R1/R2 实施须先解决 source-version pin 与三路由投影。未运行 007windows 远端浏览器或 HTTP 实测，不能将此差距当作现场故障结论。
